#!/bin/bash
# One granted deletion attempt; queue state and retry policy stay in PHP.
set -Eeuo pipefail
[[ -n "${ZFSAS_TASK_ID:-}" && -n "${ZFSAS_ATTEMPT_TOKEN:-}" && -n "${ZFSAS_COORDINATOR_GENERATION:-}" ]] || exit 1
# shellcheck source=/dev/null
source /usr/local/sbin/zfs_snapsync_delete_worker
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
# The adapter is only usable with an immutable job file supplied by its grant.
[[ $# == 1 && "$1" == /tmp/zfs-snapsync-coordinator/attempt-inputs/*.job && -f "$1" && ! -L "$1" ]] || exit 1
export ZFSAS_DELETE_APPROVAL="$1.approval.json"
declare -A ATTEMPT_JOB=()
job_load "$1" ATTEMPT_JOB || exit 1
CURRENT_JOB_ID="${ATTEMPT_JOB[JOB_ID]:-}"
[[ "$CURRENT_JOB_ID" =~ ^[a-zA-Z0-9_.:-]+$ ]] || exit 1
[[ -n "${ATTEMPT_JOB[SNAPSHOT_GUID]:-}" && "${ATTEMPT_JOB[SNAPSHOT]:-}" == "${ATTEMPT_JOB[DATASET]:-}@"* ]] || exit 1
is_valid_snapshot_name "${ATTEMPT_JOB[SNAPSHOT]}" || exit 1
OUTCOME=validation_failure
RESULT_STATE=failed
RESULT_REASON=''
RESULT_MESSAGE='Deletion worker did not produce an explicit outcome.'
queue_load_job_assoc() {
  local -n target="$2"
  target=()
  local key
  for key in "${!ATTEMPT_JOB[@]}"; do target[$key]="${ATTEMPT_JOB[$key]}"; done
}
# Compatibility files cannot replace a granted attempt's explicit result.
delete_result_already_committed() { return 1; }
queue_remove_job() { OUTCOME=success; RESULT_STATE=completed; RESULT_MESSAGE='A committed deletion result already exists.'; }
complete_delete_job() { OUTCOME=success; RESULT_STATE=completed; RESULT_MESSAGE="$1"; }
skip_delete_job() { OUTCOME=success; RESULT_STATE=skipped; RESULT_MESSAGE="$1"; }
retry_delete_job() { OUTCOME=wait; RESULT_REASON=resource; RESULT_MESSAGE="$1"; }
destroy_single_snapshot_with_retries() {
  local snapshot="$1" output
  [[ "$(zfs_guid_for_transport "$snapshot" local)" == "${ATTEMPT_JOB[SNAPSHOT_GUID]}" ]] || return 1
  if ! output="$(zfs destroy "$snapshot" 2>&1)"; then
    OUTCOME=transient_failure; RESULT_STATE=failed; RESULT_MESSAGE="$output"
    return 1
  fi
}
execute_remote_snapshot_delete() {
  if ! eval "$1"; then
    OUTCOME=transient_failure; RESULT_STATE=failed
    return 1
  fi
}
# Legacy validation calls fail_delete_job after a failed destroy. Preserve the
# execution classification so only the coordinator chooses retry deadlines.
fail_delete_job() {
  [[ "$OUTCOME" == transient_failure ]] || OUTCOME=validation_failure
  RESULT_STATE=failed; RESULT_MESSAGE="$1"
}
cleanup_attempt() { release_dataset_gates || true; }
trap cleanup_attempt EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
printf '%s\n' '{"phase":"validating","message":"Validating one granted deletion."}' | php "$CLIENT" progress 1 >/dev/null
ensure_runtime_layout
exec 9>"$DELETE_WORKER_RUNTIME_DIR/owner.lock"
if ! flock -n 9; then
  retry_delete_job 'Another deletion executor owns the resource.'
elif ! unraid_array_actionable; then
  OUTCOME=wait; RESULT_REASON=array; RESULT_MESSAGE="$(unraid_array_action_message)"
else
  process_delete_job
fi
php -r '$r=["outcome"=>$argv[1],"itemState"=>$argv[2],"message"=>substr($argv[3],0,4096)];if($argv[1]==="wait"){$r["reason"]=$argv[4];$r["delay"]=1;}echo json_encode($r);' \
  "$OUTCOME" "$RESULT_STATE" "$RESULT_MESSAGE" "$RESULT_REASON" | php "$CLIENT" result 2 >/dev/null
