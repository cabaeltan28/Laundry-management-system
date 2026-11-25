<?php
/**
 * Customer home: active orders with progress, ready time, pickup code,
 * payment, loyalty stamps and shop workload.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('customer');

$ids = customer_ids_for_user((int)$u['id']);
$active = [];
$latest = null;
$historyCount = 0;
$done = 0;
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $active = q_all("SELECT o.*, s.name AS service_name, s.unit AS service_unit FROM orders o JOIN services s ON s.id = o.service_id
                     WHERE o.customer_id IN ($ph) AND o.status NOT IN ('claimed','cancelled') ORDER BY o.status = 'ready' DESC, o.id DESC", $ids);
    $latest = q_one("SELECT * FROM notifications WHERE customer_id IN ($ph) AND channel = 'in_app' ORDER BY id DESC LIMIT 1", $ids);
    $historyCount = (int)q_val("SELECT COUNT(*) FROM orders WHERE customer_id IN ($ph) AND status IN ('claimed','cancelled')", $ids);
    $done = (int)q_val("SELECT COUNT(*) FROM orders WHERE customer_id IN ($ph) AND status <> 'cancelled'", $ids);
}
$wl = workload();
$first = explode(' ', trim($u['full_name']))[0];
$every = (int)setting('loyalty_every', 0);
$loyalPct = (float)setting('loyalty_discount_percent', 0);

layout_start(t('Track'), 'dashboard', ['refresh' => 120]);
?>
<h1 class="hello"><?= e(t('Hi')) ?>, <?= e($first) ?> 👋</h1>
<p class="muted mb"><?= e($active ? t('Your laundry is moving right along.') : t('You have no laundry in progress right now.')) ?></p>

<?php $found = array_sum(array_column(unlinked_records_for_phone($u['phone']), 'n')); if ($found > 0): ?>
  <div class="card link-card mb">
    <b>🔎 <?= e(t('We found')) ?> <?= (int)$found ?> <?= e(t('order(s) made at the counter with your number')) ?> <?= e(mask_phone($u['phone'])) ?>.</b>
    <p class="small muted"><?= e(t('Type the 4-digit pickup code from ANY of your claim stubs or SMS')) ?>:</p>
    <form method="post" action="<?= e(url('account.php')) ?>" class="search-bar">
      <?= csrf_field() ?><input type="hidden" name="action" value="link_phone"><input type="hidden" name="return" value="customer/dashboard.php">
      <input type="text" name="code" inputmode="numeric" maxlength="6" class="code-input" placeholder="0000" required aria-label="<?= e(t('Pickup code')) ?>">
      <button class="btn btn-big"><?= e(t('Add them to my account')) ?></button>
    </form>
  </div>
<?php endif; ?>

<?php foreach ($active as $o): $bal = (float)$o['total'] - (float)$o['amount_paid']; ?>
  <div class="card mb">
    <div class="card-head">
      <div><small class="muted"><?= e(t('ORDER')) ?></small><h2 class="mt0"><a href="<?= e(url('customer/order.php?id=' . $o['id'])) ?>"><?= e($o['order_no']) ?></a></h2>
        <p class="muted"><?= e($o['service_name']) ?> · <?= e(qty_fmt($o['weight'], $o['service_unit'])) ?> · <?= (int)$o['bag_count'] ?> <?= e(t('bag(s)')) ?></p></div>
      <div><?= status_badge($o['status']) ?> <?= $o['is_rush'] ? '<span class="badge b-rush">⚡ RUSH</span>' : '' ?></div>
    </div>
    <?= order_stepper($o['status']) ?>
    <div class="eta">
      <div><small class="muted"><?= e($o['status'] === 'ready' ? t('READY FOR PICKUP') : t('READY BY')) ?></small><br>
        <b><?= $o['status'] === 'ready' ? '✅ ' . e(t('Ready now!')) : e(fmt_when($o['estimated_ready_at'])) ?></b></div>
      <?php if ($o['status'] !== 'ready'): $hrs = (strtotime($o['estimated_ready_at']) - clock()) / 3600; ?>
        <b class="small"><?= $hrs > 0 ? '~' . e(ceil($hrs)) . ' ' . e(t('hrs')) : e(t('any moment now')) ?></b>
      <?php else: ?>
        <span class="right"><small class="muted"><?= e(t('PICKUP CODE')) ?></small><br><b class="mono" style="font-size:24px;letter-spacing:4px"><?= e($o['pickup_code']) ?></b></span>
      <?php endif; ?>
    </div>
    <div class="info-grid">
      <div class="info"><small><?= e(t('Payment')) ?></small><b><?= pay_badge($o['payment_status']) ?> <?= e(money($o['total'])) ?></b><span><?= $bal > 0 ? e(t('To pay at pickup')) . ': ' . e(money($bal)) : e(t('Fully paid')) ?></span></div>
      <div class="info"><small><?= e(t('Pickup at')) ?></small><b><?= e(shop_name()) ?></b><span><?= e(setting('shop_address', '')) ?></span></div>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$active): ?>
  <div class="card mb"><?= empty_state('No active orders', 'Drop off your laundry at the shop. If you have a claim stub, link it in your Account page.') ?>
    <div class="center"><a class="btn btn-outline" href="<?= e(url('account.php')) ?>"><?= e(t('Link an order')) ?></a></div></div>
<?php endif; ?>

<?php if ($every >= 2 && $loyalPct > 0): $stamp = $done % $every; ?>
  <div class="loyal mb">
    <b>🎁 <?= e(t('Loyalty card')) ?></b> · <span class="small muted"><?= e(t('Every')) ?> <?= $every ?><?= e(t('th order')) ?>: <?= e((float)$loyalPct) ?>% <?= e(t('off')) ?></span>
    <div class="stamps"><?php for ($i = 1; $i <= $every; $i++): ?><span class="<?= $i <= $stamp ? 'on' : '' ?>"><?= $i === $every ? '🎁' : ($i <= $stamp ? '✓' : $i) ?></span><?php endfor; ?></div>
    <small class="muted"><?= ($every - $stamp - 1) === 0 ? e(t('Your NEXT order gets the discount!')) : e(t('Orders until your reward')) . ': ' . ($every - $stamp - 1) ?></small>
  </div>
<?php endif; ?>

<div class="grid g2 mb">
  <div class="card" style="background:var(--amber-soft);border-color:#f1d8a8">
    <b><?= e(t('Shop workload')) ?>: <?= e(t($wl['level'])) ?></b><br><span class="small muted"><?= e(t('Processing time now is about')) ?> <?= e(str_replace('hours', t('hours'), $wl['est_text'])) ?>.</span>
  </div>
  <a class="card" href="<?= e(url('customer/help.php')) ?>" style="color:inherit;text-decoration:none">
    <b>💬 <?= e(t('Have a question?')) ?></b><br><span class="small muted"><?= e(t('Ask us about your laundry, prices or hours.')) ?></span>
  </a>
</div>

<?php if ($latest): ?>
<div class="card mb">
  <div class="card-head"><h3><?= e(t('Latest update')) ?></h3><small class="muted"><?= e(time_ago($latest['created_at'])) ?></small></div>
  <div class="notif"><span class="ndot">🔔</span><div><b><?= e($latest['title']) ?></b><br><span class="small muted"><?= e($latest['message']) ?></span></div></div>
  <a href="<?= e(url('customer/notifications.php')) ?>"><?= e(t('See all updates')) ?></a>
</div>
<?php endif; ?>

<a class="card list-item" href="<?= e(url('customer/orders.php')) ?>" style="display:flex;color:inherit;text-decoration:none;padding:16px 18px">
  <div class="meta"><b><?= e(t('Order history')) ?></b><small class="muted"><?= $historyCount ?> <?= e(t('completed order(s)')) ?></small></div><span>›</span>
</a>
<?php layout_end();
