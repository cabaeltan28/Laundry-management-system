<?php
/**
 * PIN quick-switch for a shared counter computer. Each employee's actions
 * are still recorded under their own name.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

$staff = q_all("SELECT id, full_name, role FROM users WHERE role IN ('employee','owner') AND is_active = 1 AND pin_hash IS NOT NULL ORDER BY full_name");

if (is_post()) {
    verify_csrf();
    $target = q_one("SELECT * FROM users WHERE id = ? AND role IN ('employee','owner') AND is_active = 1", [(int)post('user_id')]);
    $key = 'pin:' . (int)post('user_id');
    if (login_locked($key)) {
        flash('error', 'Too many wrong PINs for this user. Wait 15 minutes or log in with password.');
    } elseif ($target && $target['pin_hash'] && password_verify(post('pin'), $target['pin_hash'])) {
        clear_failed_logins($key);
        $from = $u['full_name'];
        login_user($target);
        audit('user_switched', 'user', (int)$target['id'], null, null, $from, $target['full_name'], null, (int)$target['id']);
        flash('success', t('Hello') . ', ' . $target['full_name'] . '!');
        redirect(home_for($target['role']));
    } else {
        record_failed_login($key);
        flash('error', t('Wrong PIN.'));
    }
    redirect('staff/switch.php');
}

layout_start(t('Switch user'), 'switch', ['subtitle' => t('Signed in as') . ' ' . $u['full_name']]);
?>
<div class="card" style="max-width:520px">
  <h3>🔁 <?= e(t('Switch user with PIN')) ?></h3>
  <p class="muted small"><?= e(t('Choose your name and type your PIN. Everything you do is saved under your name.')) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label><?= e(t('Your name')) ?></label>
      <select name="user_id" required>
        <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$u['id'] ? 'disabled' : '' ?>><?= e($s['full_name']) ?><?= $s['role'] === 'owner' ? ' (owner)' : '' ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>PIN</label><input type="password" name="pin" inputmode="numeric" maxlength="6" required class="mono"></div>
    <button class="btn btn-big"><?= e(t('Switch')) ?></button>
  </form>
  <p class="small muted mt">No PIN yet? Set one in <a href="<?= e(url('account.php')) ?>">My account</a>.</p>
</div>
<?php layout_end();
