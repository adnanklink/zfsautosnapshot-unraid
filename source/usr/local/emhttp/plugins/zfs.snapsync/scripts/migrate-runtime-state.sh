#!/bin/bash
# Installation only, after verified worker shutdown. Never execute old authority.
set -euo pipefail
config_dir="${ZFSAS_LEGACY_CONFIG_DIR:-/boot/config/plugins/zfs.snapsync}"
ops_root="${ZFSAS_RUNTIME_OPS_DIR:-/tmp/zfs-snapsync-ops}"
migrator_root="${ZFSAS_RUNTIME_MIGRATOR_DIR:-/tmp/zfs-snapsync-migrator}"
failed_logs="${ZFSAS_RUNTIME_FAILED_LOGS_DIR:-/var/log/zfs-snapsync-failed-sends}"
mkdir -p "$ops_root/legacy-review-required" "$ops_root/status" "$migrator_root" "$failed_logs"
# A checkpoint's legacy TSV companions remain recovery inputs until it finishes.
for name in status.env logs; do
  if [[ -e "$config_dir/dataset_migrator/$name" && ! -e "$ops_root/legacy-review-required/migrator-$name" ]]; then
    mv "$config_dir/dataset_migrator/$name" "$ops_root/legacy-review-required/migrator-$name"
  fi
done
if [[ ! -s "$config_dir/dataset_migrator/recovery.env" ]]; then
  for name in folders.tsv containers.tsv; do
    if [[ -e "$config_dir/dataset_migrator/$name" && ! -e "$ops_root/legacy-review-required/migrator-$name" ]]; then
      mv "$config_dir/dataset_migrator/$name" "$ops_root/legacy-review-required/migrator-$name"
    fi
  done
fi
# Legacy cursors are historical evidence, not proof of a completed current run.
for name in runtime_queue snapshot_manager send_schedule_state.state; do
  if [[ -e "$config_dir/$name" && ! -e "$ops_root/legacy-review-required/$name" ]]; then
    mv "$config_dir/$name" "$ops_root/legacy-review-required/$name"
  fi
done
if [[ -d "$config_dir/failed_send_logs" ]]; then
  for path in "$config_dir/failed_send_logs/"*.log; do
    [[ -f "$path" && ! -L "$path" ]] || continue
    # Preserve bounded evidence without replacing current-boot failure captures.
    target="$failed_logs/legacy-$(basename "$path")"
    if [[ ! -e "$target" ]]; then tail -c 4194304 "$path" > "$target"; fi
    rm -f "$path"
  done
  rmdir "$config_dir/failed_send_logs" 2>/dev/null || true
fi
chown -R nobody:users "$ops_root/legacy-review-required" "$failed_logs" "$migrator_root" 2>/dev/null || true
