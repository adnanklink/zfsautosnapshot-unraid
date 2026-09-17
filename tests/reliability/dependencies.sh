#!/bin/bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
source "$root/source/usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"; trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; CONFIG_DIR="$fixture/config"
mkdir -p "$OPS_JOBS_DIR" "$CONFIG_DIR"
ops_apply_owner() { :; }
declare -A first=([JOB_ID]=first [JOB_TYPE]=send [STATE]=queued [SOURCE_ROOT]=source/data [DESTINATION_ROOT]=backup/data [SOURCE_SNAPSHOT]=source/data@selected [SEND_PLAN_SNAPSHOT]=source/data@selected [SEND_PLAN_BASE_SNAPSHOT]=source/data@base [SEND_PLAN_DEST_BASE_SNAPSHOT]=backup/data@base [SEND_PLAN_DEST_SNAPSHOT]=backup/data@selected)
declare -A second=([JOB_ID]=second [JOB_TYPE]=send [STATE]=retry_wait [SOURCE_ROOT]=source/data [DESTINATION_ROOT]=backup/data [SOURCE_SNAPSHOT]=source/data@other [SEND_PLAN_BASE_SNAPSHOT]=source/data@older [SEND_PLAN_DEST_BASE_SNAPSHOT]=backup/data@older)
job_write "$OPS_JOBS_DIR/first.job" first
job_write "$OPS_JOBS_DIR/second.job" second
# Reproduce the circular wait: transfer waits for space, deletion must be admitted
# for old garbage while both queued sends retain their distinct incremental bases.
for snapshot in source/data@selected source/data@base backup/data@base backup/data@older source/data@other; do
  snapshot_delete_conflicts_with_send_jobs "$snapshot"
done
! snapshot_delete_conflicts_with_send_jobs backup/data@garbage
! snapshot_delete_conflicts_with_send_jobs backup/unrelated@old
first[STATE]=running; job_write "$OPS_JOBS_DIR/first.job" first
snapshot_delete_conflicts_with_send_jobs backup/data@garbage
first[STATE]=retry_wait; job_write "$OPS_JOBS_DIR/first.job" first
! snapshot_delete_conflicts_with_send_jobs backup/data@garbage
first[SEND_RESUME_BASE_SNAPSHOT]=source/data@resume
first[SEND_RESUME_DEST_BASE_SNAPSHOT]=backup/data@resume
job_write "$OPS_JOBS_DIR/first.job" first
snapshot_delete_conflicts_with_send_jobs source/data@resume
snapshot_delete_conflicts_with_send_jobs backup/data@resume
first[STATE]=complete; job_write "$OPS_JOBS_DIR/first.job" first
! snapshot_delete_conflicts_with_send_jobs source/data@selected
# The second waiting run retains its independent references.
snapshot_delete_conflicts_with_send_jobs backup/data@older
echo 'PASS: prerequisite cleanup admission, two sends sharing a destination, exact selected/base/resume references, unrelated work and active-tree exclusion'
# Exercise the production admission path with measured space, queued cleanup,
# reservation contention and an exhausted cleanup plan.
SEND_SPACE_RESERVATION_DIR="$fixture/reservations"; mkdir -p "$SEND_SPACE_RESERVATION_DIR"
declare -A admission=([JOB_ID]=space [JOB_TYPE]=send [STATE]=queued [DESTINATION_ROOT]=backup/data [SPACE_REQUIRED_BYTES]=100)
job_write "$OPS_JOBS_DIR/space.job" admission
send_space_reservation_exists_for_job() { return 1; }
nearest_existing_dataset_ancestor_for_transport() { printf backup/data; }
send_space_buffer_bytes() { printf 10; }
acquire_send_space_reservation_for_transport() { printf -v "$7" 10; printf -v "$8" 110; return 1; }
get_pool_freeing_for_transport() { printf '%s' "${freeing:-0}"; }
pool_has_active_delete_jobs() { [[ "${cleanup_queued:-0}" == 1 ]]; }
acquire_pool_prep_lock() { return 0; }
release_pool_prep_lock() { :; }
queue_pool_retention_cleanup() { :; }
queue_pool_free_space_cleanup_for_target() { return 1; }
ensure_delete_worker_for_backlog() { :; }
preserve_failed_send_log_for_job() { :; }
log() { :; }
! approve_send_job_space_for_launch "$OPS_JOBS_DIR/space.job" "$$"
job_load "$OPS_JOBS_DIR/space.job" admission
[[ "${admission[STATE]}" == failed && "${admission[PHASE]}" == insufficient_space && "${admission[LAST_ERROR]}" == *'No eligible cleanup remains'* ]]
admission[STATE]=queued; admission[SPACE_CLEANUP_REQUESTED_AT]=0
job_write "$OPS_JOBS_DIR/space.job" admission
cleanup_queued=1
! approve_send_job_space_for_launch "$OPS_JOBS_DIR/space.job" "$$"
job_load "$OPS_JOBS_DIR/space.job" admission
[[ "${admission[STATE]}" != failed && "${admission[ATTEMPT_COUNT]:-0}" == 0 ]]
cleanup_queued=0; freeing=200
! approve_send_job_space_for_launch "$OPS_JOBS_DIR/space.job" "$$"
job_load "$OPS_JOBS_DIR/space.job" admission
[[ "${admission[STATE]}" != failed && "${admission[ATTEMPT_COUNT]:-0}" == 0 ]]
echo 'PASS: measured shortage fails actionably; cleanup/freeing dependency waits do not consume attempts'
