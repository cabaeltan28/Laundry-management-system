<?php
/**
 * COUNTER (employee home). Designed for speed and for staff with little
 * computer experience: big buttons, pictures, Tagalog/English, shortcut keys.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);
run_pickup_reminders();

$wl = workload();
$in = "'" . implode("','", ACTIVE_STATUSES) . "'";
$queue = q_all("SELECT o.*, c.full_name AS customer_name, s.name AS service_name, s.unit AS service_unit
                FROM orders o JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id
                WHERE o.status IN ($in)
                ORDER BY o.is_rush DESC, o.estimated_ready_at ASC LIMIT 40");
$today = date('Y-m-d 00:00:00', clock());
$mine = (int)q_val('SELECT COUNT(*) FROM orders WHERE created_by = ? AND created_at >= ?', [$u['id'], $today]);
$todayOrders = (int)q_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$today]);
$late = (int)q_val("SELECT COUNT(*) FROM orders WHERE status IN ($in) AND estimated_ready_at < ?", [now()]);
$code = get('code');
$hits = $code !== '' ? find_ready_by_code($code) : [];

layout_start(t('Counter'), 'dashboard', ['refresh' => $code === '' ? 90 : 0]);
?>
<div class="tiles">
  <?= tile('➕', 'New order', 'staff/new_order.php', 'n', 'primary') ?>
  <?= tile('✅', 'Release laundry', 'staff/pickups.php', 'p') ?>
  <?= tile('📋', 'All orders', 'staff/orders.php', 'o') ?>
  <?= tile('💵', 'Cash closing', 'staff/closing.php', 'k') ?>
</div>

<div class="grid g2 mb">
  <div class="card">
    <h3>🔢 <?= e(t('Customer is here to claim? Type the pickup code')) ?></h3>
    <form method="get" class="search-bar">
      <input type="text" name="code" value="<?= e($code) ?>" inputmode="numeric" maxlength="6" class="code-input" placeholder="0000" accesskey="q" aria-label="<?= e(t('Pickup code')) ?>" <?= $code === '' ? '' : 'autofocus' ?>>
      <button class="btn btn-big"><?= e(t('Find')) ?></button>
    </form>
    <?php if ($code !== '' && !$hits): ?>
      <div class="alert alert-error mt"><?= e(t('No ready order has this code. Check the code or search by name.')) ?></div>
    <?php endif; ?>
    <?php foreach ($hits as $o): $bal = round((float)$o['total'] - (float)$o['amount_paid'], 2); ?>
      <div class="pickup-hit">
        <div class="list-item" style="padding-top:0">
          <div class="meta"><b style="font-size:17px"><?= e($o['customer_name']) ?></b>
            <small class="muted"><?= e($o['order_no']) ?> · <?= e($o['service_name']) ?> · <?= e(qty_fmt($o['weight'], $o['service_unit'])) ?> · <?= (int)$o['bag_count'] ?> <?= e(t('bag(s)')) ?></small></div>
          <div class="right"><small class="muted"><?= e(t('To pay')) ?></small><div class="money-big" style="font-size:26px;color:<?= $bal > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= e(money(max(0, $bal))) ?></div></div>
        </div>
        <form method="post" action="<?= e(url('staff/order_action.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="pay_claim">
          <input type="hidden" name="pickup_code" value="<?= e($o['pickup_code']) ?>"><input type="hidden" name="return" value="staff/dashboard.php">
          <?php if ($bal > 0): ?>
            <div class="row">
              <div class="field"><label><?= e(t('Paid with')) ?></label><select name="method"><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label><?= e(t('Reference no. (GCash/Maya/Bank)')) ?></label><input type="text" name="reference"></div>
            </div>
            <button class="btn btn-xl">💵 <?= e(t('Received')) ?> <?= e(money($bal)) ?> — <?= e(t('Release laundry')) ?></button>
          <?php else: ?>
            <input type="hidden" name="method" value="cash">
            <button class="btn btn-xl">✅ <?= e(t('Paid already — Release laundry')) ?></button>
          <?php endif; ?>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3>🔍 <?= e(t('Find an order')) ?></h3>
    <form class="search-bar" method="get" action="<?= e(url('staff/orders.php')) ?>">
      <input type="search" name="q" accesskey="s" placeholder="<?= e(t('Name, mobile number or order number')) ?>" aria-label="<?= e(t('Search')) ?>">
      <input type="hidden" name="filter" value="all">
      <button class="btn btn-big"><?= e(t('Search')) ?></button>
    </form>
    <div class="grid g3 mt">
      <div class="kpi"><small><?= e(t('In process')) ?></small><div class="val"><?= (int)$wl['active'] ?></div></div>
      <div class="kpi"><small><?= e(t('Ready / unclaimed')) ?></small><div class="val"><?= (int)$wl['ready'] ?></div></div>
      <div class="kpi"><small><?= e(t('Late')) ?></small><div class="val" style="color:<?= $late ? 'var(--red)' : 'var(--green)' ?>"><?= $late ?></div></div>
    </div>
    <p class="small muted"><?= e(t('Orders today')) ?>: <?= $todayOrders ?> · <?= e(t('made by you')) ?>: <?= $mine ?></p>
  </div>
</div>

<div class="grid g-main">
  <div class="card">
    <div class="card-head"><div><h3>🧺 <?= e(t('Work queue')) ?></h3><p class="muted"><?= e(t('Rush first, then the earliest promised time. Press the pink button when a step is done.')) ?></p></div><span class="live">● LIVE</span></div>
    <?php if (!$queue): ?>
      <?= empty_state("You're all caught up", 'No active orders right now.') ?>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th><?= e(t('Order')) ?></th><th><?= e(t('Customer')) ?></th><th><?= e(t('Qty')) ?></th><th><?= e(t('Now')) ?></th><th><?= e(t('Promised')) ?></th><th class="num"><?= e(t('Next step')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($queue as $o): $next = next_status($o['status']); $isLate = strtotime($o['estimated_ready_at']) < clock(); ?>
        <tr>
          <td><a href="<?= e(url('staff/order.php?id=' . $o['id'])) ?>"><b><?= e($o['order_no']) ?></b></a><?= $o['is_rush'] ? ' <span class="badge b-rush">⚡ RUSH</span>' : '' ?></td>
          <td><?= e($o['customer_name']) ?><br><small class="muted"><?= e($o['service_name']) ?></small></td>
          <td class="nowrap"><?= (float)$o['weight'] > 0 ? e(qty_fmt($o['weight'], $o['service_unit'])) : '<span class="badge pay-unpaid">' . e(t('Weigh')) . '</span>' ?></td>
          <td><?= status_badge($o['status']) ?></td>
          <td class="nowrap"><?= e(fmt_when($o['estimated_ready_at'])) ?><?= $isLate ? '<br><small style="color:var(--red)">⏰ ' . e(t('late')) . '</small>' : '' ?></td>
          <td class="num">
            <?php if ((float)$o['weight'] <= 0): ?>
              <a class="btn btn-outline" href="<?= e(url('staff/order.php?id=' . $o['id'])) ?>">⚖️ <?= e(t('Enter weight')) ?></a>
            <?php elseif ($next): ?>
              <form method="post" action="<?= e(url('staff/order_action.php')) ?>" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="action" value="advance">
                <input type="hidden" name="to" value="<?= e($next) ?>">
                <input type="hidden" name="return" value="staff/dashboard.php">
                <button class="btn">→ <?= e($next === 'ready' ? t('Ready') : status_label($next)) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="stack">
    <?= workload_card($wl) ?>
    <div class="card">
      <h3>⌨️ <?= e(t('Shortcut keys')) ?></h3>
      <p class="small muted"><?= e(t('Hold Alt and press the letter:')) ?></p>
      <div class="chips"><span>Alt+N <?= e(t('New order')) ?></span><span>Alt+Q <?= e(t('Pickup code')) ?></span><span>Alt+S <?= e(t('Search')) ?></span><span>Alt+P <?= e(t('Release laundry')) ?></span><span>Alt+H <?= e(t('Counter')) ?></span></div>
    </div>
  </div>
</div>
<?php layout_end();
