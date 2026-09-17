<?php
require_once __DIR__ . '/response-helpers.php';
if (!defined('ZFSAS_WORKSPACE')) { define('ZFSAS_WORKSPACE', true); }
function zfsas_ui_h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function zfsas_ui_url($section, $tab = '') { return '/Settings/ZFSAutoSnapshot?' . http_build_query(array_filter(['section' => $section, 'tab' => $tab])); }
$uiSection = is_string($_GET['section'] ?? null) ? $_GET['section'] : 'overview';
$uiTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
if ($uiSection === 'main') { $uiSection = 'snapshots'; $uiTab = 'automation'; }
$uiSection = ['special-features' => 'tools', 'snapshot-manager' => 'snapshots'][$uiSection] ?? $uiSection;
$uiSections = ['overview' => ['Overview', 'Your snapshots and transfers, at a glance.', 'overview'],
    'snapshots' => ['Snapshots', 'Browse snapshots or configure automatic protection.', 'snapshots'],
    'replication' => ['Replication', 'Keep another copy. Control where, when, and how it gets there.', 'replication'],
    'activity' => ['Activity', 'Follow current work and investigate recent results.', 'activity'],
    'tools' => ['Tools', 'Migration and diagnostics, with a clear review before changes.', 'tools'],
    'help' => ['Help', 'Understand the workflows and find your next step.', 'help']];
if (!isset($uiSections[$uiSection])) { $uiSection = 'overview'; }
if ($uiSection === 'snapshots') { $uiTab = $uiTab === 'automation' ? 'automation' : 'browse'; }
if ($uiSection === 'tools') { $uiTab = $uiTab === 'migrator' ? 'migrator' : ''; }
$uiCsrf = zfsas_get_csrf_token();
$uiVersion = 'Development';
$installed = '/var/log/plugins/zfs.autosnapshot.plg';
if (is_file($installed) && preg_match('/<PLUGIN\b[^>]*version="([^"]+)"/', (string) file_get_contents($installed), $match)) { $uiVersion = $match[1]; }
$uiStandalone = empty($GLOBALS['zfsas_host_shell']);
if ($uiStandalone) { ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= zfsas_ui_h($uiSections[$uiSection][0]) ?> · ZFS Auto Snapshot</title></head><body><?php }
?>
<link rel="stylesheet" href="/plugins/zfs.autosnapshot/css/workspace.css">
<div class="zfsas-workspace" data-section="<?= zfsas_ui_h($uiSection) ?>" data-csrf="<?= zfsas_ui_h($uiCsrf) ?>">
<a class="ui-skip" href="#workspace-content">Skip to content</a>
<aside class="ui-sidebar">
  <a class="ui-brand" href="<?= zfsas_ui_url('overview') ?>"><span class="ui-brand-mark" aria-hidden="true">Z</span><span>ZFS Auto Snapshot<small>Protection workspace</small></span></a>
  <button type="button" class="ui-menu-toggle" aria-expanded="false" aria-controls="workspace-navigation">Menu</button>
  <nav id="workspace-navigation" aria-label="Plugin navigation">
  <?php $icons = ['overview' => '▦', 'snapshots' => '▤', 'replication' => '⇄', 'activity' => '◷', 'tools' => '◇', 'help' => '?']; foreach ($uiSections as $key => $item): ?>
    <a href="<?= zfsas_ui_url($key) ?>" <?= $key === $uiSection ? 'aria-current="page"' : '' ?>><span class="ui-nav-icon" aria-hidden="true"><?= $icons[$key] ?></span><?= zfsas_ui_h($item[0]) ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="ui-sidebar-foot"><span class="ui-badge">Development build</span><small><?= zfsas_ui_h($uiVersion) ?></small><a href="<?= zfsas_ui_url('help') ?>#recovery">Runtime history lives in RAM</a></div>
</aside>
<main id="workspace-content" class="ui-content" tabindex="-1">
  <header class="ui-page-header"><div><p class="ui-eyebrow">ZFS AUTO SNAPSHOT</p><h1><?= zfsas_ui_h($uiSections[$uiSection][0]) ?></h1><p><?= zfsas_ui_h($uiSections[$uiSection][1]) ?></p></div><a class="ui-subtle-link" href="<?= zfsas_ui_url('help') ?>">Help &amp; recovery <span aria-hidden="true">↗</span></a></header>
  <?php if ($uiSection === 'snapshots'): ?><nav class="ui-tabs" aria-label="Snapshot views"><a <?= $uiTab === 'browse' ? 'aria-current="page"' : '' ?> href="<?= zfsas_ui_url('snapshots') ?>">Browse snapshots</a><a <?= $uiTab === 'automation' ? 'aria-current="page"' : '' ?> href="<?= zfsas_ui_url('snapshots', 'automation') ?>">Automation</a></nav><?php endif; ?>
  <div id="workspace-notice" role="status" aria-live="polite"></div>
  <script src="/plugins/zfs.autosnapshot/js/workspace.js"></script>
  <script src="/plugins/zfs.autosnapshot/js/workspace-requests.js"></script>
  <?php
  switch ($uiSection) {
      case 'snapshots': require __DIR__ . ($uiTab === 'automation' ? '/settings.php' : '/snapshot-manager-page.php'); break;
      case 'replication': require __DIR__ . '/send-settings.php'; break;
      case 'tools': require __DIR__ . ($uiTab === 'migrator' ? '/migrate-datasets.php' : '/views/tools.php'); break;
      case 'help': require __DIR__ . '/views/help.php'; break;
      default: require __DIR__ . '/views/operations.php'; break;
  }
  ?>
  <footer class="ui-footer">ZFS Auto Snapshot <span>Development preview · Full replication coordination is still in progress.</span></footer>
</main>
</div>
<?php if ($uiStandalone) { ?></body></html><?php }
