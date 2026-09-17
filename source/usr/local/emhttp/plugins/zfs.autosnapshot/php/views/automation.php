<div class="zfsas-wrap">
  <div id="compat_feedback"></div>

  <?php if ($datasetDiscoveryError !== null) : ?>
    <div class="zfsas-alert zfsas-alert-warn">
      <?php echo h($datasetDiscoveryError); ?>
      Existing configured datasets are still shown.
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo h($saveApiUrl); ?>" data-ajax-action="<?php echo h($saveApiUrl); ?>" id="zfsas_settings_form">
    <?php echo zfsas_config_tools_markup('auto', $configDir, $pageConfig); ?>
    <input type="hidden" name="return_to" value="<?php echo h($defaultSettingsReturnUrl); ?>">
    <?php if ($csrfToken !== '') : ?>
    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
    <?php endif; ?>
      <div class="zfsas-card">
        <h3>Datasets</h3>
        <div class="zfsas-help">
          Check the datasets you want this plugin to manage. Only checked datasets are included in automated snapshot cleanup and creation.
        </div>

        <p id="dataset-discovery-status" class="zfsas-help">Saved selections are shown while datasets load.</p>
          <div class="zfsas-dataset-toolbar">
            <div class="zfsas-pool-filter">
              <label for="dataset_name_filter">Dataset search</label><input id="dataset_name_filter" type="search" class="zfsas-input">
              <label for="dataset_pool_filter">Pool</label>
              <select id="dataset_pool_filter" class="zfsas-select">
                <option value="__all">All pools</option>
                <?php foreach ($datasetPools as $poolName => $poolStats) : ?>
                  <option value="<?php echo h($poolName); ?>">
                    <?php echo h($poolName); ?> (<?php echo (int) $poolStats['total']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn" id="dataset_select_visible">Select shown</button>
            <button type="button" class="btn" id="dataset_clear_visible">Clear shown</button>
            <button type="button" class="btn" id="dataset_select_all">Select all</button>
            <button type="button" class="btn" id="dataset_clear_all">Clear all</button>
            <div class="zfsas-help zfsas-dataset-count" id="dataset_count"></div>
          </div>

          <div class="zfsas-table-wrap">
            <table class="zfsas-table">
              <thead>
                <tr>
                  <th class="zfsas-center">Use</th>
                  <th>Dataset</th>
                  <th class="zfsas-threshold-col">Pool free-space target</th>
                </tr>
              </thead>
              <tbody id="async-dataset-rows">
                <?php foreach ($datasetRows as $index => $row) : ?>
                  <tr class="zfsas-dataset-row<?php echo !empty($row['locked']) ? ' zfsas-row-locked' : ''; ?>" data-pool="<?php echo h($row['pool']); ?>">
                    <td class="zfsas-center">
                      <input type="hidden" name="dataset_name[<?php echo (int) $index; ?>]" value="<?php echo h($row['dataset']); ?>">
                      <input class="zfsas-dataset-checkbox" type="checkbox" name="dataset_selected[<?php echo (int) $index; ?>]" value="1" <?php echo $row['selected'] ? 'checked' : ''; ?> <?php echo !empty($row['locked']) ? 'disabled' : ''; ?>>
                    </td>
                    <td>
                      <div class="zfsas-dataset-cell">
                        <div>
                          <code><?php echo h($row['dataset']); ?></code>
                          <span class="zfsas-pool-chip"><?php echo h($row['pool']); ?></span>
                          <?php if (!empty($row['locked'])) : ?>
                            <span class="zfsas-badge">Reserved for ZFS Send destination</span>
                          <?php endif; ?>
                          <?php if (!$row['available']) : ?>
                            <span class="zfsas-badge" data-undetected>Not currently detected</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td class="zfsas-threshold-col">
                      <input class="zfsas-input zfsas-threshold-input" name="dataset_threshold[<?php echo (int) $index; ?>]" value="<?php echo h($row['threshold']); ?>" <?php echo !empty($row['locked']) ? 'disabled' : ''; ?>>
                      <div class="zfsas-help">Examples: <code>500M</code>, <code>100G</code>, <code>2T</code>.</div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

      </div>

      <div class="zfsas-card">
        <h3>Retention Policy (Days)</h3>
        <div class="zfsas-grid">
          <div class="zfsas-field">
            <label for="keep_all_for_days">Keep every snapshot for this many days</label>
            <input id="keep_all_for_days" name="keep_all_for_days" class="zfsas-input" type="number" min="1" max="36500" value="<?php echo h($config['KEEP_ALL_FOR_DAYS']); ?>">
            <div class="zfsas-help">
              All snapshots newer than this age are kept.
            </div>
          </div>

          <div class="zfsas-field">
            <label for="keep_daily_until_days">Then keep 1 snapshot per day until this many days</label>
            <input id="keep_daily_until_days" name="keep_daily_until_days" class="zfsas-input" type="number" min="2" max="36500" value="<?php echo h($config['KEEP_DAILY_UNTIL_DAYS']); ?>">
            <div class="zfsas-help">
              For snapshots older than the first window, keep the newest snapshot from each day.
            </div>
          </div>

          <div class="zfsas-field">
            <label for="keep_weekly_until_days">Then keep 1 snapshot per week until this many days</label>
            <input id="keep_weekly_until_days" name="keep_weekly_until_days" class="zfsas-input" type="number" min="3" max="36500" value="<?php echo h($config['KEEP_WEEKLY_UNTIL_DAYS']); ?>">
            <div class="zfsas-help">
              Snapshots older than this are removed.
            </div>
          </div>
        </div>
      </div>

      <div class="zfsas-card">
        <h3>Run Schedule</h3>
        <div id="auto-coordinator-status">
          <p data-status role="status">Checking coordinator status…</p>
          <button type="button" class="btn" data-cancel disabled>Cancel run and pause</button>
          <button type="button" class="btn" data-resume disabled>Resume schedule</button>
          <p>Runtime history is kept in RAM and is lost on reboot. A saved pause remains until Resume.</p>
        </div>
        <p>Existing interval schedules retain their cron alignment until converted. New intervals first run one interval after Save; Run Now does not change that time.</p>
        <label><input type="checkbox" name="convert_schedule" value="1" id="convert_schedule"> Convert this schedule to the new timing rules on Save</label>
        <p id="schedule-next-preview" role="status">Next run preview loads when scheduling fields change.</p>
        <div class="zfsas-field">
          <label for="schedule_mode">How often should automatic runs happen?</label>
          <select id="schedule_mode" name="schedule_mode" class="zfsas-select">
            <option value="disabled" <?php echo ($config['SCHEDULE_MODE'] === 'disabled') ? 'selected' : ''; ?>>Disabled (manual only)</option>
            <option value="minutes" <?php echo ($config['SCHEDULE_MODE'] === 'minutes') ? 'selected' : ''; ?>>Every N minutes</option>
            <option value="hourly" <?php echo ($config['SCHEDULE_MODE'] === 'hourly') ? 'selected' : ''; ?>>Every N hours</option>
            <option value="daily" <?php echo ($config['SCHEDULE_MODE'] === 'daily') ? 'selected' : ''; ?>>Every day at a specific time</option>
            <option value="weekly" <?php echo ($config['SCHEDULE_MODE'] === 'weekly') ? 'selected' : ''; ?>>Once per week</option>
            <option value="custom" <?php echo ($config['SCHEDULE_MODE'] === 'custom') ? 'selected' : ''; ?>>Advanced: custom cron</option>
          </select>
          <div class="zfsas-help">
            The preview uses the host timezone and the saved schedule timing rules.
          </div>
        </div>

        <div class="zfsas-field zfsas-schedule-row" data-mode="minutes" style="margin-top: 12px;">
          <label for="schedule_every_minutes">Every how many minutes?</label>
          <input id="schedule_every_minutes" name="schedule_every_minutes" class="zfsas-input" type="number" min="1" max="59" value="<?php echo h($config['SCHEDULE_EVERY_MINUTES']); ?>">
          <div class="zfsas-help">
            1 means every minute. 15 means every 15 minutes.
          </div>
        </div>

        <div class="zfsas-field zfsas-schedule-row" data-mode="hourly" style="margin-top: 12px;">
          <label for="schedule_every_hours">Every how many hours?</label>
          <input id="schedule_every_hours" name="schedule_every_hours" class="zfsas-input" type="number" min="1" max="24" value="<?php echo h($config['SCHEDULE_EVERY_HOURS']); ?>">
          <div class="zfsas-help">
            1 means every hour. 6 means every 6 hours.
          </div>
        </div>

        <div class="zfsas-field zfsas-schedule-row" data-mode="daily" style="margin-top: 12px;">
          <label>Daily run time (24-hour clock)</label>
          <div class="zfsas-inline">
            <input id="schedule_daily_hour" name="schedule_daily_hour" class="zfsas-input" type="number" min="0" max="23" value="<?php echo h($config['SCHEDULE_DAILY_HOUR']); ?>">
            <input id="schedule_daily_minute" name="schedule_daily_minute" class="zfsas-input" type="number" min="0" max="59" value="<?php echo h($config['SCHEDULE_DAILY_MINUTE']); ?>">
          </div>
          <div class="zfsas-help">
            Example: 03 and 30 means 3:30 AM every day.
          </div>
        </div>

        <div class="zfsas-field zfsas-schedule-row" data-mode="weekly" style="margin-top: 12px;">
          <label for="schedule_weekly_day">Weekly day and time</label>
          <div class="zfsas-inline">
            <select id="schedule_weekly_day" name="schedule_weekly_day" class="zfsas-select">
              <?php foreach ($weekdayNames as $dayValue => $dayLabel) : ?>
                <option value="<?php echo h($dayValue); ?>" <?php echo ((string) $config['SCHEDULE_WEEKLY_DAY'] === (string) $dayValue) ? 'selected' : ''; ?>><?php echo h($dayLabel); ?></option>
              <?php endforeach; ?>
            </select>
            <input id="schedule_weekly_hour" name="schedule_weekly_hour" class="zfsas-input" type="number" min="0" max="23" value="<?php echo h($config['SCHEDULE_WEEKLY_HOUR']); ?>">
            <input id="schedule_weekly_minute" name="schedule_weekly_minute" class="zfsas-input" type="number" min="0" max="59" value="<?php echo h($config['SCHEDULE_WEEKLY_MINUTE']); ?>">
          </div>
          <div class="zfsas-help">
            Example: Sunday, 04 and 00 means every Sunday at 4:00 AM.
          </div>
        </div>

        <div class="zfsas-field zfsas-schedule-row" data-mode="custom" style="margin-top: 12px;">
          <label for="custom_cron_schedule">Custom cron expression (advanced)</label>
          <input id="custom_cron_schedule" name="custom_cron_schedule" class="zfsas-input" value="<?php echo h($config['CUSTOM_CRON_SCHEDULE']); ?>">
          <div class="zfsas-help">
            Use only if you need behavior outside the plain-English options. Format must have exactly 5 fields.
          </div>
        </div>

        <div id="schedule_preview" class="zfsas-preview"></div>
        <div class="zfsas-help" style="margin-top: 10px;">
          Current cron expression: <code id="resolved_cron_value"><?php echo h($resolvedCron); ?></code>
        </div>
      </div>

      <details id="automation-advanced" class="zfsas-card"><summary>Advanced: naming and Dry Run</summary>
        <div class="zfsas-field" style="margin-top: 14px;">
          <label for="prefix">Snapshot name prefix</label>
          <input id="prefix" name="prefix" class="zfsas-input" value="<?php echo h($config['PREFIX']); ?>">
          <div class="zfsas-help">
            Safety guard: only snapshots containing the prefix <code id="prefix_preview"><?php echo h($config['PREFIX']); ?></code> are eligible for automatic deletion.
          </div>
        </div>

        <div class="zfsas-field" style="margin-top: 12px;">
          <label for="dry_run">Mode</label>
          <label class="zfsas-checkline" for="dry_run">
            <input type="checkbox" id="dry_run" name="dry_run" value="1" <?php echo ($config['DRY_RUN'] === '1') ? 'checked' : ''; ?>>
            <span>Dry run (preview only, no snapshot create/delete)</span>
          </label>
          <div class="zfsas-help">
            Leave this unchecked for normal operation. In Dry Run mode, open Activity → Logs → Auto Snapshot debug to see each action that would be taken.
          </div>
        </div>
      </details>

      <div class="zfsas-actions">
        <div id="manual_run_status" class="zfsas-manual-status">Manual run is ready.</div>
        <div id="save_feedback" class="zfsas-save-feedback-inline">
          <?php if (!empty($errors)) : ?>
            <div class="zfsas-alert zfsas-alert-error">
              <?php foreach ($errors as $error) : ?>
                <div><?php echo h($error); ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <button type="button" class="btn" id="manual_run">Run Now</button>
        <button
          type="button"
          class="btn btn-primary"
          id="zfsas_save_btn"
          <?php if (!empty($notices)) : ?>data-show-saved="1"<?php endif; ?>
        >Save automation</button>
        <noscript><button type="submit" class="btn btn-primary">Save automation</button></noscript>
      </div>

  </form>
<script src="/plugins/zfs.autosnapshot/js/config-tools.js"></script>
</div>

<script type="application/json" id="automation-options"><?php echo json_encode([(int) $logPollIntervalMs, $logApiUrl, $logStreamApiUrl, $runApiUrl, $saveApiUrl, $diagnosticsApiUrl, $sendSettingsUrl, $migrateDatasetsUrl, $snapshotManagerEmbeddedUrl, $initialSection], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="/plugins/zfs.autosnapshot/js/automation.js"></script>
<script src="/plugins/zfs.autosnapshot/js/dataset-discovery.js"></script>


<script src="/plugins/zfs.autosnapshot/js/schedule-preview.js"></script>

<script src="/plugins/zfs.autosnapshot/js/coordinator-status.js"></script>
