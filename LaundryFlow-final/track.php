<?php
/**
 * Order tracking WITHOUT logging in. Opened by scanning the QR code on the
 * claim stub (or typing the order number + tracking code).
 * Logged-in customers can save the order to their account with one tap.
 */
require __DIR__ . '/includes/bootstrap.php';

$no = strtoupper(trim(get('o', post('o'))));
$code = strtoupper(str_replace(' ', '', get('t', post('t'))));
$me = current_user();

// "Save to my account" (customer already logged in)
if (is_post() && post('action') === 'save' && $me && $me['role'] === 'customer') {
    verify_csrf();
    $r = link_by_tracking($no, $code, (int)$me['id']);
    flash($r['ok'] ? 'success' : 'error', $r['msg']);
    header('Location: ' . url('track.php') . '?o=' . rawurlencode($no) . '&t=' . rawurlencode($code));
    exit;
}

$order = null;
$error = '';
if ($no !== '' || $code !== '') {
    if (login_locked('track:' . client_ip())) {
        $error = t('Too many attempts. Please wait 15 minutes.');
    } else {
        $row = q_one('SELECT id, tracking_token FROM orders WHERE order_no = ?', [$no]);
        if ($row && hash_equals((string)$row['tracking_token'], $code)) {
            $order = get_order((int)$row['id']);
        } else {
            record_failed_login('track:' . client_ip());
            $error = t('Order number and tracking code do not match. Check your claim stub.');
        }
    }
}
$qs = '?o=' . rawurlencode($no) . '&t=' . rawurlencode($code);

layout_start(t('Track your laundry'), 'track', ['refresh' => $order ? 120 : 0]);
if (!$me) { echo lang_switch(); }
echo announcement_bar();
?>
<div class="auth-card">
  <a class="brand dark" href="<?= e(url('login.php')) ?>"><span class="logo">🧺</span><?= e(shop_name()) ?></a>
  <?php if ($order): ?>
    <?php $first = explode(' ', trim($order['customer_name']))[0]; $bal = (float)$order['total'] - (float)$order['amount_paid']; ?>
    <div class="card-head"><div><small class="muted"><?= e(t('ORDER')) ?></small><h2 class="mt0"><?= e($order['order_no']) ?></h2><p class="muted"><?= e(t('Hi')) ?> <?= e($first) ?> · <?= e($order['service_name']) ?></p></div><?= status_badge($order['status']) ?></div>
    <?= order_stepper($order['status']) ?>
    <?php if (!in_array($order['status'], ['claimed', 'cancelled'], true)): ?>
      <div class="eta"><div><small class="muted"><?= e($order['status'] === 'ready' ? t('READY FOR PICKUP') : t('READY BY')) ?></small><br>
        <b><?= $order['status'] === 'ready' ? '✅ ' . e(t('Ready now!')) : e(fmt_when($order['estimated_ready_at'])) ?></b></div>
        <span class="muted small"><?= e(t(STATUS_HINTS[$order['status']])) ?></span></div>
    <?php endif; ?>
    <div class="info-grid">
      <div class="info"><small><?= e(t('Qty')) ?></small><b><?= e(qty_fmt($order['weight'], $order['service_unit'])) ?></b><span><?= (int)$order['bag_count'] ?> <?= e(t('bag(s)')) ?></span></div>
      <div class="info"><small><?= e(t('Payment')) ?></small><b><?= pay_badge($order['payment_status']) ?></b><span><?= $bal > 0 ? e(t('To pay at pickup')) . ' ' . e(money($bal)) : 'Total ' . e(money($order['total'])) ?></span></div>
    </div>

    <?php if ($me && $me['role'] === 'customer'): ?>
      <?php if ((int)$order['customer_user_id'] === (int)$me['id']): ?>
        <div class="alert alert-success mt">✅ <?= e(t('This order is in your account.')) ?> <a href="<?= e(url('customer/order.php?id=' . $order['id'])) ?>"><?= e(t('Open')) ?></a></div>
      <?php elseif (!$order['customer_user_id']): ?>
        <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="o" value="<?= e($no) ?>"><input type="hidden" name="t" value="<?= e($code) ?>">
          <button class="btn btn-big btn-block">💾 <?= e(t('Save to my account')) ?></button></form>
      <?php endif; ?>
    <?php elseif (!$me): ?>
      <div class="card mt" style="background:var(--brand-soft);border:0">
        <b>💾 <?= e(t('Want updates on your phone?')) ?></b>
        <p class="small muted"><?= e(t('Log in or create a free account — this order is added automatically.')) ?></p>
        <div class="btn-row"><a class="btn" href="<?= e(url('register.php') . $qs) ?>"><?= e(t('Create account')) ?></a><a class="btn btn-outline" href="<?= e(url('login.php') . $qs) ?>"><?= e(t('Log in')) ?></a></div>
      </div>
    <?php endif; ?>

    <?php if ($order['status'] !== 'claimed'): $wl = workload(); ?>
      <p class="muted small mt"><?= e(t('Shop workload')) ?>: <span class="pill wl-<?= e(strtolower($wl['level'])) ?>"><?= e(t($wl['level'])) ?></span> · <?= e(setting('shop_address', '')) ?></p>
    <?php endif; ?>
    <p class="small muted"><?= e(t('This page updates by itself. Show your pickup code at the counter to claim.')) ?></p>
    <?php if (!$me || $me['role'] === 'customer'): ?><a class="btn btn-ghost btn-block" href="<?= e(url('track.php')) ?>"><?= e(t('Track another order')) ?></a><?php endif; ?>
  <?php else: ?>
    <h2>🧺 <?= e(t('Track your laundry')) ?></h2>
    <p class="muted"><?= e(t('Scan the QR code on your claim stub — or type the order number and tracking code below.')) ?></p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="get">
      <div class="field"><label><?= e(t('Order number')) ?></label><input type="text" name="o" value="<?= e($no) ?>" placeholder="LF-10482" required></div>
      <div class="field"><label><?= e(t('Tracking code')) ?></label><input type="text" name="t" value="<?= e($code) ?>" placeholder="ABCD2345" required class="mono"></div>
      <button class="btn btn-block btn-big"><?= e(t('Track order')) ?></button>
    </form>
    <p class="small muted mt">🔒 <?= e(t('Keep your tracking code private.')) ?></p>
  <?php endif; ?>
  <div class="auth-links"><a href="<?= e(url('login.php')) ?>"><?= e(t('Log in')) ?></a> · <a href="<?= e(url('register.php')) ?>"><?= e(t('Create account')) ?></a></div>
</div>
<?php layout_end();
