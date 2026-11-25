<?php
/**
 * Ready-for-pickup list. Type the pickup code and release in one step
 * (the unpaid balance is recorded at the same time).
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role(['employee', 'owner']);
run_pickup_reminders();

$rows = q_all("SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone, s.name AS service_name, s.unit AS service_unit
               FROM orders o JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id
               WHERE o.status = 'ready' ORDER BY o.ready_at ASC");
$claimedToday = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'claimed' AND claimed_at >= ?", [date('Y-m-d 00:00:00', clock())]);
$over24 = 0;
foreach ($rows as $r) {
    if (strtotime($r['ready_at']) < clock() - 86400) $over24++;
}

layout_start(t('Ready for pickup'), 'pickups', [
    'refresh' => 120,
    'subtitle' => count($rows) . ' ' . t('waiting') . ' · ' . $over24 . ' ' . t('over 24 hours') . ' · ' . $claimedToday . ' ' . t('released today'),
]);
?>
<div class="alert alert-info">💡 <?= e(t('Ask the customer for the 4-digit PICKUP CODE. Type it, choose how they paid, then press RELEASE.')) ?></div>
<div class="card">
  <?php if (!$rows): ?>
    <?= empty_state("You're all caught up", 'No orders are waiting for pickup.') ?>
  <?php endif; ?>
  <?php foreach ($rows as $o): $bal = round((float)$o['total'] - (float)$o['amount_paid'], 2); $waitH = (clock() - strtotime($o['ready_at'])) / 3600; ?>
    <div class="list-item" id="o<?= (int)$o['id'] ?>" style="flex-wrap:wrap">
      <div class="meta" style="min-width:220px;flex:1">
        <b style="font-size:16px"><?= e($o['customer_name']) ?></b>
        <small class="muted"><a href="<?= e(url('staff/order.php?id=' . $o['id'])) ?>"><?= e($o['order_no']) ?></a> · <?= e($o['service_name']) ?> · <?= e(qty_fmt($o['weight'], $o['service_unit'])) ?> · <?= (int)$o['bag_count'] ?> <?= e(t('bag(s)')) ?>
          · <?= e(t('Ready')) ?> <?= e(fmt_when($o['ready_at'])) ?>
          <?= $waitH >= 24 ? ' · <b style="color:var(--red)">' . (int)floor($waitH / 24) . ' ' . e(t('day(s) waiting')) . '</b>' : '' ?></small>
      </div>
      <form method="post" action="<?= e(url('staff/order_action.php')) ?>" class="btn-row">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="pay_claim"><input type="hidden" name="return" value="staff/pickups.php">
        <span class="badge <?= $bal > 0 ? 'pay-unpaid' : 'pay-paid' ?>" style="font-size:14px"><?= $bal > 0 ? e(t('To pay')) . ' ' . e(money($bal)) : e(t('Paid')) ?></span>
        <?php if ($bal > 0): ?>
          <select name="method" style="width:auto" aria-label="<?= e(t('Paid with')) ?>"><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select>
          <input type="text" name="reference" placeholder="Ref #" style="width:110px" aria-label="<?= e(t('Reference no. (GCash/Maya/Bank)')) ?>">
        <?php else: ?><input type="hidden" name="method" value="cash"><?php endif; ?>
        <input type="text" name="pickup_code" placeholder="<?= e(t('Code')) ?>" inputmode="numeric" maxlength="6" required style="width:110px" class="mono" aria-label="<?= e(t('Pickup code')) ?>">
        <button class="btn">✅ <?= e(t('Release')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_end();
