<?php
/**
 * Printable claim stub (press Ctrl+P). Contains the pickup code and the
 * private tracking code for online tracking.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role(['employee', 'owner']);

$o = get_order((int)get('id'));
if (!$o) {
    flash('error', 'Order not found.');
    redirect('staff/orders.php');
}
$payments = q_all('SELECT * FROM payments WHERE order_id = ? AND is_void = 0 ORDER BY id', [$o['id']]);
$balance = (float)$o['total'] - (float)$o['amount_paid'];
$trackUrl = track_url($o);

layout_start(t('Claim stub') . ' ' . $o['order_no'], 'orders', [
    'actions' => '<a class="btn btn-outline" href="' . e(url('staff/order.php?id=' . $o['id'])) . '">' . e(t('Open order')) . '</a> <a class="btn" href="' . e(url('staff/new_order.php')) . '">➕ ' . e(t('New order')) . '</a>',
    'subtitle' => t('Press Ctrl + P to print'),
]);
?>
<div class="no-print card mb center">
  <p style="font-size:17px"><b>🖨️ <?= e(t('Press Ctrl + P to print this claim stub.')) ?></b></p>
  <p class="muted"><?= e(t('Tell the customer the PICKUP CODE. They need it to get their laundry.')) ?></p>
  <?php if (PUBLIC_URL === '' && preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '')): ?>
    <p class="small" style="color:var(--amber)">ℹ️ The QR code points to "localhost", which phones cannot open. Set PUBLIC_URL in config.php (see README) so customers can scan it.</p>
  <?php endif; ?>
</div>
<div class="stub">
  <h2><?= e(shop_name()) ?></h2>
  <p class="center small muted"><?= e(setting('shop_branch', '')) ?> · <?= e(setting('shop_address', '')) ?><br><?= e(setting('shop_phone', '')) ?></p>
  <hr>
  <div class="line"><span>Order no.</span><b><?= e($o['order_no']) ?></b></div>
  <div class="line"><span>Date</span><span><?= e(fmt_dt($o['created_at'], 'M j, Y g:i A')) ?></span></div>
  <div class="line"><span>Customer</span><span><?= e($o['customer_name']) ?></span></div>
  <div class="line"><span>Service</span><span><?= e($o['service_name']) ?><?= $o['is_rush'] ? ' (RUSH)' : '' ?></span></div>
  <div class="line"><span>Weight / qty</span><span><?= e(qty_fmt($o['weight'], $o['service_unit'])) ?> · <?= (int)$o['bag_count'] ?> bag(s)</span></div>
  <?php if ($o['notes']): ?><div class="line"><span>Notes</span><span><?= e($o['notes']) ?></span></div><?php endif; ?>
  <hr>
  <?php if ((float)$o['rush_percent'] > 0): ?><div class="line"><span>Rush (+<?= e((float)$o['rush_percent']) ?>%)</span><span>included</span></div><?php endif; ?>
  <?php if ((float)$o['discount_percent'] > 0): ?><div class="line"><span>🎁 Loyalty (−<?= e((float)$o['discount_percent']) ?>%)</span><span>included</span></div><?php endif; ?>
  <div class="line"><span>Total</span><b><?= e(money($o['total'])) ?></b></div>
  <?php foreach ($payments as $p): ?>
    <div class="line"><span>Paid (<?= e(PAYMENT_METHODS[$p['method']]) ?>)</span><span><?= e(money($p['amount'])) ?></span></div>
  <?php endforeach; ?>
  <div class="line"><span>Balance</span><b><?= e(money(max(0, $balance))) ?></b></div>
  <hr>
  <div class="line"><span>Estimated ready</span><b><?= e(fmt_when($o['estimated_ready_at'])) ?></b></div>
  <p class="center mt0"><small class="muted">PICKUP CODE</small><br><span class="code-box"><?= e($o['pickup_code']) ?></span></p>
  <div class="qr-box"><?= qr_svg($trackUrl, 120) ?>
    <p><b>📱 Scan to track your laundry</b><br>Open your phone camera and point it at the code. You can save the order to your account.<br>
      <span class="muted">Order <?= e($o['order_no']) ?> · Code <b class="mono"><?= e($o['tracking_token']) ?></b></span></p></div>
  <hr>
  <p class="center small muted">Present this stub or your pickup code when claiming. Unclaimed laundry reminders are sent by SMS. Keep your codes private.</p>
</div>
<?php layout_end();
