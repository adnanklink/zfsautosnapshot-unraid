#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; JOB_LOCKS_DIR="$fixture/locks"
mkdir -p "$OPS_JOBS_DIR" "$JOB_LOCKS_DIR"
ops_apply_owner() { :; }
declare -A old=([JOB_ID]=old [JOB_TYPE]=send [JOB_MODE]=scheduled [SEND_TRANSPORT]=local [STATE]=queued)
job_write "$OPS_JOBS_DIR/old.job" old
! send_job_matches_selector old any
old[REVISION]=0; old[JOB_ID]=network; old[SEND_TRANSPORT]=ssh; job_write "$OPS_JOBS_DIR/network.job" old
send_job_matches_selector old any
old[REVISION]=0; old[JOB_ID]=active; old[SEND_TRANSPORT]=local; old[STATE]=running; job_write "$OPS_JOBS_DIR/active.job" old
old[REVISION]=0; old[JOB_ID]=manual; old[STATE]=retry_wait; old[JOB_MODE]=manual_snapshot; job_write "$OPS_JOBS_DIR/manual.job" old
retire_unstarted_local_send_jobs
job_load "$OPS_JOBS_DIR/old.job" old; [[ "${old[STATE]}" == failed && "${old[PHASE]}" == coordinator_cutover ]]
job_load "$OPS_JOBS_DIR/network.job" old; [[ "${old[STATE]}" == queued ]]
job_load "$OPS_JOBS_DIR/active.job" old; [[ "${old[STATE]}" == running ]]
job_load "$OPS_JOBS_DIR/manual.job" old; [[ "${old[STATE]}" == failed && "${old[RECOVERY_REQUIRED]}" == 1 ]]
echo 'PASS: local legacy admission disabled, unstarted work retired, active workers preserved, network jobs retained and interrupted manual authority requires review'
