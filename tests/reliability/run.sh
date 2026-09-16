#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
# Endpoint fixtures intentionally write production paths; require disposable isolation.
[[ -f /.dockerenv ]] || { echo 'Run this suite in the disposable test container.' >&2; exit 1; }
bash tests/reliability/cancellation.sh
bash tests/reliability/ownership.sh
bash tests/reliability/destination.sh
php tests/reliability/settings_endpoints.php
php tests/reliability/snapshots.php
node tests/reliability/selection.cjs
# Each endpoint suite expects an isolated filesystem; run batch_endpoints.php in
# a separate container with plugin and sbin mounts (see docs/reliability-audit.md).
