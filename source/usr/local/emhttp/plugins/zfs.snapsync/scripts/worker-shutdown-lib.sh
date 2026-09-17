#!/bin/bash
# Install/remove shutdown: bind every signal to a process start time.
zfsas_shutdown_identity() {
  local stat
  stat="$(cat "/proc/$1/stat" 2>/dev/null)" || return 1
  stat="${stat##*) }"
  [[ "${stat%% *}" != Z ]] || return 1
  awk '{print $20}' <<< "$stat"
}
stop_pid_tree() {
  local root_pid="$1" pid identity round alive
  local -A identities=()
  [[ "$(ps -p "$root_pid" -o args= 2>/dev/null)" == *"$RUN_MATCH"* ]] || return 0
  while IFS= read -r pid; do
    identity="$(zfsas_shutdown_identity "$pid")" || continue
    identities[$pid]="$identity"
  done < <(collect_pid_tree "$root_pid")
  for pid in "${!identities[@]}"; do
    [[ "$(zfsas_shutdown_identity "$pid" || true)" == "${identities[$pid]}" ]] && kill -TERM "$pid" 2>/dev/null || true
  done
  for ((round=0; round<120; round++)); do
    alive=0
    for pid in "${!identities[@]}"; do
      [[ "$(zfsas_shutdown_identity "$pid" || true)" == "${identities[$pid]}" ]] || continue
      alive=1
      if (( round >= 100 )); then kill -KILL "$pid" 2>/dev/null || true; fi
    done
    (( alive == 0 )) && return 0
    sleep 0.1
  done
  echo 'Worker shutdown could not be verified; runtime ownership has been preserved.' >&2
  return 1
}
zfsas_stop_recorded_send_groups() (
  source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
  local file
  local -A old_attempt=()
  for file in "$OPS_JOBS_DIR"/*.job; do
    [[ -f "$file" ]] || continue
    job_load "$file" old_attempt || continue
    case "${old_attempt[STATE]:-}" in running|canceling) ;; *) continue ;; esac
    [[ -n "${old_attempt[WORKER_START]:-}" ]] || continue
    stop_send_process_group "${old_attempt[WORKER_PGID]:-}" "${old_attempt[WORKER_START]}" || exit 1
  done
)
