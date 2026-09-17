#!/usr/bin/env python3
"""Static contracts for the GitHub issue help/diagnostics workflow."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "source/usr/local/emhttp/plugins/zfs.snapsync"
SETTINGS_PAGE = PLUGIN / "php/settings.php"
DIAGNOSTICS_PAGE = PLUGIN / "php/diagnostics.php"


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def extract_report_issue_block(settings: str) -> str:
    marker = '<h2>Report an issue</h2>'
    start = settings.find(marker)
    if start == -1:
        raise AssertionError("Help must include Report an issue")
    return settings[start:settings.index('</section>', start)]


def main() -> int:
    settings = (PLUGIN / "php/views/help.php").read_text() + (PLUGIN / "php/views/tools.php").read_text() + (PLUGIN / "php/workspace.php").read_text()
    if not DIAGNOSTICS_PAGE.is_file():
        raise AssertionError("diagnostics.php endpoint must exist")
    diagnostics = DIAGNOSTICS_PAGE.read_text()

    assert_contains(
        settings,
        "zfsas_ui_url('help')",
        "settings page must expose a Help tab",
    )
    assert_contains(
        settings,
        "https://github.com/adnanklink/zfssnapsync-auto-unraid/issues",
        "Help tab must link to the repository's GitHub issues page",
    )
    assert_contains(
        settings,
        "zfs_snapsync_diagnostics.zip",
        "Help tab must provide a diagnostics zip download button",
    )
    assert_contains(
        settings,
        "/plugins/zfs.snapsync/php/diagnostics.php",
        "Help tab diagnostics button must call the diagnostics endpoint",
    )

    assert_contains(
        diagnostics,
        "function zfsas_diagnostics_redact",
        "diagnostics endpoint must redact sensitive values before writing files",
    )
    for token in ["PASSWORD", "API_KEY", "TOKEN", "SECRET"]:
        assert_contains(
            diagnostics,
            token,
            f"diagnostics redaction must cover {token} style secrets",
        )
    assert_contains(
        diagnostics,
        "ZipArchive",
        "diagnostics endpoint must create a zip archive",
    )
    assert_contains(
        diagnostics,
        "/var/log/zfs_snapsync.log",
        "diagnostics archive must include the main plugin debug log when present",
    )
    assert_contains(
        diagnostics,
        "/var/log/zfs_snapsync_send.log",
        "diagnostics archive must include the send log when present",
    )
    assert_contains(
        diagnostics,
        "/boot/config/plugins/zfs.snapsync/zfs_snapsync.conf",
        "diagnostics archive must include redacted auto-snapshot config when present",
    )
    assert_contains(
        diagnostics,
        "/boot/config/plugins/zfs.snapsync/zfs_send.conf",
        "diagnostics archive must include redacted send config when present",
    )
    assert_contains(
        diagnostics,
        "zfsas_diagnostics_write_zfs_summary",
        "diagnostics archive must summarize ZFS datasets/snapshots instead of dumping full inventories",
    )
    assert_contains(
        diagnostics,
        "commands/zfs-summary.txt",
        "diagnostics archive must include a public-safe ZFS summary file",
    )
    assert_contains(
        diagnostics,
        "commands/send-summary.txt",
        "diagnostics archive must include send-checkpoint summary counts",
    )
    for forbidden in [
        "commands/zfs-list-datasets.txt",
        "commands/zfs-list-snapshots.txt",
        "commands/df.txt",
        "commands/mount.txt",
    ]:
        if forbidden in diagnostics:
            raise AssertionError(f"diagnostics archive must not include raw high-detail topology file {forbidden}")
    assert_contains(
        diagnostics,
        "zpool status",
        "diagnostics archive must collect read-only zpool status",
    )
    for redaction_marker in ["[REDACTED_HOST]", "[REDACTED_IP]", "[REDACTED_DOCKER_ID]", "[REDACTED_HASH]", "[REDACTED_SSH_LOGIN]"]:
        assert_contains(
            diagnostics,
            redaction_marker,
            f"diagnostics redaction must include public-safe marker {redaction_marker}",
        )
    assert_contains(
        diagnostics,
        "Linux [REDACTED_HOST]",
        "diagnostics redaction must redact the hostname emitted by uname -a",
    )
    assert_contains(
        diagnostics,
        "includeNotableLines",
        "syslog summaries must be able to omit raw notable lines that expose unrelated app/share/plugin names",
    )
    assert_contains(
        diagnostics,
        "Recent notable lines omitted for public-safe syslog summary",
        "public syslog summaries must document that raw notable lines are intentionally omitted",
    )
    assert_contains(
        diagnostics,
        "allowKnownSymlink",
        "installed plugin manifest collection must safely allow the known manifest symlink path",
    )
    assert_contains(
        diagnostics,
        "'/boot/config/plugins/zfs.snapsync.plg'",
        "diagnostics safety allowlist must permit the boot plugin manifest path",
    )
    report_issue_block = extract_report_issue_block(settings)
    for issue_field in [
        "description of the problem",
        "which system",
        "how to reproduce",
        "plugin version",
        "Unraid version",
        "diagnostics zip",
    ]:
        assert_contains(
            report_issue_block,
            issue_field,
            f"Report an issue help block must request {issue_field}",
        )

    print("PASS: diagnostics help/export static contracts")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
