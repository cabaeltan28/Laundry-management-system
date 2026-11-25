<?php
/**
 * My account (all roles): profile, colors, password, PIN (staff),
 * and for customers: bring orders made at the counter into the account.
 */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$me = q_one('SELECT * FROM users WHERE id = ?', [$u['id']]);

if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        if ($action === 'profile') {
            $name = post('full_name');
            $phone = normalize_phone(post('phone'));
            $email = post('email');
            if (mb_strlen($name) < 3) throw new RuntimeException(t('Enter your full name.'));
            if (!$phone) throw new RuntimeException(t('Mobile number is not valid (example: 0917 123 4567).'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('Enter a valid email.'));
            if ($email !== '' && q_val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) throw new RuntimeException(t('That email is already used.'));
            q('UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?', [$name, $phone, $email !== '' ? $email : null, $u['id']]);
            if ($u['role'] === 'customer') {
                q('UPDATE customers SET full_name = ?, phone = ?, email = ? WHERE user_id = ? AND created_by = ?', [$name, $phone, $email !== '' ? $email : null, $u['id'], $u['id']]);
            }
            audit('customer_updated', 'user', (int)$u['id'], null, 'profile', $me['full_name'] . ' / ' . $me['phone'], $name . ' / ' . $phone);
            flash('success', t('Profile saved.'));
        } elseif ($action === 'theme') {
            if (setting('allow_user_theme', '1') !== '1') throw new RuntimeException(t('The owner has set the colors for everyone.'));
            $theme = post('theme');
            if ($theme !== '' && !isset(THEMES[$theme])) throw new RuntimeException(t('Choose a color.'));
            q('UPDATE users SET theme = ? WHERE id = ?', [$theme !== '' ? $theme : null, $u['id']]);
            flash('success', t('Colors saved.'));
        } elseif ($action === 'password') {
            $cur = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            if (!password_verify($cur, $me['password_hash'])) throw new RuntimeException(t('Current password is incorrect.'));
            if ($p = password_problem($new)) throw new RuntimeException($p);
            if ($new !== ($_POST['confirm'] ?? '')) throw new RuntimeException(t('New passwords do not match.'));
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            audit('password_changed', 'user', (int)$u['id']);
            flash('success', t('Password changed.'));
        } elseif ($action === 'pin' && $u['role'] !== 'customer') {
            $pin = post('pin');
            if (!preg_match('/^\d{4,6}$/', $pin)) throw new RuntimeException(t('PIN must be 4–6 digits.'));
            if (!password_verify($_POST['current'] ?? '', $me['password_hash'])) throw new RuntimeException(t('Current password is incorrect.'));
            q('UPDATE users SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
            audit('password_changed', 'user', (int)$u['id'], null, 'pin');
            flash('success', t('PIN saved.'));
        } elseif ($action === 'link_phone' && $u['role'] === 'customer') {
            $r = link_by_phone_and_code($me, post('code'));
            flash($r['ok'] ? 'success' : 'error', $r['msg']);
        } elseif ($action === 'link' && $u['role'] === 'customer') {
            if (login_locked('link:' . $u['id'])) throw new RuntimeException(t('Too many attempts. Try again in 15 minutes.'));
            $r = link_by_tracking(post('order_no'), post('code'), (int)$u['id']);
            if (!$r['ok']) record_failed_login('link:' . $u['id']);
            flash($r['ok'] ? 'success' : 'error', $r['msg']);
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(post('return') === 'customer/dashboard.php' ? 'customer/dashboard.php' : 'account.php');
}

$found = $u['role'] === 'customer' ? unlinked_records_for_phone($me['phone']) : [];
$foundOrders = array_sum(array_column($found, 'n'));
$canTheme = setting('allow_user_theme', '1') === '1';

layout_start(t('My account'), 'account');
?>
<div class="grid g2">
  <div class="stack">
    <?php if ($u['role'] === 'customer'): ?>
    <div class="card link-card">
      <h3>🧺 <?= e(t('Add your counter orders to this account')) ?></h3>
      <p class="small"><b><?= e(t('Easiest way:')) ?></b> <?= e(t('scan the QR code on your claim stub with your phone camera, then tap "Save to my account".')) ?></p>
      <?php if ($foundOrders > 0): ?>
        <div class="alert alert-success mt">🔎 <?= e(t('We found')) ?> <b><?= (int)$foundOrders ?></b> <?= e(t('order(s) made at the counter with your number')) ?> <?= e(mask_phone($me['phone'])) ?>.</div>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="link_phone">
          <div class="field"><label><?= e(t('Type the 4-digit pickup code from ANY of your claim stubs or SMS')) ?></label>
            <input type="text" name="code" inputmode="numeric" maxlength="6" class="code-input" placeholder="0000" required></div>
          <button class="btn btn-big btn-block">✅ <?= e(t('Add them to my account')) ?></button>
        </form>
      <?php else: ?>
        <p class="small muted"><?= e(t('Orders made at the counter with your mobile number will appear here automatically.')) ?></p>
      <?php endif; ?>
      <details class="inline-form"><summary><?= e(t('Other way: type the order number and tracking code')) ?></summary>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="link">
          <div class="row">
            <div class="field"><label><?= e(t('Order number')) ?></label><input type="text" name="order_no" placeholder="LF-10482" required></div>
            <div class="field"><label><?= e(t('Tracking code')) ?></label><input type="text" name="code" class="mono" required></div>
          </div>
          <button class="btn btn-outline"><?= e(t('Add order')) ?></button>
        </form>
      </details>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3>🙂 <?= e(t('Profile')) ?></h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="field"><label><?= e(t('Full name')) ?></label><input type="text" name="full_name" value="<?= e($me['full_name']) ?>" required></div>
        <div class="field"><label><?= e(t('Mobile number')) ?></label><input type="tel" name="phone" value="<?= e($me['phone']) ?>" required></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($me['email']) ?>"></div>
        <p class="small muted"><?= e(t('Username')) ?>: <b><?= e($me['username']) ?></b></p>
        <button class="btn"><?= e(t('Save profile')) ?></button>
      </form>
    </div>
  </div>

  <div class="stack">
    <?php if ($canTheme): ?>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="theme">
      <h3>🎨 <?= e(t('My colors')) ?></h3>
      <div class="choice-grid">
        <label class="choice"><input type="radio" name="theme" value="" <?= empty($me['theme']) ? 'checked' : '' ?>>
          <b><span class="swatch" style="background:<?= e(theme_colors((string)setting('theme', 'pink'))[1]) ?>"></span> <?= e(t('Shop default')) ?></b></label>
        <?php foreach (THEMES as $key => $th): ?>
          <label class="choice"><input type="radio" name="theme" value="<?= e($key) ?>" <?= ($me['theme'] ?? '') === $key ? 'checked' : '' ?>>
            <b><span class="swatch" style="background:<?= e($th[1]) ?>"></span> <?= e($th[0]) ?></b></label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-outline mt"><?= e(t('Save colors')) ?></button>
    </form>
    <?php endif; ?>

    <div class="card">
      <h3>🔑 <?= e(t('Change password')) ?></h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="field"><label><?= e(t('Current password')) ?></label><input type="password" name="current" required></div>
        <div class="row">
          <div class="field"><label><?= e(t('New password')) ?></label><input type="password" name="new" required><div class="hint"><?= e(t('8+ characters, letters and numbers')) ?></div></div>
          <div class="field"><label><?= e(t('Confirm new password')) ?></label><input type="password" name="confirm" required></div>
        </div>
        <button class="btn btn-outline"><?= e(t('Change password')) ?></button>
      </form>
    </div>
    <?php if ($u['role'] !== 'customer'): ?>
    <div class="card">
      <h3>🔢 <?= e(t('Quick-switch PIN')) ?></h3>
      <p class="muted small"><?= e(t('Used to switch users on a shared counter computer. Every action is still recorded under your name.')) ?></p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="pin">
        <div class="row">
          <div class="field"><label><?= e(t('New PIN (4–6 digits)')) ?></label><input type="password" name="pin" inputmode="numeric" required></div>
          <div class="field"><label><?= e(t('Current password')) ?></label><input type="password" name="current" required></div>
        </div>
        <button class="btn btn-outline"><?= e(t('Save PIN')) ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
