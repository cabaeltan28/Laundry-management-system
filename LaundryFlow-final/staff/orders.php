<?php
/**
 * Order list with filters and search (employees and owner).
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

$filter = get('filter', 'active');
$q = get('q');
$from = get('from');
$to = get('to');
$customerId = (int)get('customer_id');

$where = [];
$params = [];
switch ($filter) {
    case 'active':    $where[] = "o.status IN ('" . implode("','", ACTIVE_STATUSES) . "')"; break;
    case 'ready':     $where[] = "o.status = 'ready'"; break;
    case 'claimed':   $where[] = "o.status = 'claimed'"; break;
    case 'cancelled': $where[] = "o.status = 'cancelled'"; break;
    case 'rush':      $where[] = "o.is_rush = 1 AND o.status NOT IN ('claimed','cancelled')"; break;
    case 'unpaid':    $where[] = "o.payment_status <> 'paid' AND o.status <> 'cancelled'"; break;
    case 'overdue':   $where[] = "o.status IN ('" . implode("','", ACTIVE_STATUSES) . "') AND o.estimated_ready_at < ?"; $params[] = now(); break;
    default:          $filter = 'all';
}
if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q);
    $where[] = '(o.order_no LIKE ? OR c.full_name LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ')';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    if ($digits !== '') $params[] = '%' . $digits . '%';
}
if ($from !== '' && strtotime($from)) { $where[] = 'o.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to)) { $where[] = 'o.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($to)); }
if ($customerId) { $where[] = 'o.customer_id = ?'; $params[] = $customerId; }

$sql = 'SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone, s.name AS service_name, s.unit AS service_unit
        FROM orders o JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id' .
    ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 300';
$orders = q_all($sql, $params);

// Exact order number match -> open directly
if ($q !== '' && count($orders) === 1 && strcasecmp($orders[0]['order_no'], $q) === 0) {
    redirect('staff/order.php?id=' . $orders[0]['id']);
}

$tabs = ['active' => t('In process'), 'ready' => t('Ready'), 'overdue' => t('Late'), 'rush' => 'Rush', 'unpaid' => t('Unpaid'), 'claimed' => t('Claimed'), 'cancelled' => t('Cancelled'), 'all' => t('All')];
$cust = $customerId ? q_one('SELECT full_name FROM customers WHERE id = ?', [$customerId]) : null;

layout_start(t('Orders'), 'orders', [
    'refresh' => $filter === 'active' && $q === '' ? 120 : 0,
    'subtitle' => count($orders) . ' ' . t('order(s)') . ($cust ? ' · ' . $cust['full_name'] : ''),
    'actions' => '<a class="btn" href="' . e(url('staff/new_order.php')) . '">➕ ' . e(t('New order')) . '</a>',
]);
?>
<div class="tabs">
  <?php foreach ($tabs as $k => $l): ?>
    <a class="<?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= e($k) ?><?= $q !== '' ? '&q=' . e(urlencode($q)) : '' ?><?= $customerId ? '&customer_id=' . $customerId : '' ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <?php if ($customerId): ?><input type="hidden" name="customer_id" value="<?= $customerId ?>"><?php endif; ?>
    <div class="field" style="flex:2"><label><?= e(t('Search')) ?></label><input type="search" name="q" accesskey="s" value="<?= e($q) ?>" placeholder="<?= e(t('Name, mobile number or order number')) ?>"></div>
    <div class="field"><label><?= e(t('From')) ?></label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label><?= e(t('To')) ?></label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn"><?= e(t('Search')) ?></button>
    <?php if ($q !== '' || $from !== '' || $to !== '' || $customerId): ?><a class="btn btn-ghost" href="?filter=<?= e($filter) ?>"><?= e(t('Clear')) ?></a><?php endif; ?>
  </form>
  <?php if (!$orders): ?>
    <?= empty_state('No orders found', 'Try another filter or search.') ?>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(t('Order')) ?></th><th><?= e(t('Customer')) ?></th><th><?= e(t('Service')) ?></th><th><?= e(t('Qty')) ?></th><th><?= e(t('Now')) ?></th><th><?= e(t('Promised')) ?></th><th class="num">Total</th><th><?= e(t('Payment')) ?></th><th><?= e(t('Date')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td class="nowrap"><a href="<?= e(url('staff/order.php?id=' . $o['id'])) ?>"><b><?= e($o['order_no']) ?></b></a><?= $o['is_rush'] ? ' <span class="badge b-rush">RUSH</span>' : '' ?><?= demo_badge($o) ?></td>
        <td><?= e($o['customer_name']) ?><br><small class="muted"><?= e(phone_fmt($o['customer_phone'])) ?></small></td>
        <td><?= e($o['service_name']) ?></td>
        <td class="nowrap"><?= e(qty_fmt($o['weight'], $o['service_unit'])) ?></td>
        <td><?= status_badge($o['status']) ?></td>
        <td class="nowrap small"><?= in_array($o['status'], ['claimed', 'cancelled'], true) ? '—' : e(fmt_when($o['estimated_ready_at'])) ?></td>
        <td class="num"><?= e(money($o['total'])) ?></td>
        <td><?= pay_badge($o['payment_status']) ?></td>
        <td class="nowrap small muted"><?= e(fmt_dt($o['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_end();
