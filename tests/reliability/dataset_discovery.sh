#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
cat > "$fixture/zfs" <<'ZFS'
#!/bin/bash
case "$DISCOVERY_CASE" in
  success) printf 'tank\ntank/data\n';;
  empty) exit 0;;
  slow) sleep 30;;
  *) exit 1;;
esac
ZFS
chmod +x "$fixture/zfs"
export PATH="$fixture:$PATH"
endpoint=source/usr/local/emhttp/plugins/zfs.snapsync/php/dataset-inventory.php
for DISCOVERY_CASE in success empty failure slow; do
  export DISCOVERY_CASE
  php "$endpoint" > "$fixture/result"
  python3 - "$fixture/result" "$DISCOVERY_CASE" <<'PY'
import json,sys
text=open(sys.argv[1]).read()
payload=json.loads(text.split('ZFSAS_JSON_BEGIN')[1].split('ZFSAS_JSON_END')[0])
case=sys.argv[2]
if case=='success':
    assert payload['ok'] and [r['dataset'] for r in payload['datasets']]==['tank','tank/data'], payload
elif case=='empty':
    assert payload['ok'] and payload['datasets']==[], payload
else:
    assert not payload['ok'] and payload['error'], payload
    if case=='slow': assert 'timed out' in payload['error'], payload
PY
done
echo 'PASS: dataset discovery endpoint success, empty pools, failure and bounded slow ZFS command'
