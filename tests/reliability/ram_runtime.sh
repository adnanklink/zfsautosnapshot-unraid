#!/bin/bash
# Run inside a disposable container with /boot mounted read-only. Trace the
# entire process tree externally to catch attempted metadata writes as well.
set -euo pipefail
[[ -f /.dockerenv ]] || exit 1
root="$(cd "$(dirname "$0")/../.." && pwd)"
plugin="$root/source/usr/local/emhttp/plugins/zfs.autosnapshot"
source "$plugin/scripts/ops-queue-lib.sh"
ensure_runtime_layout
load_send_config
SCHEDULE_LAST_COMPLETED_WINDOW[fixture]=123
write_schedule_state
[[ "$SEND_SCHEDULE_STATE_FILE" == /tmp/* && -s "$SEND_SCHEDULE_STATE_FILE" ]]
printf 'failure evidence\n' > "$LOG_FILE"
declare -A failure=([JOB_ID]=ram-fixture [JOB_TYPE]=send [STATE]=failed [LAST_ERROR]='fixture failure')
preserve_failed_send_log_for_job failure
[[ "$(failed_send_log_path ram-fixture)" == /var/log/* ]]
php -r 'require $argv[1]; zfsas_config_read_pair("/boot/config/plugins/zfs.autosnapshot");' "$plugin/php/send-helpers.php"
php -r 'require $argv[1]; if (!zfsas_sm_ensure_storage_dirs()) { exit(1); }' "$plugin/php/snapshot-manager-helpers.php"
php "$plugin/php/send-queue-status.php" > /tmp/ram-status.json
php "$plugin/php/send-queue-stream.php" > /tmp/ram-stream.txt
# Exercise real migrator status/folder/container publication functions, without
# launching migration or any safety-critical operation.
(
  source <(sed '/^for cmd in zfs /,$d' "$root/source/usr/local/sbin/zfs_autosnapshot_migrate_datasets")
  ensure_dir "$PLUGIN_ROOT"
  CURRENT_FOLDER_PERCENT=42
  write_status_file
  [[ "$STATUS_FILE" == /tmp/* && -s "$STATUS_FILE" ]]
)
# Removed shutdown persistence must never be called or reintroduced silently.
! grep -q 'load_boot_persisted_state\|write_boot_persisted_snapshot\|PERSIST_QUEUE_ON_EXIT' "$root/source/usr/local/sbin/zfs_autosnapshot_delete_worker"
echo 'PASS: RAM cursors, bounded failure capture, settings reads, status polling, migrator progress with read-only /boot'
