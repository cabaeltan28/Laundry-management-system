<?php
/**
 * Owner notifications (discrepancy alerts) + log of SMS sent to customers.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');

$tab = get('tab', 'mine');
if ($tab === 'sms') {
    $rows = q_all("SELECT n.*, o.order_no FROM notifications n LEFT JOIN orders o ON o.id = n.order_id WHERE n.channel = 'sms_log' ORDER BY n.id DESC LIMIT 200");
} elseif ($tab === 'customers') {
    $rows = q_all("SELECT n.*, o.order_no, c.full_name AS customer_name FROM notifications n LEFT JOIN orders o ON o.id = n.order_id
                   LEFT JOIN customers c ON c.id = n.customer_id WHERE n.customer_id IS NOT NULL AND n.channel = 'in_app' ORDER BY n.id DESC LIMIT 200");
} else {
    $tab = 'mine';
    $rows = q_all("SELECT n.*, o.order_no FROM notifications n LEFT JOIN orders o ON o.id = n.order_id WHERE n.user_id = ? AND n.customer_id IS NULL ORDER BY n.id DESC LIMIT 200", [$u['id']]);
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND customer_id IS NULL AND is_read = 0', [$u['id']]);
}

layout_start('Notifications', 'notifications');
?>
<div class="tabs">
  <a class="<?= $tab === 'mine' ? 'active' : '' ?>" href="?tab=mine">My alerts</a>
  <a class="<?= $tab === 'customers' ? 'active' : '' ?>" href="?tab=customers">Sent to customers</a>
  <a class="<?= $tab === 'sms' ? 'active' : '' ?>" href="?tab=sms">SMS log</a>
</div>
<?php if ($tab === 'sms' && !SMS_ENABLED): ?>
  <div class="alert alert-info">SMS sending is in <b>simulation mode</b>. Messages are logged here but not sent. Set SMS_ENABLED and your API key in config.php to send real SMS.</div>
<?php endif; ?>
<div class="card">
  <?php if (!$rows): ?><?= empty_state("You're all caught up", 'No notifications here.') ?><?php endif; ?>
  <?php foreach ($rows as $n): ?>
    <div class="notif <?= ($tab === 'mine' && !$n['is_read']) ? 'unread' : '' ?>">
      <span class="ndot"><?= $n['type'] === 'discrepancy' ? '⚠' : '✉' ?></span>
      <div><b><?= e($n['title']) ?></b><?= isset($n['customer_name']) ? ' <small class="muted">→ ' . e($n['customer_name']) . '</small>' : '' ?><br>
        <span class="small muted"><?= e($n['message']) ?></span>
        <?php if ($n['order_id']): ?><br><a class="small" href="<?= e(url('staff/order.php?id=' . $n['order_id'])) ?>">Open <?= e($n['order_no']) ?></a><?php endif; ?></div>
      <span class="when"><?= e(fmt_dt($n['created_at'])) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_end();
