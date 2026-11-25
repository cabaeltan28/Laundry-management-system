<?php
/**
 * Customer order detail. Customers only see their own orders.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('customer');

$o = get_order((int)get('id'));
if (!$o || (int)$o['customer_user_id'] !== (int)$u['id']) {
    http_response_code(404);
    flash('error', 'Order not found.');
    redirect('customer/orders.php');
}
$timeline = order_timeline((int)$o['id']);
$payments = q_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$o['id']]);
$changes = q_all("SELECT a.*, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                  WHERE a.order_id = ? AND a.action IN ('field_updated','total_recalculated','payment_voided') AND (a.field IS NULL OR a.field <> 'notes')
                  ORDER BY a.id", [$o['id']]);
$bal = (float)$o['total'] - (float)$o['amount_paid'];
$unit = $o['service_unit'];

layout_start($o['order_no'], 'orders', ['refresh' => in_array($o['status'], ['claimed', 'cancelled'], true) ? 0 : 120]);
?>
<p><a href="<?= e(url('customer/dashboard.php')) ?>">← <?= e(t('Back')) ?></a></p>
<div class="card mb">
  <div class="card-head"><div><small class="muted">ORDER</small><h2 class="mt0"><?= e($o['order_no']) ?></h2><p class="muted">Dropped off <?= e(fmt_dt($o['created_at'], 'M j, Y g:i A')) ?></p></div>
    <div><?= status_badge($o['status']) ?> <?= $o['is_rush'] ? '<span class="badge b-rush">RUSH</span>' : '' ?></div></div>
  <?= order_stepper($o['status']) ?>
  <?php if (!in_array($o['status'], ['claimed', 'cancelled'], true)): ?>
    <div class="eta"><div><small class="muted"><?= $o['status'] === 'ready' ? 'READY FOR PICKUP' : 'ESTIMATED READY' ?></small><br>
      <b><?= $o['status'] === 'ready' ? 'Since ' . e(fmt_when($o['ready_at'])) : e(fmt_when($o['estimated_ready_at'])) ?></b></div>
      <div class="right"><small class="muted">PICKUP CODE</small><br><b class="mono" style="font-size:20px"><?= e($o['pickup_code']) ?></b></div></div>
  <?php endif; ?>
  <p class="small muted"><?= e(STATUS_HINTS[$o['status']] ?? '') ?></p>
</div>

<div class="grid g2 mb">
  <div class="card">
    <h3><?= e(t('Price breakdown')) ?></h3>
    <div class="list-item"><span><?= e($o['service_name']) ?></span><span><?= e(money($o['unit_price'])) ?> / <?= $unit === 'kg' ? 'kg' : 'pc' ?></span></div>
    <div class="list-item"><span><?= $unit === 'kg' ? 'Weight' : 'Quantity' ?></span><span><?= e(qty_fmt($o['weight'], $unit)) ?></span></div>
    <?php if ((float)$o['weight'] > 0 && (float)$o['weight'] < (float)$o['service_min_qty']): ?>
      <div class="list-item"><span>Minimum charge</span><span><?= e(qty_fmt($o['service_min_qty'], $unit)) ?></span></div>
    <?php endif; ?>
    <div class="list-item"><span>Subtotal</span><span><?= e(money($o['subtotal'])) ?></span></div>
    <?php $pr = calc_price(['price' => $o['unit_price'], 'min_qty' => $o['service_min_qty']], (float)$o['weight'], (bool)$o['is_rush'], (float)$o['discount_percent'], (float)$o['rush_percent']); ?>
    <?php if ((float)$o['rush_percent'] > 0): ?><div class="list-item"><span>⚡ Rush (+<?= e((float)$o['rush_percent']) ?>%)</span><span><?= e(money($pr['rush_amount'])) ?></span></div><?php endif; ?>
    <?php if ((float)$o['discount_percent'] > 0): ?><div class="list-item"><span>🎁 <?= e(t('Loyalty discount')) ?> (−<?= e((float)$o['discount_percent']) ?>%)</span><span>−<?= e(money($pr['discount_amount'])) ?></span></div><?php endif; ?>
    <div class="list-item"><b>Total</b><b><?= e(money($o['total'])) ?></b></div>
    <div class="list-item"><span>Paid</span><span><?= e(money($o['amount_paid'])) ?></span></div>
    <div class="list-item"><b>Balance</b><b style="color:<?= $bal > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= e(money(max(0, $bal))) ?></b></div>
  </div>
  <div class="card">
    <h3><?= e(t('Progress')) ?></h3>
    <ul class="timeline">
      <?php foreach ($timeline as $t): ?>
        <li><span class="tdot">✓</span><div><b><?= e(status_label($t['status'])) ?></b></div><small><?= e(fmt_dt($t['created_at'], 'M j g:i A')) ?></small></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<div class="card mb">
  <h3><?= e(t('Payments')) ?></h3>
  <?php if (!$payments): ?><p class="muted">No payments yet. You can pay at pickup.</p><?php endif; ?>
  <?php foreach ($payments as $p): ?>
    <div class="list-item"><div class="meta"><b><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></b><small class="muted"><?= e(fmt_dt($p['created_at'])) ?><?= $p['reference_no'] ? ' · Ref ' . e($p['reference_no']) : '' ?></small></div>
      <span><?= $p['is_void'] ? '<s>' . e(money($p['amount'])) . '</s> <span class="badge st-cancelled">Voided</span>' : e(money($p['amount'])) ?></span></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3><?= e(t('Change history')) ?></h3>
  <p class="muted small">For transparency, every change to your weight, service or price is shown here with the reason.</p>
  <?php if (!$changes): ?><p class="muted">No changes — your order is exactly as recorded at drop-off.</p><?php endif; ?>
  <?php foreach ($changes as $c): ?>
    <div class="activity"><div><b class="small"><?= e(audit_action_label($c['action'])) ?><?= $c['field'] ? ' · ' . e($c['field']) : '' ?>: <?= e($c['old_value']) ?> → <?= e($c['new_value']) ?></b><br>
      <small class="muted"><?= e($c['reason']) ?> · by <?= e(explode(' ', (string)$c['actor_name'])[0] ?: 'System') ?></small></div><span class="when"><?= e(fmt_dt($c['created_at'])) ?></span></div>
  <?php endforeach; ?>
</div>
<?php layout_end();
