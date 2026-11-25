<?php
/**
 * Owner: all payments with filters + CSV export.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role('owner');

$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
$method = get('method');
$staffId = (int)get('staff');
$fromDt = date('Y-m-d 00:00:00', strtotime($from) ?: time());
$toDt = date('Y-m-d 23:59:59', strtotime($to) ?: time());

$where = 'p.created_at >= ? AND p.created_at <= ?';
$params = [$fromDt, $toDt];
if (isset(PAYMENT_METHODS[$method])) { $where .= ' AND p.method = ?'; $params[] = $method; }
if ($staffId) { $where .= ' AND p.received_by = ?'; $params[] = $staffId; }

$rows = q_all("SELECT p.*, o.order_no, o.id AS oid, c.full_name AS customer_name, u.full_name AS staff_name, v.full_name AS voided_name
               FROM payments p JOIN orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id
               LEFT JOIN users u ON u.id = p.received_by LEFT JOIN users v ON v.id = p.voided_by
               WHERE $where ORDER BY p.id DESC LIMIT 1000", $params);

if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_' . date('Ymd', strtotime($fromDt)) . '_' . date('Ymd', strtotime($toDt)) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ correctly
    csv_row($out, ['Date', 'Order', 'Customer', 'Method', 'Reference', 'Amount', 'Received by', 'Voided', 'Void reason']);
    foreach ($rows as $p) {
        csv_row($out, [$p['created_at'], $p['order_no'], $p['customer_name'], PAYMENT_METHODS[$p['method']] ?? $p['method'], $p['reference_no'],
            number_format((float)$p['amount'], 2, '.', ''), $p['staff_name'], $p['is_void'] ? 'Yes' : 'No', $p['void_reason']]);
    }
    exit;
}

$total = 0.0;
$byMethod = [];
foreach ($rows as $p) {
    if ($p['is_void']) continue;
    $total += (float)$p['amount'];
    $byMethod[$p['method']] = ($byMethod[$p['method']] ?? 0) + (float)$p['amount'];
}
$staff = q_all("SELECT id, full_name FROM users WHERE role IN ('employee','owner') ORDER BY full_name");
$qs = http_build_query(['from' => $from, 'to' => $to, 'method' => $method, 'staff' => $staffId ?: '', 'export' => 'csv']);

layout_start('Transactions', 'transactions', [
    'subtitle' => count($rows) . ' payment(s) · ' . money($total) . ' collected',
    'actions' => '<a class="btn btn-outline" href="?' . e($qs) . '">⤓ Download CSV (Excel)</a>',
]);
?>
<div class="card mb">
  <form class="filters" method="get">
    <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label>Method</label><select name="method"><option value="">All methods</option><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Received by</label><select name="staff"><option value="">Everyone</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $staffId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn">Apply</button>
  </form>
  <div class="chips"><?php foreach ($byMethod as $m => $amt): ?><span><?= e(PAYMENT_METHODS[$m]) ?>: <?= e(money($amt)) ?></span><?php endforeach; ?></div>
</div>
<div class="card">
  <?php if (!$rows): ?><?= empty_state('No transactions in this period') ?><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Method</th><th>Reference</th><th>Received by</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $p): ?>
      <tr style="<?= $p['is_void'] ? 'opacity:.6' : '' ?>">
        <td class="nowrap small"><?= e(fmt_dt($p['created_at'], 'M j, Y g:i A')) ?></td>
        <td><a href="<?= e(url('staff/order.php?id=' . $p['oid'])) ?>"><?= e($p['order_no']) ?></a></td>
        <td><?= e($p['customer_name']) ?></td>
        <td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
        <td class="mono small"><?= e($p['reference_no'] ?: '—') ?></td>
        <td><?= e($p['staff_name']) ?></td>
        <td class="num"><?= $p['is_void'] ? '<s>' . e(money($p['amount'])) . '</s>' : e(money($p['amount'])) ?></td>
        <td><?= $p['is_void'] ? '<span class="badge st-cancelled" title="' . e($p['void_reason']) . '">Voided by ' . e($p['voided_name']) . '</span>' : '<span class="badge pay-paid">Valid</span>' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_end();
