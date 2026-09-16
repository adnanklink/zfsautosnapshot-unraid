#!/bin/bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
source "$root/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"; trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; CONFIG_DIR="$fixture/config"
mkdir -p "$OPS_JOBS_DIR" "$CONFIG_DIR"
ops_apply_owner() { :; }; log() { :; }; send_config_hash() { printf config; }
worker="$root/source/usr/local/sbin/zfs_autosnapshot_send_worker"
for function in schedule_run_base_sort send_member_sort_key finalize_sort_key load_existing_send_job_id_cache write_send_job_file ensure_send_job_file freeze_current_send_manifest prepare_scheduled_job_snapshot build_resume_members_for_current_job queue_child_send_jobs process_finalize_job; do
  eval "$(sed -n "/^${function}() {/,/^}/p" "$worker")"
done
SEND_CHILD_JOB_WRITE_KEYS=(); SEND_FINALIZE_JOB_WRITE_KEYS=()
SEND_JOB_ID_CACHE_LOADED=0; declare -A SEND_JOB_ID_PATH_CACHE=()
declare -A job=([JOB_ID]=run [JOB_TYPE]=send [JOB_MODE]=scheduled [JOB_ACTION]=prepare [STATE]=running
  [SOURCE_ROOT]=tank/source [DESTINATION_ROOT]=backup/target [SOURCE_SNAPSHOT_NAME]=checkpoint
  [SEND_TRANSPORT]=local [SEND_CONFIG_HASH]=config [SCHEDULE_JOB_ID]=schedule [WINDOW_KEY]=100
  [REQUESTED_EPOCH]=100 [MEMBER_COUNT]=2 [MEMBER_0_SOURCE]=tank/source [MEMBER_0_DESTINATION]=backup/target
  [MEMBER_0_SNAPSHOT]=tank/source@checkpoint [MEMBER_1_SOURCE]=tank/source/child
  [MEMBER_1_DESTINATION]=backup/target/child [MEMBER_1_SNAPSHOT]=tank/source/child@checkpoint)
CURRENT_JOB_PATH="$OPS_JOBS_DIR/root.job"
persist_job() { job_write "$CURRENT_JOB_PATH" job; }
fail_current_job_final() { failure="$1"; }
complete_job() { result=complete; }; defer_current_job() { result=waiting; }
zfs_guid_for_transport() {
  printf '%s\n' "$1" >> "$fixture/guid-reads"
  case "$1" in
    tank/source) printf 10 ;; tank/source@checkpoint) printf 11 ;;
    tank/source/child) printf 20 ;; tank/source/child@checkpoint) printf 21 ;;
    *) return 1 ;;
  esac
}
persist_job
# Interrupt publication after the first child. The entire manifest must already
# be durable in RAM, before any child can begin transferring.
eval "$(declare -f ensure_send_job_file | sed '1s/ensure_send_job_file/real_ensure_send_job_file/')"
publications=0
ensure_send_job_file() {
  publications=$((publications + 1))
  (( publications != 2 )) || return 1
  real_ensure_send_job_file "$@"
}
! queue_child_send_jobs
job_load "$CURRENT_JOB_PATH" job
send_member_manifest_valid job
[[ "${job[MEMBER_1_SNAPSHOT_GUID]}" == 21 ]]
first="$OPS_JOBS_DIR/0000000100-send-child-run-0.job"
second="$OPS_JOBS_DIR/0000000100-send-child-run-1.job"
final="$OPS_JOBS_DIR/0000000100-finalize-run.job"
[[ -f "$first" && ! -f "$second" && ! -f "$final" ]]
declare -A child=(); job_load "$first" child; child[STATE]=complete; job_write "$first" child
cp "$first" "$fixture/success-evidence"
job[STATE]=retry_wait; persist_job
snapshot_delete_conflicts_with_send_jobs tank/source/child@checkpoint
! snapshot_delete_conflicts_with_send_jobs tank/source/child@unrelated
# Recovery must use frozen membership even if a new child would sort before
# existing members, and must not rediscover/rebind snapshot GUIDs.
list_tree_datasets() { printf 'tank/source\ntank/source/added\ntank/source/child\n'; printf scan >> "$fixture/unexpected-scan"; }
zfs_guid_for_transport() { printf probe >> "$fixture/unexpected-scan"; return 1; }
prepare_scheduled_job_snapshot
build_resume_members_for_current_job
ensure_send_job_file() { real_ensure_send_job_file "$@"; }
SEND_JOB_ID_CACHE_LOADED=0
queue_child_send_jobs
[[ ! -f "$fixture/unexpected-scan" ]]
cmp "$first" "$fixture/success-evidence"
job_load "$second" child
[[ "${child[SOURCE_ROOT]}" == tank/source/child && "${child[SOURCE_SNAPSHOT_GUID]}" == 21 ]]
child[STATE]=complete; job_write "$second" child
# Replay cannot overwrite completed transfers; corrupt membership fails before
# another child or finalizer is admitted.
job[MEMBER_1_SOURCE]=tank/source/added
failure=''; ! queue_child_send_jobs
[[ "$failure" == *'Cannot freeze transfer identities'* ]]
job_load "$CURRENT_JOB_PATH" job
job_load "$second" child; child[SOURCE_SNAPSHOT_GUID]=999; job_write "$second" child
failure=''; ! queue_child_send_jobs
[[ "$failure" == *'identity does not match'* ]]
# A terminal success for the wrong identity is not completion evidence.
job_load "$final" job
failure=''; ! process_finalize_job
[[ "$failure" == *'identity differs'* ]]
child[SOURCE_SNAPSHOT_GUID]=21; job_write "$second" child
result=''; process_finalize_job; [[ "$result" == complete ]]
child[STATE]=skipped; job_write "$second" child
failure=''; ! process_finalize_job; [[ "$failure" == *'did not succeed'* ]]
child[STATE]=running; job_write "$second" child
result=''; process_finalize_job || [[ $? == 2 ]]; [[ "$result" == waiting ]]
unset 'job[MEMBER_MANIFEST_HASH]'
failure=''; ! process_finalize_job; [[ "$failure" == *'Missing expected child manifest'* ]]
echo 'PASS: frozen fan-out before child execution, partial publication recovery, fixed recursive membership, no repeated successful transfers, GUID-bound finalization and legacy evidence rejection'
# Resume-only runs with no missing members retain explicit zero-child authority.
job=([JOB_ID]=resume-done [JOB_TYPE]=send [JOB_MODE]=scheduled [JOB_ACTION]=prepare [STATE]=running
  [SOURCE_ROOT]=tank/source [DESTINATION_ROOT]=backup/target [SEND_CONFIG_HASH]=config
  [SEND_TRANSPORT]=local [REQUESTED_EPOCH]=101 [RESUME_ONLY]=1 [MEMBER_COUNT]=0)
CURRENT_JOB_PATH="$OPS_JOBS_DIR/resume-done.job"
persist_job
queue_child_send_jobs
job_load "$OPS_JOBS_DIR/0000000101-finalize-resume-done.job" job
result=''; process_finalize_job; [[ "$result" == complete ]]
echo 'PASS: explicit zero-child resume finalization'
