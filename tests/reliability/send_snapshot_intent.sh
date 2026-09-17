#!/bin/bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
source "$root/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"; trap 'rm -rf "$fixture"' EXIT
worker="$root/source/usr/local/sbin/zfs_autosnapshot_send_worker"
for function in prepare_scheduled_job_snapshot freeze_current_send_manifest; do
  eval "$(sed -n "/^${function}() {/,/^}/p" "$worker")"
done
log() { :; }; current_send_transport() { printf local; }
zfs_dataset_tree_actionable() { :; }; send_destination_actionable_for_transport() { :; }
spiped_transport_requires_receiver_inventory() { return 1; }
latest_checkpoint_basename_for_schedule() { :; }
fail_current_job_final() { failure="$1"; }; fail_current_job() { failure="$1"; }
defer_current_job() { return 1; }
list_tree_datasets() { printf 'tank/data\ntank/data/child\n'; }
zfs_guid_for_transport() {
  case "$1" in
    tank/data) printf '%s' "${dataset_guid:-10}";; tank/data/child) printf 20;;
    tank/data@*) printf 11;; tank/data/child@*) printf 21;; *) return 1;;
  esac
}
snapshot_exists() { [[ -f "$fixture/created" ]]; }
persist_job() { declare -p job > "$fixture/record"; }
zfs() {
  # The actual create must observe a published intent containing the whole tree.
  [[ -s "$fixture/record" ]] && grep -q SNAPSHOT_INTENT_HASH "$fixture/record"
  [[ "$1" == snapshot && $# == 3 && "$2" == tank/data@* && "$3" == tank/data/child@* ]]
  printf '%s\n' "$@" > "$fixture/command"
  touch "$fixture/created"
  [[ "${crash:-0}" != 1 ]] || exit 42
}
reset_job() {
  job=([JOB_ID]=intent-run [JOB_TYPE]=send [JOB_MODE]=scheduled [SOURCE_ROOT]=tank/data
    [DESTINATION_ROOT]=backup/data [INCLUDE_CHILDREN]=1 [SNAPSHOT_PREFIX]=send- [SEND_CONFIG_HASH]=config [SEND_TRANSPORT]=local)
}
declare -A job=()
# No mutation is authorized if publication fails, even when errexit is suppressed.
reset_job
persist_job() { return 1; }
! prepare_scheduled_job_snapshot
[[ ! -f "$fixture/created" ]]
persist_job() { declare -p job > "$fixture/record"; }
reset_job
# Crash after the ZFS side effect but before any GUID result is published.
crash=1
(prepare_scheduled_job_snapshot) && exit 1
[[ -f "$fixture/created" ]]
source "$fixture/record"
[[ -z "${job[SOURCE_SNAPSHOT]:-}" && -n "${job[SNAPSHOT_INTENT_HASH]}" ]]
cp "$fixture/command" "$fixture/first-command"
crash=0; failure=''
! prepare_scheduled_job_snapshot
[[ "$failure" == *'interrupted before GUID evidence'* && "${job[RECOVERY_REQUIRED]}" == 1 ]]
cmp "$fixture/command" "$fixture/first-command"
# Failed ambiguous intent keeps exact selected snapshots protected for review.
OPS_JOBS_DIR="$fixture/jobs"; mkdir -p "$OPS_JOBS_DIR"
ops_apply_owner() { :; }
job[STATE]=failed
job_write "$OPS_JOBS_DIR/intent.job" job
snapshot_delete_conflicts_with_send_jobs "${job[MEMBER_1_SNAPSHOT]}"
! snapshot_delete_conflicts_with_send_jobs tank/data/child@unrelated
rm "$OPS_JOBS_DIR/intent.job"
! snapshot_delete_conflicts_with_send_jobs "${job[MEMBER_1_SNAPSHOT]}"

# Changed dataset identity fails without a second mutation.
rm "$fixture/created"
dataset_guid=999; failure=''
! prepare_scheduled_job_snapshot
[[ "$failure" == *'identity changed'* && ! -f "$fixture/created" ]]
# Successful creation freezes both snapshot and dataset GUIDs before fan-out.
dataset_guid=10
reset_job
prepare_scheduled_job_snapshot
send_member_manifest_valid job
[[ "${job[MEMBER_1_SNAPSHOT_GUID]}" == 21 && "${job[MEMBER_1_DATASET_GUID]}" == 20 ]]
# No recursive re-enumeration or replacement creation after evidence is committed.
list_tree_datasets() { return 1; }; zfs() { exit 99; }
prepare_scheduled_job_snapshot
echo 'PASS: intent before creation, exact recursive targets, crash ambiguity requires review, dataset replacement rejection, GUID-bound success'
