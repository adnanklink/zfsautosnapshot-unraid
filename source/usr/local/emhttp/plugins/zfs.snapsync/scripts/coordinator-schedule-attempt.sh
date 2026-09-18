#!/bin/bash
set -Eeuo pipefail
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
printf '%s\n' '{"phase":"resource_admission","message":"Checking scheduled replication ownership."}' | php "$CLIENT" progress 1 >/dev/null
[[ $# == 4 ]] || exit 1
is_valid_dataset_name "$2" && is_valid_dataset_name "$3" || exit 1
if ! unraid_array_actionable; then
  printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 2 >/dev/null
elif ! acquire_dataset_gates -x "$2" "$3"; then
  printf '%s\n' '{"outcome":"wait","reason":"resource","delay":1,"message":"Another operation owns the dataset."}' | php "$CLIENT" result 2 >/dev/null
else
  if [[ "$4" == replication_schedule ]]; then retire_unstarted_local_send_jobs; fi
  php /usr/local/emhttp/plugins/zfs.snapsync/php/replication-schedule-worker.php "$1"
fi
