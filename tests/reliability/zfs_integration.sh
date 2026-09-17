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
# Exercise production intent and GUID publication against actual ZFS metadata.
# Prerequisite readiness is isolated here; this is not the coordinator phase test.
(
  zfs create -o mountpoint=none "$source_pool/intent"
  zfs create -o mountpoint=none "$source_pool/intent/child"
  worker="$ROOT/source/usr/local/sbin/zfs_autosnapshot_send_worker"
  for function in prepare_scheduled_job_snapshot freeze_current_send_manifest; do
    eval "$(sed -n "/^${function}() {/,/^}/p" "$worker")"
  done
  current_send_transport() { printf local; }
  zfs_dataset_tree_actionable() { :; }; send_destination_actionable_for_transport() { :; }
  spiped_transport_requires_receiver_inventory() { return 1; }
  latest_checkpoint_basename_for_schedule() { :; }
  fail_current_job_final() { printf '%s\n' "$1" >&2; return 1; }
  fail_current_job() { fail_current_job_final "$@"; }
  persist_job() { declare -p intent_job > "$fixture/intent-record"; }
  declare -A intent_job=([JOB_ID]=real-intent [JOB_MODE]=scheduled [SOURCE_ROOT]="$source_pool/intent"
    [DESTINATION_ROOT]="$target_pool/intent" [INCLUDE_CHILDREN]=1 [SNAPSHOT_PREFIX]=send-
    [SEND_CONFIG_HASH]=fixture [SEND_TRANSPORT]=local)
  declare -n job=intent_job
  prepare_scheduled_job_snapshot
  send_member_manifest_valid job
  [[ "${job[MEMBER_COUNT]}" == 2 && "${job[RECOVERY_REQUIRED]}" == 0 ]]
  for index in 0 1; do
    [[ "$(zfs get -H -p -o value guid "${job[MEMBER_${index}_SNAPSHOT]}")" == "${job[MEMBER_${index}_SNAPSHOT_GUID]}" ]]
  done
  zfs create -o mountpoint=none "$source_pool/intent/later"
  prepare_scheduled_job_snapshot
  [[ "${job[MEMBER_COUNT]}" == 2 ]]
  ! zfs list -H "$source_pool/intent/later@${job[SOURCE_SNAPSHOT_NAME]}" >/dev/null 2>&1
)
echo 'PASS: real ZFS creation intent, exact recursive targets, GUID manifest and fixed membership'
printf 'base content\n' > "$fixture/source/base.txt"
zfs snapshot "$source_dataset@base"
declare -A job=([SEND_TRANSPORT]=local)
SEND_RATE_LIMIT=0
run_pipeline_with_status 'Real full transfer' '' "$source_dataset@base" "$destination"
snapshots_have_same_guid "$source_dataset@base" "$destination@base" local
printf 'incremental content\n' > "$fixture/source/incremental.txt"
zfs snapshot "$source_dataset@next"
php -r '
require $argv[1];
$result=ZfsasReplicationInspection::inspect(["sourceSnapshot"=>$argv[2]."@next","sourceGuid"=>$argv[4],"destination"=>$argv[3]]);
if ($result["outcome"]!=="success" || $result["inspection"]["base"]["snapshot"]!==$argv[2]."@base"
    || count($result["inspection"]["references"])!==3) { fwrite(STDERR,json_encode($result)); exit(1); }
echo "PASS: native read-only inspection selects GUID-matched base on real ZFS\n";
' "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/php/replication-inspection.php" "$source_dataset" "$destination" "$(zfs get -H -p -o value guid "$source_dataset@next")"
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
# A queued transfer must allow prerequisite cleanup while retaining its exact
# incremental base. Use a dataset quota for a small deterministic shortage.
cleanup_source="$source_pool/cleanup"
cleanup_destination="$target_pool/cleanup"
zfs create -o mountpoint="$fixture/cleanup-source" "$cleanup_source"
dd if=/dev/urandom of="$fixture/cleanup-source/obsolete.bin" bs=1M count=8 status=none
zfs snapshot "$cleanup_source@obsolete"
run_pipeline_with_status 'Cleanup fixture full transfer' '' "$cleanup_source@obsolete" "$cleanup_destination"
rm "$fixture/cleanup-source/obsolete.bin"
printf 'protected base content\n' > "$fixture/cleanup-source/base.txt"
zfs snapshot "$cleanup_source@protected-base"
run_pipeline_with_status 'Cleanup fixture base transfer' "$cleanup_source@obsolete" "$cleanup_source@protected-base" "$cleanup_destination"
zfs set quota=20M "$cleanup_destination"
dd if=/dev/urandom of="$fixture/cleanup-source/next.bin" bs=1M count=14 status=none
zfs snapshot "$cleanup_source@next"
zpool sync "$target_pool"
available_before="$(zfs get -H -p -o value available "$cleanup_destination")"
(( available_before < 14 * 1024 * 1024 ))
declare -A waiting=([JOB_ID]=cleanup-wait [JOB_TYPE]=send [JOB_MODE]=manual_snapshot [STATE]=retry_wait
  [SOURCE_ROOT]="$cleanup_source" [DESTINATION_ROOT]="$cleanup_destination" [SOURCE_SNAPSHOT]="$cleanup_source@next"
  [SEND_PLAN_BASE_SNAPSHOT]="$cleanup_source@protected-base" [SEND_PLAN_DEST_BASE_SNAPSHOT]="$cleanup_destination@protected-base")
job_write "$OPS_JOBS_DIR/cleanup-wait.job" waiting
snapshot_delete_conflicts_with_send_jobs "$cleanup_source@protected-base"
snapshot_delete_conflicts_with_send_jobs "$cleanup_destination@protected-base"
! snapshot_delete_conflicts_with_send_jobs "$cleanup_destination@obsolete"
# Exercise the production exclusion predicate immediately before exact cleanup.
if ! snapshot_delete_conflicts_with_send_jobs "$cleanup_destination@obsolete"; then zfs destroy "$cleanup_destination@obsolete"; fi
zpool sync "$target_pool"
available_after="$(zfs get -H -p -o value available "$cleanup_destination")"
(( available_after > available_before && available_after > 14 * 1024 * 1024 ))
run_pipeline_with_status 'Transfer unblocked by prerequisite cleanup' "$cleanup_source@protected-base" "$cleanup_source@next" "$cleanup_destination"
snapshots_have_same_guid "$cleanup_source@next" "$cleanup_destination@next" local
zfs list -H -t snapshot "$cleanup_destination@protected-base" >/dev/null
zfs list -H -t snapshot "$target_pool/unrelated@base" >/dev/null
echo 'PASS: real quota shortage, prerequisite cleanup with queued transfer, protected base and unrelated snapshot preservation'
echo 'PASS: real ZFS full/incremental transfers, destination preservation, run cancellation, full pipeline shutdown, persistent pause, wrong-token rejection, explicit resume and byte comparison'
