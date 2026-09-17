#!/bin/bash
set -Eeuo pipefail
# Locks use the same namespace as deletion, Snapshot Manager and Dataset Migrator.
# The wrapper and pipeline remain in the coordinator-owned process group.
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
printf '%s\n' '{"phase":"resource_admission","message":"Checking replication dataset ownership."}' | php "$CLIENT" progress 1 >/dev/null
[[ $# == 3 ]] || exit 1
is_valid_dataset_name "$2" && is_valid_dataset_name "$3" || exit 1
if ! unraid_array_actionable; then
  printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 2 >/dev/null
elif ! acquire_dataset_gates -x "$2" "$3"; then
  printf '%s\n' '{"outcome":"wait","reason":"resource","message":"Another operation owns the dataset."}' | php "$CLIENT" result 2 >/dev/null
else
  php /usr/local/emhttp/plugins/zfs.snapsync/php/replication-phase-worker.php "$1"
fi
