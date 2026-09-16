#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
OPS_ROOT="$fixture/ops"; OPS_JOBS_DIR="$OPS_ROOT/jobs"; CONFIG_DIR="$fixture/config"; JOB_LOCKS_DIR="$fixture/claims"
SEND_SPACE_RESERVATION_DIR="$fixture/reservations"; SEND_WORKER_RUNTIME_DIR="$fixture/workers"
mkdir -p "$OPS_JOBS_DIR" "$CONFIG_DIR" "$SEND_SPACE_RESERVATION_DIR" "$SEND_WORKER_RUNTIME_DIR"
ops_apply_owner() { :; }
# Readers cannot remove a stale claim; only the serialized owner can replace it.
mkdir -p "$JOB_LOCKS_DIR/job-stale.lockdir"
printf '99999999\n' > "$JOB_LOCKS_DIR/job-stale.lockdir/pid"
! job_claim_active stale
[[ -d "$JOB_LOCKS_DIR/job-stale.lockdir" ]]
acquire_job_claim stale
! acquire_job_claim stale
foreign_release="$fixture/foreign-release.sh"
printf 'source %q\nJOB_LOCKS_DIR=%q\nrelease_job_claim stale\n' "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh" "$JOB_LOCKS_DIR" > "$foreign_release"
bash "$foreign_release"
job_claim_active stale
release_job_claim stale
[[ ! -d "$JOB_LOCKS_DIR/job-stale.lockdir" ]]
# Child evidence survives pruning until explicit finalizer success.
declare -A child=([JOB_ID]=send-child-run-0 [JOB_TYPE]=send [JOB_ACTION]=send_member [STATE]=complete [PARENT_RUN_ID]=run [PURGE_AFTER_EPOCH]=1)
declare -A final=([JOB_ID]=finalize-run [JOB_TYPE]=send [JOB_ACTION]=finalize [STATE]=queued [PARENT_RUN_ID]=run [EXPECTED_CHILD_COUNT]=1)
job_write "$OPS_JOBS_DIR/child.job" child; job_write "$OPS_JOBS_DIR/final.job" final
prune_old_jobs
[[ -f "$OPS_JOBS_DIR/child.job" ]]
# Exercise actual finalizer, with deterministic terminal side effects.
eval "$(sed -n '/^process_finalize_job() {/,/^}/p' "$ROOT/source/usr/local/sbin/zfs_autosnapshot_send_worker")"
complete_job() { result=complete; }; fail_current_job_final() { result=failed; }; defer_current_job() { result=deferred; }
declare -A job=(); for key in "${!final[@]}"; do job[$key]="${final[$key]}"; done
result=''; process_finalize_job; [[ "$result" == complete ]]
job[EXPECTED_CHILD_COUNT]=2; result=''; ! process_finalize_job; [[ "$result" == failed ]]
job[EXPECTED_CHILD_COUNT]=1; child[STATE]=running; job_write "$OPS_JOBS_DIR/child.job" child
result=''; process_finalize_job || [[ $? == 2 ]]; [[ "$result" == deferred ]]
child[STATE]=skipped; job_write "$OPS_JOBS_DIR/child.job" child
result=''; ! process_finalize_job; [[ "$result" == failed ]]
# Genuine crash: keep reservations for surviving children, stop them, then retry.
setsid bash -c 'sleep 120 & wait' &
pid=$!; sleep 0.1; start="$(process_start_time "$pid")"
kill -KILL "$pid"; wait "$pid" 2>/dev/null || true
printf 'PID="%s"\nJOB_ID="crash"\n' "$pid" > "$SEND_SPACE_RESERVATION_DIR/crash.reservation"
cleanup_stale_send_space_reservations_locked
[[ -f "$SEND_SPACE_RESERVATION_DIR/crash.reservation" ]]
declare -A crash=([JOB_ID]=crash [JOB_TYPE]=send [STATE]=running [PHASE]=sending [WORKER_PID]="$pid" [WORKER_PGID]="$pid" [WORKER_START]="$start")
job_write "$OPS_JOBS_DIR/crash.job" crash
reconcile_stale_jobs
job_load "$OPS_JOBS_DIR/crash.job" crash
[[ "${crash[STATE]}" == queued && -z "$(send_group_members "$pid")" ]]
cleanup_stale_send_space_reservations_locked
[[ ! -f "$SEND_SPACE_RESERVATION_DIR/crash.reservation" ]]
# Migrator lock loser must leave status, folders, containers, and log untouched.
export ZFSAS_MIGRATOR_PLUGIN_ROOT="$fixture/migrator" ZFSAS_MIGRATOR_LOCK_FILE="$fixture/migrator.lock" ZFSAS_MIGRATOR_LOG_FILE="$fixture/migrate.log"
mkdir -p "$ZFSAS_MIGRATOR_PLUGIN_ROOT"
for file in status.env folders.tsv containers.tsv; do printf 'active owner\n' > "$ZFSAS_MIGRATOR_PLUGIN_ROOT/$file"; done
printf 'active log\n' > "$ZFSAS_MIGRATOR_LOG_FILE"
exec 9>"$ZFSAS_MIGRATOR_LOCK_FILE"; flock 9
! bash "$ROOT/source/usr/local/sbin/zfs_autosnapshot_migrate_datasets" --dataset tank/data > "$fixture/loser.log" 2>&1
for file in status.env folders.tsv containers.tsv; do [[ "$(cat "$ZFSAS_MIGRATOR_PLUGIN_ROOT/$file")" == 'active owner' ]]; done
[[ "$(cat "$ZFSAS_MIGRATOR_LOG_FILE")" == 'active log' ]]
flock -u 9; exec 9>&-
# Migration and recovery share the exact gate namespace with other workers.
export ZFSAS_OPS_ROOT="$OPS_ROOT"
eval "$(sed -n '/^acquire_migration_gates() {/,/^}/p' "$ROOT/source/usr/local/sbin/zfs_autosnapshot_migrate_datasets")"
ensure_dir() { mkdir -p "$1"; }; apply_owner() { :; }
ACTIVE_DATASET=tank/data/child
acquire_dataset_gates -s tank/data
# A separate process closes any partially acquired descriptors on failure.
! (acquire_migration_gates)
release_dataset_gates
(
  acquire_migration_gates
  ! acquire_dataset_gates -s tank/data
  acquire_dataset_gates -s other/data
  release_dataset_gates
)
acquire_dataset_gates -s tank/data
release_dataset_gates
# Detached children cannot retain the parent's dataset/owner flock.
exec 8>"$fixture/detach.lock"; flock 8
"$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/detach-worker.sh" sleep 10 &
detached=$!; sleep .1
exec 8>&-
flock -n "$fixture/detach.lock" true
kill "$detached"; wait "$detached" 2>/dev/null || true
# Installer signals only a matching worker identity and verifies all children stop.
source "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/worker-shutdown-lib.sh"
collect_pid_tree() { local child; while read -r child; do collect_pid_tree "$child"; done < <(pgrep -P "$1" || true); printf '%s\n' "$1"; }
sleep 20 & unrelated=$!
RUN_MATCH=zfsas-fixture-worker
stop_pid_tree "$unrelated"; kill -0 "$unrelated"
setsid bash -c 'sleep 20 & wait' zfsas-fixture-worker & owned=$!
sleep .1
stop_pid_tree "$owned"
wait "$owned" 2>/dev/null || true
[[ -z "$(send_group_members "$owned")" ]]
kill "$unrelated"; wait "$unrelated" 2>/dev/null || true
echo 'PASS: claim ownership, finalizer evidence and explicit child outcomes, orphan reservation recovery, migration lock loser'
