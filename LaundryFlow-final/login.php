<?php
require __DIR__ . '/includes/bootstrap.php';

$linkO = strtoupper(trim(get('o', post('o'))));
$linkT = strtoupper(trim(get('t', post('t'))));
if (current_user()) {
    redirect(home_for(current_user()['role']));
}

$error = '';
$identifier = '';
if (is_post()) {
    verify_csrf();
    $identifier = post('identifier');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Enter your username (or email) and password.';
    } elseif (login_locked($identifier)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $user = q_one('SELECT * FROM users WHERE username = ? OR email = ?', [$identifier, $identifier]);
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!(int)$user['is_active']) {
                $error = 'This account is deactivated. Please contact the shop owner.';
            } else {
                clear_failed_logins($identifier);
                login_user($user);
                audit('login', 'user', (int)$user['id'], null, null, null, null, null, (int)$user['id']);
                if ($user['role'] === 'customer' && $linkO !== '') {
                    $r = link_by_tracking($linkO, $linkT, (int)$user['id']);
                    flash($r['ok'] ? 'success' : 'error', $r['msg']);
                }
                redirect(home_for($user['role']));
            }
        } else {
            record_failed_login($identifier);
            $error = 'Incorrect username/email or password.';
        }
    }
}

layout_start(t('Log in'), '');
echo lang_switch();
echo announcement_bar();
?>
<div class="auth-card">
  <a class="brand dark" href="<?= e(url('login.php')) ?>"><span class="logo">🧺</span><span><?= e(shop_name()) ?><small style="color:var(--muted)">LAUNDRY ORDER MONITORING</small></span></a>
  <h2><?= e(t('Welcome back')) ?> 👋</h2>
  <p class="muted"><?= e(t('Log in to track, process or monitor laundry orders.')) ?></p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <?php if ($linkO !== ''): ?><input type="hidden" name="o" value="<?= e($linkO) ?>"><input type="hidden" name="t" value="<?= e($linkT) ?>">
      <div class="alert alert-info">🧺 <?= e(t('After this, order')) ?> <b><?= e($linkO) ?></b> <?= e(t('will be saved to your account.')) ?></div><?php endif; ?>
    <div class="field">
      <label for="identifier"><?= e(t('Username or email')) ?></label>
      <input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" required autofocus>
    </div>
    <div class="field">
      <label for="password"><?= e(t('Password')) ?></label>
      <input type="password" id="password" name="password" required>
    </div>
    <button class="btn btn-block btn-big"><?= e(t('Log in')) ?></button>
  </form>
  <div class="auth-links">
    <?= e(t('New customer?')) ?> <a href="<?= e(url('register.php') . ($linkO !== '' ? '?o=' . rawurlencode($linkO) . '&t=' . rawurlencode($linkT) : '')) ?>"><?= e(t('Create an account')) ?></a><br>
    <?= e(t('Have a claim stub?')) ?> <a href="<?= e(url('track.php')) ?>"><?= e(t('Track an order without logging in')) ?></a>
  </div>
  <div class="demo"><b>Demo accounts</b> (change after first login)<br>
    Owner: owner / owner123 · Staff: jessa / staff123 · Customer: angela / customer123</div>
</div>
<?php layout_end();
