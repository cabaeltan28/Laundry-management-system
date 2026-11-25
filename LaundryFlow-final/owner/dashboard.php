<?php
/**
 * Owner console: business overview, revenue chart, workload,
 * discrepancies, recent transactions and live employee activity.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');
run_pickup_reminders();

$todayStart = date('Y-m-d 00:00:00');
$yStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
$yNow = date('Y-m-d H:i:s', strtotime('-1 day'));
$revToday = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND created_at >= ?', [$todayStart]);
$revYest = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND created_at >= ? AND created_at <= ?', [$yStart, $yNow]);
$ordersToday = (int)q_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$todayStart]);
$ordersHour = (int)q_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 3600)]);
$wl = workload();
$waiting24 = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'ready' AND ready_at < ?", [date('Y-m-d H:i:s', time() - 86400)]);
$unpaid = (float)q_val("SELECT COALESCE(SUM(total - amount_paid),0) FROM orders WHERE status <> 'cancelled' AND payment_status <> 'paid'");
$change = $revYest > 0 ? round(($revToday - $revYest) / $revYest * 100, 1) : null;

// Last 7 days revenue & orders (aggregated in PHP so it works on any MySQL version)
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[$d] = ['rev' => 0.0, 'orders' => 0];
}
$since = array_key_first($days) . ' 00:00:00';
foreach (q_all('SELECT amount, created_at FROM payments WHERE is_void = 0 AND created_at >= ?', [$since]) as $p) {
    $d = substr($p['created_at'], 0, 10);
    if (isset($days[$d])) $days[$d]['rev'] += (float)$p['amount'];
}
foreach (q_all('SELECT created_at FROM orders WHERE created_at >= ?', [$since]) as $r) {
    $d = substr($r['created_at'], 0, 10);
    if (isset($days[$d])) $days[$d]['orders']++;
}
$maxRev = max(1, max(array_column($days, 'rev')));
$weekRev = array_sum(array_column($days, 'rev'));
$weekOrders = array_sum(array_column($days, 'orders'));

$discs = q_all("SELECT d.*, o.order_no, c.full_name AS customer_name, u.full_name AS reporter FROM discrepancies d
                LEFT JOIN orders o ON o.id = d.order_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = d.reported_by
                WHERE d.status = 'open' ORDER BY d.id DESC LIMIT 3");
$tx = q_all('SELECT p.*, o.order_no, o.id AS oid, o.weight, c.full_name AS customer_name, s.name AS service_name, s.unit
             FROM payments p JOIN orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id
             ORDER BY p.id DESC LIMIT 8');
$activity = q_all("SELECT a.*, u.full_name AS actor_name, o.order_no FROM audit_logs a JOIN users u ON u.id = a.actor_id
                   LEFT JOIN orders o ON o.id = a.order_id
                   WHERE u.role IN ('employee','owner') AND a.action NOT IN ('login','logout') ORDER BY a.id DESC LIMIT 8");

$tips = daily_insights();
$snapRev = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND created_at >= ?', [date('Y-m-01 00:00:00', clock())]);
$snapExp = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ?', [date('Y-m-01', clock())]);
$snapProfit = $snapRev - $snapExp;
$hour = (int)date('G', clock());
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
layout_start($greet . ', ' . explode(' ', $u['full_name'])[0], 'dashboard', [
    'refresh' => 120,
    'actions' => '<a class="btn btn-outline" href="' . e(url('owner/assistant.php')) . '">✨ Ask AI</a> <a class="btn btn-outline" href="' . e(url('owner/reports.php')) . '">⤓ Export report</a>',
]);
?>
<div class="section-title"><div><h2 class="mt0">Business overview</h2><p class="muted">Live performance across <?= e(setting('shop_branch', shop_name())) ?></p></div><span class="live">● LIVE · Updated <?= e(date('g:i A')) ?></span></div>

<div class="grid g4 mb">
  <div class="card kpi"><small>Today's revenue</small><div class="val"><?= e(money($revToday)) ?></div>
    <div class="sub <?= $change === null ? '' : ($change >= 0 ? 'up' : 'down') ?>"><?= $change === null ? 'No sales yesterday at this time' : ($change >= 0 ? '+' : '') . e($change) . '% vs yesterday' ?></div></div>
  <div class="card kpi"><small>Today's orders</small><div class="val"><?= $ordersToday ?></div><div class="sub"><?= $ordersHour ?> added this hour</div></div>
  <div class="card kpi"><small>Active orders</small><div class="val"><?= (int)$wl['active'] ?></div><div class="sub"><?= (int)$wl['counts']['washing'] ?> washing · <?= (int)$wl['counts']['drying'] ?> drying</div></div>
  <div class="card kpi"><small>Ready / unclaimed</small><div class="val"><?= (int)$wl['ready'] ?></div><div class="sub <?= $waiting24 ? 'warn' : '' ?>"><?= $waiting24 ?> waiting over 24h</div></div>
</div>

<div class="card mb" style="background:linear-gradient(120deg,#fff,var(--brand-soft))">
  <div class="card-head"><div><h3>✨ Today's insights</h3><p class="muted">Monthly profit so far: <b><?= e(money($snapProfit)) ?></b> (sales <?= e(money($snapRev)) ?> − expenses <?= e(money($snapExp)) ?>)</p></div>
    <div class="btn-row"><span class="ai-tag <?= $tips['source'] === 'ai' ? '' : 'off' ?>"><?= $tips['source'] === 'ai' ? 'AI' : 'Offline' ?></span><a href="<?= e(url('owner/assistant.php')) ?>">Ask a question →</a></div></div>
  <ul class="insights" style="columns:2;column-gap:24px"><?php foreach ($tips['items'] as $tip): ?><li><?= e($tip) ?></li><?php endforeach; ?></ul>
</div>

<div class="grid g-main mb">
  <div class="card">
    <div class="card-head"><div><h3>Revenue & orders</h3><p class="muted">Last 7 days · Asia/Manila</p></div>
      <div class="btn-row"><span class="badge pay-paid">Revenue <?= e(money($weekRev)) ?></span><span class="badge st-washing"><?= (int)$weekOrders ?> orders</span></div></div>
    <div class="bars">
      <?php foreach ($days as $d => $v): ?>
        <div class="bar <?= $d === date('Y-m-d') ? 'today' : '' ?>" title="<?= e($d) ?>: <?= e(money($v['rev'])) ?>, <?= (int)$v['orders'] ?> orders">
          <em><?= $v['rev'] > 0 ? '₱' . e(number_format($v['rev'] / 1000, 1)) . 'k' : '' ?></em>
          <span style="height:<?= max(1, round($v['rev'] / $maxRev * 100)) ?>%"></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="bar-labels"><?php foreach ($days as $d => $v): ?><span><?= e(date('D j', strtotime($d))) ?><br><?= (int)$v['orders'] ?> ord</span><?php endforeach; ?></div>
    <p class="small muted mt">Unpaid balances across open orders: <b style="color:var(--red)"><?= e(money($unpaid)) ?></b> · <a href="<?= e(url('staff/orders.php?filter=unpaid')) ?>">view</a></p>
  </div>
  <div class="stack">
    <?= workload_card($wl) ?>
    <?php foreach ($discs as $d): ?>
      <div class="card disc-card">
        <div class="card-head"><div><h3>⚠ <?= e(DISCREPANCY_LABELS[$d['type']] ?? $d['type']) ?></h3><p class="muted"><?= e($d['order_no'] ?? t('Cash drawer')) ?><?= $d['customer_name'] ? ' · ' . e($d['customer_name']) : '' ?> · by <?= e($d['reporter']) ?></p></div>
          <?php if ($d['difference']): ?><span class="badge b-open"><?= e($d['difference']) ?></span><?php endif; ?></div>
        <div class="info-grid"><div class="info"><small>Before</small><b><?= e($d['old_value']) ?></b></div><div class="info" style="background:var(--red-soft)"><small>After</small><b><?= e($d['new_value']) ?></b></div></div>
        <a class="btn btn-outline btn-block mt" href="<?= e(url($d['order_id'] ? 'staff/order.php?id=' . $d['order_id'] : 'owner/discrepancies.php')) ?>">Review discrepancy</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$discs): ?><div class="card"><h3>Discrepancies</h3><p class="muted small">✓ No open discrepancies. All changes are reviewed.</p></div><?php endif; ?>
  </div>
</div>

<div class="grid g-main">
  <div class="card">
    <div class="card-head"><div><h3>Recent transactions</h3><p class="muted">Latest payments recorded at the counter</p></div><a href="<?= e(url('owner/transactions.php')) ?>">View all</a></div>
    <?php if (!$tx): ?><?= empty_state('No transactions yet') ?><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Order</th><th>Customer</th><th>Service</th><th>Qty</th><th class="num">Amount</th><th>Method</th><th>Time</th></tr></thead>
      <tbody><?php foreach ($tx as $p): ?>
        <tr>
          <td><a href="<?= e(url('staff/order.php?id=' . $p['oid'])) ?>"><?= e($p['order_no']) ?></a></td>
          <td><?= e($p['customer_name']) ?></td><td><?= e($p['service_name']) ?></td><td class="nowrap"><?= e(qty_fmt($p['weight'], $p['unit'])) ?></td>
          <td class="num"><?= $p['is_void'] ? '<s>' . e(money($p['amount'])) . '</s>' : e(money($p['amount'])) ?></td>
          <td><?= $p['is_void'] ? '<span class="badge st-cancelled">Voided</span>' : '<span class="badge pay-paid">' . e(PAYMENT_METHODS[$p['method']]) . '</span>' ?></td>
          <td class="small muted nowrap"><?= e(fmt_dt($p['created_at'], 'M j g:i A')) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Employee activity</h3><p class="muted">Live actions at the shop</p></div><a href="<?= e(url('owner/audit.php')) ?>">See audit log</a></div>
    <?php if (!$activity): ?><p class="muted small">No activity yet.</p><?php endif; ?>
    <?php foreach ($activity as $a): ?>
      <div class="activity"><span class="avatar"><?= e(initials($a['actor_name'])) ?></span>
        <div><b class="small"><?= e($a['actor_name']) ?></b><br><small class="muted"><?= e(audit_action_label($a['action'])) ?><?= $a['order_no'] ? ' · ' . e($a['order_no']) : '' ?><?= $a['new_value'] && $a['action'] !== 'order_created' ? ' → ' . e(mb_strimwidth((string)$a['new_value'], 0, 40, '…')) : '' ?></small></div>
        <span class="when"><?= e(fmt_dt($a['created_at'], 'g:i A')) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>
<?php layout_end();
