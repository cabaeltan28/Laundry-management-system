<?php
/**
 * Customer order history.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('customer');

$ids = customer_ids_for_user((int)$u['id']);
$orders = [];
$spent = 0.0;
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $orders = q_all("SELECT o.*, s.name AS service_name, s.unit AS service_unit FROM orders o JOIN services s ON s.id = o.service_id
                     WHERE o.customer_id IN ($ph) ORDER BY o.id DESC LIMIT 200", $ids);
    $spent = (float)q_val("SELECT COALESCE(SUM(amount_paid), 0) FROM orders WHERE customer_id IN ($ph) AND status <> 'cancelled'", $ids);
}

layout_start(t('Order history'), 'orders');
?>
<h1><?= e(t('Order history')) ?></h1>
<p class="muted mb"><?= count($orders) ?> order(s) · <?= e(money($spent)) ?> paid in total</p>
<div class="card">
  <?php if (!$orders): ?><?= empty_state('No orders yet') ?><?php endif; ?>
  <?php foreach ($orders as $o): ?>
    <div class="list-item">
      <div class="meta"><b><a href="<?= e(url('customer/order.php?id=' . $o['id'])) ?>"><?= e($o['order_no']) ?></a></b>
        <small class="muted"><?= e(fmt_dt($o['created_at'], 'M j, Y')) ?> · <?= e($o['service_name']) ?> · <?= e(qty_fmt($o['weight'], $o['service_unit'])) ?></small></div>
      <div class="right"><?= status_badge($o['status']) ?><br><small><?= e(money($o['total'])) ?> · <?= pay_badge($o['payment_status']) ?></small></div>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_end();
