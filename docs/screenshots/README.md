# Workspace screenshots

Captured with Chromium from the actual PHP views and JavaScript using the demo responses in `tests/reliability/workspace_browser.cjs`. No live server data or surrounding Unraid shell is shown. Capture viewport: 1440 × 1000; Overview is a full-page capture, Automation shows the first viewport, and Replication demonstrates the dark theme.

To refresh, run the workspace browser suite in the disposable test image with a writable directory mounted at `/tmp/zfsas-ui-screenshots`. Copy these outputs here:

- `section-overview-light.png` → `overview.png`
- `section-snapshots-tab-automation-light.png` → `automation.png`
- `section-replication-dark.png` → `replication-dark.png`

The test serves production PNG assets and verifies the brand image loaded before capture.
