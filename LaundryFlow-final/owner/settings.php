<?php
/**
 * Owner: shop details, business rules, colors/theme, demo data, AI/SMS status.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/demo.php';
$u = require_role('owner');

$fields = [
    'shop_name'      => ['Shop / system name', 'text', null],
    'shop_branch'    => ['Branch name', 'text', null],
    'shop_address'   => ['Address (shown to customers)', 'text', null],
    'shop_phone'     => ['Shop phone', 'text', null],
    'announcement'   => ['Announcement shown to customers (leave empty for none)', 'text', null],
    'staff_default_lang' => ['Language for new employees', 'choice', ['tl', 'en']],
    'loyalty_every'  => ['Loyalty: every Nth order gets a discount (0 = off)', 'number', [0, 100]],
    'loyalty_discount_percent' => ['Loyalty discount (%)', 'number', [0, 100]],
    'open_hour'      => ['Opening hour (0–23)', 'number', [0, 23]],
    'close_hour'     => ['Closing hour (1–24)', 'number', [1, 24]],
    'rush_percent'   => ['Rush surcharge (%)', 'number', [0, 200]],
    'rush_speed_factor' => ['Rush time factor (0.5 = twice as fast)', 'number', [0.1, 1]],
    'capacity_kg_per_hour' => ['Shop capacity (kg per hour)', 'number', [1, 1000]],
    'piece_kg_equivalent' => ['Workload of 1 piece (kg equivalent)', 'number', [0.1, 50]],
    'reminder_after_hours' => ['Pickup reminder after (hours ready)', 'number', [1, 720]],
    'max_reminders'  => ['Maximum reminders per order', 'number', [0, 10]],
    'discrepancy_threshold_percent' => ['Flag weight changes of at least (%)', 'number', [1, 100]],
];

function save_setting_audited(string $k, string $v): void
{
    $old = (string)setting($k, '');
    if ($old !== $v) {
        set_setting($k, $v);
        audit('settings_updated', 'settings', null, null, $k, $old, $v);
    }
}

if (is_post()) {
    verify_csrf();
    $action = post('action', 'save');
    try {
        if ($action === 'demo_load') {
            $n = load_demo_data();
            flash('success', "Demo data loaded: $n orders over the last 7 days. You can delete it anytime here in Settings.");
            redirect('owner/dashboard.php');
        }
        if ($action === 'demo_delete') {
            if (post('confirm') !== '1') {
                throw new RuntimeException('Tick the box to confirm deleting the demo data.');
            }
            $r = delete_demo_data((int)$u['id']);
            flash('success', "Demo data deleted: {$r['orders']} orders, {$r['customers']} customers, {$r['expenses']} expenses, {$r['users_deleted']} demo accounts removed" .
                ($r['users_deactivated'] ? ", {$r['users_deactivated']} demo account(s) deactivated because they were used for real work" : '') . '. Your real data was not touched.');
            redirect('owner/settings.php');
        }
        if ($action === 'theme') {
            $theme = post('theme');
            if (!isset(THEMES[$theme]) && $theme !== 'custom') throw new RuntimeException('Choose a color theme.');
            $color = strtolower(post('custom_color', '#db2777'));
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) throw new RuntimeException('Choose a valid custom color.');
            save_setting_audited('theme', $theme);
            save_setting_audited('custom_color', $color);
            save_setting_audited('allow_user_theme', post('allow_user_theme') === '1' ? '1' : '0');
            flash('success', 'Colors saved.');
            redirect('owner/settings.php#theme');
        }

        // Main settings
        $errors = [];
        $vals = [];
        foreach ($fields as $k => [$label, $type, $range]) {
            $v = post($k);
            if ($type === 'choice' && !in_array($v, $range, true)) {
                $errors[] = $label . ' is not valid.';
                continue;
            }
            if ($type === 'number' && (!is_numeric($v) || (float)$v < $range[0] || (float)$v > $range[1])) {
                $errors[] = $label . ' must be between ' . $range[0] . ' and ' . $range[1] . '.';
                continue;
            }
            if ($type === 'text' && $v === '' && $k === 'shop_name') {
                $errors[] = 'Shop name is required.';
                continue;
            }
            $vals[$k] = mb_substr($v, 0, 255);
        }
        if (isset($vals['open_hour'], $vals['close_hour']) && (int)$vals['close_hour'] <= (int)$vals['open_hour']) {
            $errors[] = 'Closing hour must be later than opening hour.';
        }
        if (isset($vals['loyalty_every']) && (int)$vals['loyalty_every'] === 1) {
            $errors[] = 'Loyalty: use 0 (off) or 2 and above.';
        }
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            foreach ($vals as $k => $v) save_setting_audited($k, $v);
            flash('success', 'Settings saved.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('owner/settings.php');
}

$demo = demo_counts();
$curTheme = (string)setting('theme', 'pink');
layout_start('Settings', 'settings', ['subtitle' => 'Shop details, business rules, colors and demo data']);
?>
<div class="grid g-main">
  <div class="stack">
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <h3>🏪 Shop details</h3>
      <div class="row">
        <?php foreach (['shop_name', 'shop_branch', 'shop_address', 'shop_phone'] as $k): ?>
          <div class="field"><label><?= e($fields[$k][0]) ?></label><input type="text" name="<?= e($k) ?>" value="<?= e(setting($k, '')) ?>"></div>
        <?php endforeach; ?>
      </div>
      <div class="row">
        <div class="field"><label>📢 Announcement for customers</label><input type="text" name="announcement" value="<?= e(setting('announcement', '')) ?>" placeholder="e.g. Closed on Dec 25. Free fabcon this week!"></div>
        <div class="field"><label>🌐 Language for new employees</label><select name="staff_default_lang">
          <option value="tl" <?= setting('staff_default_lang', 'tl') === 'tl' ? 'selected' : '' ?>>Tagalog</option>
          <option value="en" <?= setting('staff_default_lang', 'tl') === 'en' ? 'selected' : '' ?>>English</option></select></div>
      </div>
      <h3 class="mt">🎁 Loyalty reward</h3>
      <div class="row">
        <div class="field"><label>Every Nth order gets a discount (0 = off)</label><input type="number" name="loyalty_every" value="<?= e(setting('loyalty_every', '10')) ?>"></div>
        <div class="field"><label>Discount (%)</label><input type="number" step="any" name="loyalty_discount_percent" value="<?= e(setting('loyalty_discount_percent', '10')) ?>"></div>
      </div>
      <h3 class="mt">⚙️ Business rules</h3>
      <div class="row">
        <?php foreach ($fields as $k => [$label, $type, $range]): if ($type !== 'number' || strpos($k, 'loyalty') === 0) continue; ?>
          <div class="field"><label><?= e($label) ?></label><input type="number" step="any" name="<?= e($k) ?>" value="<?= e(setting($k, '')) ?>"></div>
        <?php endforeach; ?>
      </div>
      <p class="small muted">Capacity and turnaround hours drive the ready-time estimate and the workload indicator. Every change is recorded in the audit log.</p>
      <button class="btn">💾 Save settings</button>
    </form>

    <form method="post" class="card" id="theme">
      <?= csrf_field() ?><input type="hidden" name="action" value="theme">
      <h3>🎨 Colors</h3>
      <p class="small muted">The shop's default colors for everyone (staff, customers and the login page).</p>
      <div class="choice-grid">
        <?php foreach (THEMES as $key => $th): ?>
          <label class="choice"><input type="radio" name="theme" value="<?= e($key) ?>" <?= $curTheme === $key ? 'checked' : '' ?>>
            <b><span class="swatch" style="background:<?= e($th[1]) ?>"></span><span class="swatch" style="background:<?= e($th[4]) ?>"></span> <?= e($th[0]) ?></b></label>
        <?php endforeach; ?>
        <label class="choice"><input type="radio" name="theme" value="custom" <?= $curTheme === 'custom' ? 'checked' : '' ?>>
          <b>🖌️ Custom color</b>
          <input type="color" name="custom_color" value="<?= e(setting('custom_color', '#db2777')) ?>" aria-label="Custom color">
        </label>
      </div>
      <label class="check mt"><input type="checkbox" name="allow_user_theme" value="1" <?= setting('allow_user_theme', '1') === '1' ? 'checked' : '' ?>>
        <span>Let each user choose their own colors in <b>My account</b> (if off, everyone uses the shop colors)</span></label>
      <button class="btn mt">🎨 Save colors</button>
    </form>
  </div>

  <div class="stack">
    <div class="card" id="demo">
      <h3>🧪 Demo data</h3>
      <?php if ($demo['orders'] || $demo['customers'] || $demo['users'] || $demo['expenses']): ?>
        <p class="small">Currently in the system: <b><?= $demo['orders'] ?></b> demo orders · <b><?= $demo['customers'] ?></b> demo customers ·
          <b><?= $demo['expenses'] ?></b> demo expenses · <b><?= $demo['users'] ?></b> demo accounts (jessa, carlo, angela).</p>
      <?php else: ?>
        <p class="small muted">No demo data in the system. 🎉</p>
      <?php endif; ?>
      <?php if (!$demo['orders']): ?>
        <p class="small muted">Load one week of sample orders, payments, cash closings, expenses and a weight discrepancy — useful for practice and presentations.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="demo_load"><button class="btn btn-outline btn-block">🧪 Load demo data</button></form>
      <?php endif; ?>
      <?php if ($demo['orders'] || $demo['customers'] || $demo['users'] || $demo['expenses']): ?>
        <form method="post" class="mt">
          <?= csrf_field() ?><input type="hidden" name="action" value="demo_delete">
          <label class="check small"><input type="checkbox" name="confirm" value="1" required> I understand: all DEMO orders, payments, customers, expenses and demo accounts will be removed. Real data stays.</label>
          <button class="btn btn-danger btn-block mt">🗑️ Delete all demo data</button>
        </form>
      <?php endif; ?>
      <p class="small muted mt">Demo items show a <span class="badge b-demo">DEMO</span> label.</p>
    </div>
    <div class="card">
      <h3>✨ AI</h3>
      <p class="muted small">Status: <b><?= ai_enabled() ? 'Connected (' . e(AI_MODEL) . ')' : 'Offline mode' ?></b>.
        <?= ai_enabled() ? 'The order reader, business assistant, daily insights and customer help use Claude AI.' : 'Everything still works with the built-in offline reader and answers. To turn on AI, put your Claude API key in config.php (see README).' ?></p>
      <?php if (ai_enabled() && setting('ai_fail_reason') && (int)setting('ai_fail_until', 0) > time()): ?>
        <p class="small" style="color:var(--red)">Last error: <?= e(setting('ai_fail_reason')) ?></p>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>✉️ SMS</h3>
      <p class="muted small">Status: <b><?= SMS_ENABLED ? 'Enabled' : 'Simulation (logged only)' ?></b>. Configure in <code>config.php</code>. View messages in <a href="<?= e(url('owner/notifications.php?tab=sms')) ?>">SMS log</a>.</p>
    </div>
    <div class="card">
      <h3>🔐 Security</h3>
      <p class="muted small">Change your password and PIN in <a href="<?= e(url('account.php')) ?>">My account</a>. Delete the demo data (or deactivate demo accounts) before real use.</p>
    </div>
  </div>
</div>
<?php layout_end();
