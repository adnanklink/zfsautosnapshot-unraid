<?php
/** Persistent policy is authorization; runtime candidates never belong on flash. */
function zfsas_send_cleanup_policies(array $config): array
{
    $raw = $config['SEND_CLEANUP_POLICIES'] ?? '{"version":1,"jobs":{}}';
    if (!is_string($raw) || strlen($raw) > 1048576) { throw new InvalidArgumentException('Invalid cleanup policy document.'); }
    try { $document = json_decode($raw, false, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new InvalidArgumentException('Invalid cleanup policy document.', 0, $error); }
    if (!$document instanceof stdClass || ($document->version ?? null) !== 1
        || !($document->jobs ?? null) instanceof stdClass
        || array_diff(array_keys(get_object_vars($document)), ['version', 'jobs'])) {
        throw new InvalidArgumentException('Unsupported cleanup policy document.');
    }
    $jobs = get_object_vars($document->jobs);
    foreach ($jobs as $id => $mode) {
        if (!preg_match('/^[a-f0-9]{12}$/D', (string)$id)
            || !in_array($mode, ['retention_only', 'older_anchors'], true)) {
            throw new InvalidArgumentException('Invalid per-job cleanup policy.');
        }
    }
    return $jobs;
}

function zfsas_send_cleanup_mode(array $config, array $job): string
{
    $mode = zfsas_send_cleanup_policies($config)[$job['id']] ?? 'retention_only';
    if ($mode === 'older_anchors' && ($job['transport'] ?? 'local') !== 'local') {
        throw new InvalidArgumentException('Retention anchor cleanup is available for local replication only.');
    }
    return $mode;
}

/** Missing form fields preserve existing decisions; new jobs never inherit them. */
function zfsas_send_cleanup_save(array $previous, array $jobs, array $choices): string
{
    $old = zfsas_send_cleanup_policies($previous); $result = [];
    foreach ($jobs as $job) {
        $id = $job['id']; $mode = $choices[$id] ?? $old[$id] ?? 'retention_only';
        if (!in_array($mode, ['retention_only', 'older_anchors'], true)
            || ($mode === 'older_anchors' && ($job['transport'] ?? 'local') !== 'local')) {
            throw new InvalidArgumentException('Choose a valid cleanup policy for each local replication job.');
        }
        $result[$id] = $mode;
    }
    ksort($result);
    return json_encode(['version'=>1, 'jobs'=>(object)$result], JSON_THROW_ON_ERROR);
}
