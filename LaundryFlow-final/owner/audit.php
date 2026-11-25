<?php
/**
 * Owner: complete, append-only audit log with filters.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role('owner');

$actor = (int)get('actor');
$action = get('action');
$from = get('from', date('Y-m-d', strtotime('-7 days')));
$to = get('to', date('Y-m-d'));
$q = get('q');

$where = 'a.created_at >= ? AND a.created_at <= ?';
$params = [date('Y-m-d 00:00:00', strtotime($from) ?: time()), date('Y-m-d 23:59:59', strtotime($to) ?: time())];
if ($actor) { $where .= ' AND a.actor_id = ?'; $params[] = $actor; }
if ($action !== '' && preg_match('/^[a-z_]+$/', $action)) { $where .= ' AND a.action = ?'; $params[] = $action; }
if ($q !== '') { $where .= ' AND (o.order_no LIKE ? OR a.new_value LIKE ? OR a.old_value LIKE ? OR a.reason LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }

$rows = q_all("SELECT a.*, u.full_name AS actor_name, u.role AS actor_role, o.order_no FROM audit_logs a
               LEFT JOIN users u ON u.id = a.actor_id LEFT JOIN orders o ON o.id = a.order_id
               WHERE $where ORDER BY a.id DESC LIMIT 500", $params);
$people = q_all("SELECT id, full_name, role FROM users WHERE role IN ('employee','owner') ORDER BY full_name");
$actions = array_column(q_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');

layout_start('Audit log', 'audit', ['subtitle' => 'Append-only history of every important change · showing up to 500 entries']);
?>
<div class="card mb">
  <form class="filters" method="get">
    <div class="field"><label>Person</label><select name="actor"><option value="">Everyone</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $actor === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Event</label><select name="action"><option value="">All events</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(audit_action_label($a)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="field" style="flex:1"><label>Search</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Order no., value or reason"></div>
    <button class="btn">Apply</button>
  </form>
</div>
<div class="card">
  <?php if (!$rows): ?><?= empty_state('No entries found') ?><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Actor</th><th>Event</th><th>Order</th><th>Change</th><th>Reason</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($rows as $a): ?>
      <tr>
        <td class="nowrap small"><?= e(fmt_dt($a['created_at'], 'M j, g:i:s A')) ?></td>
        <td><?= e($a['actor_name'] ?? 'System') ?><br><small class="muted"><?= e($a['actor_role']) ?></small></td>
        <td><?= e(audit_action_label($a['action'])) ?><?= $a['field'] ? '<br><small class="muted">' . e($a['field']) . '</small>' : '' ?></td>
        <td><?= $a['order_no'] ? '<a href="' . e(url('staff/order.php?id=' . $a['order_id'])) . '">' . e($a['order_no']) . '</a>' : '—' ?></td>
        <td class="small"><?= $a['old_value'] !== null ? e($a['old_value']) . ' → ' : '' ?><?= e($a['new_value']) ?></td>
        <td class="small"><?= e($a['reason']) ?></td>
        <td class="small muted mono"><?= e($a['ip_address']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_end();
