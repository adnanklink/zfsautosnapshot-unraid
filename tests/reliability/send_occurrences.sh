#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_STATUS_DIR="$OPS_ROOT/status"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; CONFIG_DIR="$fixture/config"
mkdir -p "$OPS_STATUS_DIR" "$OPS_JOBS_DIR" "$CONFIG_DIR"
ops_apply_owner() { :; }
schedule=abcdef123456
declare -A failed=([JOB_ID]=failed-run [JOB_TYPE]=send [JOB_MODE]=scheduled [SCHEDULE_JOB_ID]="$schedule" [STATE]=failed [WINDOW_KEY]=100)
job_write "$OPS_JOBS_DIR/failed.job" failed
! schedule_job_blocked "$schedule"
schedule_window_exists "$schedule" 100
record_accepted_schedule_window "$schedule" 100
# Runtime history clearing does not restore execution authority for this window.
rm "$OPS_JOBS_DIR/failed.job"
schedule_window_exists "$schedule" 100
schedule_window_exists "$schedule" 99
! schedule_window_exists "$schedule" 200
failed[REVISION]=0; failed[STATE]=running; job_write "$OPS_JOBS_DIR/running.job" failed
schedule_job_blocked "$schedule"
failed[STATE]=canceling; job_write "$OPS_JOBS_DIR/running.job" failed
schedule_job_blocked "$schedule"
failed[STATE]=failed; job_write "$OPS_JOBS_DIR/running.job" failed
! schedule_job_blocked "$schedule"
mkdir -p "$CONFIG_DIR/send-control/paused"; touch "$CONFIG_DIR/send-control/paused/$schedule"
schedule_job_blocked "$schedule"
echo 'PASS: exhausted send occurrence stays accepted after history clearing; next occurrence is allowed; active runs and persistent pauses block admission'
