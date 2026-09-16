#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
# Endpoint fixtures intentionally write production paths; require disposable isolation.
[[ -f /.dockerenv ]] || { echo 'Run this suite in the disposable test container.' >&2; exit 1; }
bash tests/reliability/cancellation.sh
bash tests/reliability/ownership.sh
bash tests/reliability/destination.sh
bash tests/reliability/dependencies.sh
bash tests/reliability/send_occurrences.sh
php tests/reliability/settings_endpoints.php
php tests/reliability/snapshots.php
php tests/reliability/coordinator_state.php
php tests/reliability/coordinator_replan.php
php tests/reliability/coordinator_retention.php
php tests/reliability/coordinator_socket.php
php tests/reliability/coordinator_executor.php
php tests/reliability/coordinator_recovery.php
php tests/reliability/schedules.php
php tests/reliability/send_schedules.php
node tests/reliability/selection.cjs
# Each endpoint suite expects an isolated filesystem; run batch_endpoints.php in
# a separate container with plugin and sbin mounts (see docs/reliability-audit.md).
