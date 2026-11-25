<?php
require __DIR__ . '/includes/bootstrap.php';

$linkO = strtoupper(trim(get('o', post('o'))));
$linkT = strtoupper(trim(get('t', post('t'))));
if (current_user()) {
    redirect(home_for(current_user()['role']));
}

$errors = [];
$v = ['full_name' => '', 'phone' => '', 'email' => '', 'username' => ''];
if (is_post()) {
    verify_csrf();
    foreach ($v as $k => $_) {
        $v[$k] = post($k);
    }
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $phone = normalize_phone($v['phone']);

    if (mb_strlen($v['full_name']) < 3) $errors[] = t('Enter your full name.');
    if (!$phone) $errors[] = t('Enter a valid mobile number (e.g. 0917 123 4567).');
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Enter a valid email address or leave it blank.');
    if (!preg_match('/^[A-Za-z0-9_.]{4,30}$/', $v['username'])) $errors[] = t('Username must be 4–30 letters, numbers, dots or underscores.');
    if ($p = password_problem($password)) $errors[] = $p;
    if ($password !== $confirm) $errors[] = t('Passwords do not match.');
    if (!$errors && q_val('SELECT COUNT(*) FROM users WHERE username = ?', [$v['username']])) $errors[] = t('That username is already taken.');
    if (!$errors && $v['email'] !== '' && q_val('SELECT COUNT(*) FROM users WHERE email = ?', [$v['email']])) $errors[] = t('That email is already registered.');

    if (!$errors) {
        $uid = tx(function () use ($v, $phone, $password) {
            q('INSERT INTO users (role, full_name, username, email, phone, password_hash, is_active, created_at) VALUES (?,?,?,?,?,?,1,?)',
                ['customer', $v['full_name'], $v['username'], $v['email'] !== '' ? $v['email'] : null, $phone, password_hash($password, PASSWORD_DEFAULT), now()]);
            $uid = (int)db()->lastInsertId();
            q('INSERT INTO customers (user_id, full_name, phone, email, created_by, created_at) VALUES (?,?,?,?,?,?)',
                [$uid, $v['full_name'], $phone, $v['email'] !== '' ? $v['email'] : null, $uid, now()]);
            audit('account_registered', 'user', $uid, null, null, null, $v['username'], null, $uid);
            return $uid;
        });
        login_user(q_one('SELECT * FROM users WHERE id = ?', [$uid]));
        flash('success', t('Welcome! Your account is ready.'));
        if ($linkO !== '') {
            $r = link_by_tracking($linkO, $linkT, $uid);
            flash($r['ok'] ? 'success' : 'error', $r['msg']);
        }
        redirect('customer/dashboard.php');
    }
}

layout_start(t('Create account'), '');
echo lang_switch();
?>
<div class="auth-card">
  <a class="brand dark" href="<?= e(url('login.php')) ?>"><span class="logo">🧺</span><?= e(shop_name()) ?></a>
  <h2><?= e(t('Create a customer account')) ?></h2>
  <p class="muted"><?= e(t('Track your laundry, see ready times and get pickup reminders.')) ?></p>
  <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <?php if ($linkO !== ''): ?><input type="hidden" name="o" value="<?= e($linkO) ?>"><input type="hidden" name="t" value="<?= e($linkT) ?>">
      <div class="alert alert-info">🧺 <?= e(t('After this, order')) ?> <b><?= e($linkO) ?></b> <?= e(t('will be saved to your account.')) ?></div><?php endif; ?>
    <div class="field"><label><?= e(t('Full name')) ?></label><input type="text" name="full_name" value="<?= e($v['full_name']) ?>" required></div>
    <div class="row">
      <div class="field"><label><?= e(t('Mobile number')) ?></label><input type="tel" name="phone" value="<?= e($v['phone']) ?>" placeholder="09XX XXX XXXX" required></div>
      <div class="field"><label><?= e(t('Email (optional)')) ?></label><input type="email" name="email" value="<?= e($v['email']) ?>"></div>
    </div>
    <div class="field"><label><?= e(t('Username')) ?></label><input type="text" name="username" value="<?= e($v['username']) ?>" required></div>
    <div class="row">
      <div class="field"><label><?= e(t('Password')) ?></label><input type="password" name="password" required><div class="hint"><?= e(t('8+ characters, letters and numbers')) ?></div></div>
      <div class="field"><label><?= e(t('Confirm password')) ?></label><input type="password" name="confirm" required></div>
    </div>
    <button class="btn btn-block btn-big"><?= e(t('Create account')) ?></button>
  </form>
  <div class="auth-links"><?= e(t('Already have an account?')) ?> <a href="<?= e(url('login.php') . ($linkO !== '' ? '?o=' . rawurlencode($linkO) . '&t=' . rawurlencode($linkT) : '')) ?>"><?= e(t('Log in')) ?></a></div>
</div>
<?php layout_end();
