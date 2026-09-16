#!/bin/bash
# ONLY run in a disposable container with /dev/zfs and mount capability.
# Two uniquely named, file-backed pools are created; no existing pool is touched.
set -euo pipefail
[[ -f /.dockerenv && "${ZFSAS_DISPOSABLE_POOL_TEST:-}" == 1 ]] || { echo 'Requires the explicitly enabled disposable container test.' >&2; exit 77; }
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d "${ZFSAS_POOL_FIXTURE_ROOT:-/tmp}/zfsas-real.XXXXXXXX")"
nonce="$(basename "$fixture" | tr -cd 'a-zA-Z0-9')"
source_pool="zfsas_test_${nonce}_src"; target_pool="zfsas_test_${nonce}_dst"
source_guid=''; target_guid=''; pipeline_group=''; pipeline_start=''
cleanup() {
  local rc=$?
  trap - EXIT INT TERM
  if [[ -n "$pipeline_group" ]]; then stop_send_process_group "$pipeline_group" "$pipeline_start" || rc=1; fi
  for pool in "$source_pool" "$target_pool"; do
    expected="$source_guid"; [[ "$pool" != "$target_pool" ]] || expected="$target_guid"
    if [[ -n "$expected" && "$(zpool get -H -o value guid "$pool" 2>/dev/null || true)" == "$expected" ]]; then
      zpool destroy "$pool" || rc=1
    fi
  done
  if (( rc == 0 )); then rm -rf "$fixture"; else echo "Integration test failed; fixture path: $fixture" >&2; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
truncate -s 512M "$fixture/source.vdev" "$fixture/target.vdev"
zpool create -o cachefile=none -O mountpoint=none "$source_pool" "$fixture/source.vdev"
source_guid="$(zpool get -H -o value guid "$source_pool")"
zpool create -o cachefile=none -O mountpoint=none "$target_pool" "$fixture/target.vdev"
target_guid="$(zpool get -H -o value guid "$target_pool")"
source_dataset="$source_pool/data"; destination="$target_pool/data"
zfs create -o mountpoint="$fixture/source" "$source_dataset"
printf 'base content\n' > "$fixture/source/base.txt"
zfs snapshot "$source_dataset@base"
declare -A job=([SEND_TRANSPORT]=local)
SEND_RATE_LIMIT=0
run_pipeline_with_status 'Real full transfer' '' "$source_dataset@base" "$destination"
snapshots_have_same_guid "$source_dataset@base" "$destination@base" local
printf 'incremental content\n' > "$fixture/source/incremental.txt"
zfs snapshot "$source_dataset@next"
run_pipeline_with_status 'Real incremental transfer' "$source_dataset@base" "$source_dataset@next" "$destination"
snapshots_have_same_guid "$source_dataset@next" "$destination@next" local
# Existing unrelated destination must survive both a full receive and a mismatched base.
zfs create -o mountpoint="$fixture/unrelated" "$target_pool/unrelated"
printf 'must survive\n' > "$fixture/unrelated/precious.txt"
zfs snapshot "$target_pool/unrelated@base"
if run_pipeline_with_status 'Reject unrelated full destination' '' "$source_dataset@next" "$target_pool/unrelated"; then exit 1; fi
if run_pipeline_with_status 'Reject unrelated incremental base' "$source_dataset@base" "$source_dataset@next" "$target_pool/unrelated"; then exit 1; fi
[[ "$(cat "$fixture/unrelated/precious.txt")" == 'must survive' ]]
# Cancel a real, throttled receive through the production PHP run-cancel service.
dd if=/dev/urandom of="$fixture/source/bulk.bin" bs=1M count=32 status=none
zfs snapshot "$source_dataset@large"
cat > "$fixture/throttle.py" <<'PY'
import sys,time
while True:
 data=sys.stdin.buffer.read(65536)
 if not data: break
 sys.stdout.buffer.write(data);sys.stdout.buffer.flush();time.sleep(.025)
PY
cat > "$fixture/transfer.sh" <<'BASH'
#!/bin/bash
set -euo pipefail
source "$1/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
ensure_runtime_layout
mkdir -p "$CONFIG_DIR"
declare -A job=([JOB_ID]=integration-run [JOB_TYPE]=send [JOB_MODE]=scheduled [JOB_ACTION]=send_member [STATE]=running [PHASE]=sending [SCHEDULE_JOB_ID]=fixture [SOURCE_ROOT]="$2" [DESTINATION_ROOT]="$3" [WORKER_PID]="$$" [WORKER_PGID]="$$" [WORKER_START]="$(process_start_time $$)" [SEND_TRANSPORT]=local)
job_write "$OPS_JOBS_DIR/integration.job" job
# The helper's arguments are local, so retain the throttle path outside it.
THROTTLE="$4"
build_send_rate_limiter_command() { printf -v "$1" '%s' "python3 $THROTTLE"; }
run_pipeline_with_status 'Cancelable integration transfer' '' "$2@large" "$3"
BASH
setsid bash "$fixture/transfer.sh" "$ROOT" "$source_dataset" "$target_pool/resumable" "$fixture/throttle.py" > "$fixture/transfer.log" 2>&1 &
pipeline_group=$!
for ((i=0;i<100;i++)); do [[ -f "$OPS_JOBS_DIR/integration.job" ]] && break; sleep .05; done
pipeline_start="$(process_start_time "$pipeline_group")"
sleep 2
php -r 'require $argv[1]; if (!zfsas_ops_cancel_send_job("integration-run", $error)) { fwrite(STDERR,$error); exit(1); }' "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/php/send-queue-helpers.php"
wait "$pipeline_group" 2>/dev/null || true
[[ -z "$(send_group_members "$pipeline_group")" ]]
[[ -f "$CONFIG_DIR/send-control/cancelled/integration-run" && -f "$CONFIG_DIR/send-control/paused/fixture" ]]
declare -A canceled=();job_load "$OPS_JOBS_DIR/integration.job" canceled
[[ "${canceled[PHASE]}" == canceled ]]
token='';local_receive_resume_token "$target_pool/resumable" token
[[ -n "$token" ]]
if run_pipeline_with_status 'Reject wrong resume target' '' "$source_dataset@base" "$target_pool/resumable"; then exit 1; fi
php -r 'require $argv[1]; if (!zfsas_ops_resume_schedule("fixture",$error)) { fwrite(STDERR,$error); exit(1); }' "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/php/send-queue-helpers.php"
[[ ! -e "$CONFIG_DIR/send-control/paused/fixture" && -f "$CONFIG_DIR/send-control/cancelled/integration-run" ]]
run_pipeline_with_status 'Resume real canceled receive' '' "$source_dataset@large" "$target_pool/resumable"
snapshots_have_same_guid "$source_dataset@large" "$target_pool/resumable@large" local
[[ "$(zfs get -H -o value receive_resume_token "$target_pool/resumable")" == '-' ]]
zfs set mountpoint="$fixture/resumed" "$target_pool/resumable"
zfs mount "$target_pool/resumable" 2>/dev/null || true
cmp "$fixture/source/bulk.bin" "$fixture/resumed/bulk.bin"
echo 'PASS: real ZFS full/incremental transfers, destination preservation, run cancellation, full pipeline shutdown, persistent pause, wrong-token rejection, explicit resume and byte comparison'
