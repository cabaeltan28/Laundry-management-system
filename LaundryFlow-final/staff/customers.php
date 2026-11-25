<?php
/**
 * Customers (employees and owner). Edits are audited.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

if (is_post() && post('action') === 'link_user') {
    verify_csrf();
    $id = (int)post('id');
    $c = q_one('SELECT * FROM customers WHERE id = ?', [$id]);
    $who = post('account');
    $acct = $who === '' ? null : q_one("SELECT id, username, full_name FROM users WHERE role = 'customer' AND is_active = 1 AND (username = ? OR phone = ?)", [$who, normalize_phone($who) ?? '-']);
    if (!$c) {
        flash('error', t('Customer not found.'));
    } elseif ($c['user_id']) {
        flash('info', t('This customer is already connected to an app account.'));
    } elseif (!$acct) {
        flash('error', t('No customer app account found with that username or mobile number.'));
    } else {
        link_customer_records([$id], (int)$acct['id'], 'linked at the counter by ' . $u['full_name']);
        flash('success', '✅ ' . $c['full_name'] . ' → ' . $acct['username'] . '. ' . t('Their orders now show in the app.'));
    }
    redirect('staff/customers.php?q=' . urlencode(get('q')));
}

if (is_post()) {
    verify_csrf();
    $id = (int)post('id');
    $c = q_one('SELECT * FROM customers WHERE id = ?', [$id]);
    $name = post('full_name');
    $phoneRaw = post('phone');
    $phone = normalize_phone($phoneRaw);
    if (!$c) {
        flash('error', 'Customer not found.');
    } elseif (mb_strlen($name) < 2 || ($phoneRaw !== '' && !$phone)) {
        flash('error', 'Enter a valid name and mobile number.');
    } else {
        q('UPDATE customers SET full_name = ?, phone = ?, address = ?, notes = ? WHERE id = ?',
            [$name, $phone, post('address') ?: null, post('notes') ?: null, $id]);
        audit('customer_updated', 'customer', $id, null, 'details',
            $c['full_name'] . ' / ' . ($c['phone'] ?: '-'), $name . ' / ' . ($phone ?: '-'));
        flash('success', 'Customer updated.');
    }
    redirect('staff/customers.php?q=' . urlencode(get('q')));
}

$q = get('q');
$params = [];
$where = '';
if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q);
    $where = 'WHERE c.full_name LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '');
    $params[] = '%' . $q . '%';
    if ($digits !== '') $params[] = '%' . $digits . '%';
}
$rows = q_all("SELECT c.*,
                 (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
                 (SELECT COALESCE(SUM(o.total - o.amount_paid), 0) FROM orders o WHERE o.customer_id = c.id AND o.status <> 'cancelled') AS balance,
                 (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) AS last_order
               FROM customers c $where ORDER BY c.full_name LIMIT 300", $params);

layout_start(t('Customers'), 'customers', [
    'subtitle' => count($rows) . ' ' . t('customer(s)'),
    'actions' => '<a class="btn" href="' . e(url('staff/new_order.php')) . '">➕ ' . e(t('New order')) . '</a>',
]);
?>
<div class="card">
  <form class="search-bar mb" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" accesskey="s" placeholder="<?= e(t('Name or mobile number')) ?>">
    <button class="btn"><?= e(t('Search')) ?></button>
  </form>
  <?php if (!$rows): ?><?= empty_state('No customers found') ?><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('Mobile')) ?></th><th><?= e(t('Account')) ?></th><th class="num"><?= e(t('Orders')) ?></th><th class="num"><?= e(t('Unpaid')) ?></th><th><?= e(t('Last order')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><b><?= e($c['full_name']) ?></b><?= $c['notes'] ? '<br><small class="muted">' . e($c['notes']) . '</small>' : '' ?></td>
        <td class="nowrap"><?= e(phone_fmt($c['phone'])) ?: '—' ?></td>
        <td><?= $c['user_id'] ? '<span class="badge pay-paid">📱 ' . e(t('App user')) . '</span>' : '<span class="badge">' . e(t('Walk-in')) . '</span>' ?><?= demo_badge($c) ?></td>
        <td class="num"><a href="<?= e(url('staff/orders.php?filter=all&customer_id=' . $c['id'])) ?>"><?= (int)$c['order_count'] ?></a><?php $left = loyalty_orders_left((int)$c['id']); if ($left === 0): ?><br><span class="badge pay-paid">🎁 <?= e(t('Next order discounted')) ?></span><?php endif; ?></td>
        <td class="num"><?= (float)$c['balance'] > 0 ? '<span style="color:var(--red)">' . e(money($c['balance'])) . '</span>' : '—' ?></td>
        <td class="small muted nowrap"><?= $c['last_order'] ? e(fmt_dt($c['last_order'], 'M j, Y')) : '—' ?></td>
        <td class="nowrap">
          <a class="btn btn-outline btn-sm" href="<?= e(url('staff/new_order.php?customer_id=' . $c['id'])) ?>">➕ <?= e(t('New order')) ?></a>
          <details class="inline-form"><summary class="small"><?= e(t('Edit')) ?></summary>
            <form method="post" action="?q=<?= e(urlencode($q)) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <div class="field"><input type="text" name="full_name" value="<?= e($c['full_name']) ?>" required aria-label="Name"></div>
              <div class="field"><input type="tel" name="phone" value="<?= e($c['phone']) ?>" placeholder="Mobile" aria-label="Mobile"></div>
              <div class="field"><input type="text" name="address" value="<?= e($c['address']) ?>" placeholder="Address" aria-label="Address"></div>
              <div class="field"><input type="text" name="notes" value="<?= e($c['notes']) ?>" placeholder="Notes" aria-label="Notes"></div>
              <button class="btn btn-sm"><?= e(t('Save')) ?></button>
            </form>
            <?php if (!$c['user_id']): ?>
            <form method="post" action="?q=<?= e(urlencode($q)) ?>" class="mt">
              <?= csrf_field() ?><input type="hidden" name="action" value="link_user"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <label class="small"><?= e(t('Connect to app account (username or mobile)')) ?></label>
              <div class="search-bar"><input type="text" name="account" required><button class="btn btn-outline btn-sm">📱 <?= e(t('Connect')) ?></button></div>
            </form>
            <?php endif; ?>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_end();
