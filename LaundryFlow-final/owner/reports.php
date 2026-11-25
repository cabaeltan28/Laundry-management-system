<?php
/**
 * Owner reports: revenue, orders, on-time rate, services, payment methods,
 * employees, busiest hours, unpaid balances. CSV export for Excel.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role('owner');

$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
$fromDt = date('Y-m-d 00:00:00', strtotime($from) ?: time());
$toDt = date('Y-m-d 23:59:59', strtotime($to) ?: time());
$range = [$fromDt, $toDt];

$orders = q_all('SELECT o.*, c.full_name AS customer_name, s.name AS service_name, s.unit, u.full_name AS staff_name
                 FROM orders o JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id
                 LEFT JOIN users u ON u.id = o.created_by
                 WHERE o.created_at >= ? AND o.created_at <= ? ORDER BY o.id', $range);

if (get('export') === 'orders') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_' . date('Ymd', strtotime($fromDt)) . '_' . date('Ymd', strtotime($toDt)) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    csv_row($out, ['Order', 'Created', 'Customer', 'Service', 'Qty', 'Unit', 'Initial qty', 'Rush', 'Total', 'Paid', 'Payment status', 'Status', 'Est. ready', 'Ready at', 'Claimed at', 'Created by']);
    foreach ($orders as $o) {
        csv_row($out, [$o['order_no'], $o['created_at'], $o['customer_name'], $o['service_name'], $o['weight'], $o['unit'], $o['initial_weight'],
            $o['is_rush'] ? 'Yes' : 'No', $o['total'], $o['amount_paid'], $o['payment_status'], status_label($o['status']),
            $o['estimated_ready_at'], $o['ready_at'], $o['claimed_at'], $o['staff_name']]);
    }
    exit;
}

$payments = q_all('SELECT p.*, u.full_name AS staff_name FROM payments p LEFT JOIN users u ON u.id = p.received_by
                   WHERE p.is_void = 0 AND p.created_at >= ? AND p.created_at <= ?', $range);
$revenue = array_sum(array_map(fn($p) => (float)$p['amount'], $payments));

$valid = array_filter($orders, fn($o) => $o['status'] !== 'cancelled');
$kg = 0.0; $sales = 0.0; $onTime = 0; $readyCount = 0; $turn = 0.0; $rush = 0;
$byService = []; $byHour = array_fill(0, 24, 0); $byDay = [];
foreach ($valid as $o) {
    $sales += (float)$o['total'];
    if ($o['unit'] === 'kg') $kg += (float)$o['weight'];
    if ($o['is_rush']) $rush++;
    if ($o['ready_at']) {
        $readyCount++;
        $turn += (strtotime($o['ready_at']) - strtotime($o['created_at'])) / 3600;
        if (strtotime($o['ready_at']) <= strtotime($o['estimated_ready_at']) + 900) $onTime++;
    }
    $k = $o['service_name'];
    $byService[$k] = $byService[$k] ?? ['orders' => 0, 'qty' => 0.0, 'unit' => $o['unit'], 'sales' => 0.0];
    $byService[$k]['orders']++;
    $byService[$k]['qty'] += (float)$o['weight'];
    $byService[$k]['sales'] += (float)$o['total'];
    $byHour[(int)date('G', strtotime($o['created_at']))]++;
}
foreach ($payments as $p) {
    $d = substr($p['created_at'], 0, 10);
    $byDay[$d] = ($byDay[$d] ?? 0) + (float)$p['amount'];
}
ksort($byDay);
$byMethod = [];
$byStaff = [];
foreach ($payments as $p) {
    $byMethod[$p['method']] = ($byMethod[$p['method']] ?? 0) + (float)$p['amount'];
    $n = $p['staff_name'] ?? 'Unknown';
    $byStaff[$n]['collected'] = ($byStaff[$n]['collected'] ?? 0) + (float)$p['amount'];
}
foreach ($orders as $o) {
    $n = $o['staff_name'] ?? 'Unknown';
    $byStaff[$n]['orders'] = ($byStaff[$n]['orders'] ?? 0) + 1;
}
foreach (q_all('SELECT u.full_name, COUNT(*) AS n FROM discrepancies d JOIN users u ON u.id = d.reported_by
                WHERE d.created_at >= ? AND d.created_at <= ? GROUP BY u.id, u.full_name', $range) as $r) {
    $byStaff[$r['full_name']]['disc'] = (int)$r['n'];
}
$cancelled = count($orders) - count($valid);
$expenses = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?', [substr($fromDt, 0, 10), substr($toDt, 0, 10)]);
$cashShort = (float)q_val('SELECT COALESCE(SUM(difference),0) FROM cash_closings WHERE difference < 0 AND created_at >= ? AND created_at <= ?', $range);
$unpaidRows = q_all("SELECT o.*, c.full_name AS customer_name, c.phone FROM orders o JOIN customers c ON c.id = o.customer_id
                     WHERE o.payment_status <> 'paid' AND o.status <> 'cancelled' AND o.total > 0 ORDER BY o.created_at LIMIT 50");
uasort($byService, fn($a, $b) => $b['sales'] <=> $a['sales']);
arsort($byMethod);
$maxDay = $byDay ? max($byDay) : 1;
$maxSvc = $byService ? max(array_column($byService, 'sales')) : 1;
$maxHour = max(1, max($byHour));
$open = (int)setting('open_hour', 8);
$close = (int)setting('close_hour', 20);

$qs = http_build_query(['from' => $from, 'to' => $to, 'export' => 'orders']);
layout_start('Reports', 'reports', [
    'subtitle' => fmt_dt($fromDt, 'M j, Y') . ' – ' . fmt_dt($toDt, 'M j, Y'),
    'actions' => '<a class="btn btn-outline" href="?' . e($qs) . '">⤓ Orders CSV</a> <a class="btn btn-outline" href="' . e(url('owner/transactions.php?' . http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv']))) . '">⤓ Payments CSV</a>',
]);
?>
<div class="card mb">
  <form class="filters" method="get">
    <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn">Apply</button>
    <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-d')) ?>&to=<?= e(date('Y-m-d')) ?>">Today</a>
    <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>&to=<?= e(date('Y-m-d')) ?>">Last 7 days</a>
    <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-01')) ?>&to=<?= e(date('Y-m-d')) ?>">This month</a>
  </form>
</div>

<div class="grid g4 mb">
  <div class="card kpi"><small>Expenses</small><div class="val" style="color:var(--red)"><?= e(money($expenses)) ?></div><div class="sub"><a href="<?= e(url('owner/expenses.php')) ?>">Manage expenses</a></div></div>
  <div class="card kpi"><small>Profit (sales − expenses)</small><div class="val" style="color:<?= $revenue - $expenses >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= e(money($revenue - $expenses)) ?></div><div class="sub"><?= $revenue > 0 ? 'Margin ' . round(($revenue - $expenses) / $revenue * 100) . '%' : '' ?></div></div>
  <div class="card kpi"><small>Cash drawer shortages</small><div class="val"><?= e(money(abs($cashShort))) ?></div><div class="sub"><a href="<?= e(url('staff/closing.php')) ?>">Cash closings</a></div></div>
  <div class="card kpi"><small>Repeat customers</small><div class="val"><?= count(array_filter(array_count_values(array_column($valid, 'customer_id')), fn($n) => $n >= 2)) ?></div><div class="sub">of <?= count(array_unique(array_column($valid, 'customer_id'))) ?> customers in period</div></div>
</div>
<div class="grid g4 mb">
  <div class="card kpi"><small>Revenue collected</small><div class="val"><?= e(money($revenue)) ?></div><div class="sub"><?= count($payments) ?> payments</div></div>
  <div class="card kpi"><small>Orders</small><div class="val"><?= count($valid) ?></div><div class="sub"><?= $rush ?> rush · <?= $cancelled ?> cancelled</div></div>
  <div class="card kpi"><small>Laundry processed</small><div class="val"><?= e(number_format($kg, 1)) ?> kg</div><div class="sub">Avg order <?= e(money(count($valid) ? $sales / count($valid) : 0)) ?></div></div>
  <div class="card kpi"><small>On-time rate</small><div class="val"><?= $readyCount ? round($onTime / $readyCount * 100) : 0 ?>%</div><div class="sub">Avg turnaround <?= $readyCount ? e(number_format($turn / $readyCount, 1)) : '0' ?> h</div></div>
</div>

<div class="grid g2 mb">
  <div class="card">
    <h3>Daily revenue</h3>
    <?php if (!$byDay): ?><?= empty_state('No payments in this period') ?><?php else: ?>
    <div class="bars"><?php foreach ($byDay as $d => $amt): ?><div class="bar" title="<?= e($d . ': ' . money($amt)) ?>"><span style="height:<?= max(1, round($amt / $maxDay * 100)) ?>%"></span></div><?php endforeach; ?></div>
    <div class="bar-labels"><?php foreach ($byDay as $d => $amt): ?><span><?= count($byDay) <= 14 ? e(date('j', strtotime($d))) : '' ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>Sales by service</h3>
    <?php if (!$byService): ?><p class="muted">No orders.</p><?php endif; ?>
    <?php foreach ($byService as $name => $s): ?>
      <div class="hbar"><span><?= e($name) ?><br><small class="muted"><?= (int)$s['orders'] ?> orders · <?= e(qty_fmt($s['qty'], $s['unit'])) ?></small></span>
        <div class="track"><span style="width:<?= round($s['sales'] / max(1, $maxSvc) * 100) ?>%"></span></div><b class="right"><?= e(money($s['sales'])) ?></b></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid g3 mb">
  <div class="card">
    <h3>Payment methods</h3>
    <?php if (!$byMethod): ?><p class="muted">No payments.</p><?php endif; ?>
    <?php foreach ($byMethod as $m => $amt): ?>
      <div class="list-item"><span><?= e(PAYMENT_METHODS[$m]) ?></span><span><b><?= e(money($amt)) ?></b> <small class="muted"><?= $revenue > 0 ? round($amt / $revenue * 100) : 0 ?>%</small></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3>Employee performance</h3>
    <?php if (!$byStaff): ?><p class="muted">No activity.</p><?php endif; ?>
    <?php foreach ($byStaff as $n => $s): ?>
      <div class="list-item"><div class="meta"><b><?= e($n) ?></b><small class="muted"><?= (int)($s['orders'] ?? 0) ?> orders · <?= (int)($s['disc'] ?? 0) ?> discrepancies</small></div><span><?= e(money($s['collected'] ?? 0)) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3>Busiest drop-off hours</h3>
    <p class="muted small">Use this to schedule staff.</p>
    <?php for ($h = $open; $h < max($close, $open + 1) && $h < 24; $h++): ?>
      <div class="hbar" style="grid-template-columns:60px 1fr 30px"><span class="small"><?= e(date('g A', mktime($h, 0))) ?></span><div class="track"><span style="width:<?= round($byHour[$h] / $maxHour * 100) ?>%"></span></div><small><?= $byHour[$h] ?></small></div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h3>Unpaid & partial balances</h3><p class="muted">All open orders, any date</p></div><a href="<?= e(url('staff/orders.php?filter=unpaid')) ?>">Open list</a></div>
  <?php if (!$unpaidRows): ?><p class="muted">✓ No unpaid balances.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Created</th><th class="num">Total</th><th class="num">Balance</th></tr></thead>
    <tbody><?php foreach ($unpaidRows as $o): ?>
      <tr><td><a href="<?= e(url('staff/order.php?id=' . $o['id'])) ?>"><?= e($o['order_no']) ?></a></td><td><?= e($o['customer_name']) ?><br><small class="muted"><?= e(phone_fmt($o['phone'])) ?></small></td>
        <td><?= status_badge($o['status']) ?></td><td class="small muted"><?= e(fmt_dt($o['created_at'], 'M j, Y')) ?></td>
        <td class="num"><?= e(money($o['total'])) ?></td><td class="num" style="color:var(--red)"><b><?= e(money((float)$o['total'] - (float)$o['amount_paid'])) ?></b></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_end();
