#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; CONFIG_DIR="$fixture/config"
mkdir -p "$OPS_JOBS_DIR" "$CONFIG_DIR/send-control/cancelled" "$CONFIG_DIR/send-control/paused"
ops_apply_owner() { :; }
declare -A job=([JOB_ID]=run1 [JOB_TYPE]=send [STATE]=running [PHASE]=sending)
path="$OPS_JOBS_DIR/1.job"
job_write "$path" job
declare -A old=() disk=()
job_load "$path" old
job[STATE]=complete
job_write "$path" job
if job_write "$path" old; then echo 'stale publication accepted'; exit 1; fi
job_load "$path" disk
[[ "${disk[STATE]}" == complete ]]
touch "$CONFIG_DIR/send-control/cancelled/run1"
job[STATE]=running
if job_write "$path" job; then echo 'canceled worker publication accepted'; exit 1; fi
declare -A child=([JOB_ID]=child1 [PARENT_RUN_ID]=run1 [JOB_TYPE]=send [STATE]=queued)
if job_write "$OPS_JOBS_DIR/2.job" child; then echo 'late child publication accepted'; exit 1; fi
[[ ! -e "$OPS_JOBS_DIR/2.job" ]]
touch "$CONFIG_DIR/send-control/paused/schedule1"
schedule_job_blocked schedule1
# Real isolated process group: descendants must stop even when the leader crashes.
setsid bash -c 'sleep 120 & wait' &
group=$!
sleep 0.1
start="$(process_start_time "$group")"
kill -KILL "$group"
wait "$group" 2>/dev/null || true
stop_send_process_group "$group" "$start"
[[ -z "$(send_group_members "$group")" ]]
echo 'PASS: stale workers, canceled fan-out, persistent pause, orphan pipeline shutdown'
