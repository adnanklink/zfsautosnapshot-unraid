#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"
zfs() {
  case "${*: -1}" in
    source/data@base|source/data@next) printf '123\n' ;;
    backup/data@base) printf '%s\n' "${DEST_GUID:-123}" ;;
    *) return 1 ;;
  esac
}
declare -A job=([SEND_PLAN_SOURCE_GUID]=123)
validate_send_identities source/data@base source/data@next backup/data local
DEST_GUID=456
if validate_send_identities source/data@base source/data@next backup/data local; then exit 1; fi
job[SEND_PLAN_SOURCE_GUID]=999
if validate_send_identities '' source/data@next backup/data local; then exit 1; fi
if rg -n 'zfs destroy -r|purge_destination_for_reseed|receive.*-uF' "$ROOT/source/usr/local/sbin/zfs_autosnapshot_send_worker" "$ROOT/source/usr/local/emhttp/plugins/zfs.autosnapshot/scripts/ops-queue-lib.sh"; then exit 1; fi
echo 'PASS: GUID-mismatched bases and changed source identities fail closed; no destructive reseed'
