<?php
/**
 * Customer notifications (in-app). Opening this page marks them as read.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('customer');

$ids = customer_ids_for_user((int)$u['id']);
$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = q_all("SELECT n.*, o.order_no FROM notifications n LEFT JOIN orders o ON o.id = n.order_id
                   WHERE n.customer_id IN ($ph) AND n.channel = 'in_app' ORDER BY n.id DESC LIMIT 100", $ids);
    q("UPDATE notifications SET is_read = 1 WHERE customer_id IN ($ph) AND channel = 'in_app' AND is_read = 0", $ids);
}

layout_start(t('Updates'), 'notifications');
?>
<h1>🔔 <?= e(t('Updates')) ?></h1>
<p class="muted mb">Order updates, ready-for-pickup alerts and reminders</p>
<div class="card">
  <?php if (!$rows): ?><?= empty_state("You're all caught up", 'No notifications yet.') ?><?php endif; ?>
  <?php foreach ($rows as $n): ?>
    <div class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
      <span class="ndot"><?= $n['type'] === 'ready' || $n['type'] === 'reminder' ? '✓' : '✉' ?></span>
      <div><b><?= e($n['title']) ?></b><br><span class="small muted"><?= e($n['message']) ?></span>
        <?php if ($n['order_id']): ?><br><a class="small" href="<?= e(url('customer/order.php?id=' . $n['order_id'])) ?>">View <?= e($n['order_no']) ?></a><?php endif; ?></div>
      <span class="when"><?= e(time_ago($n['created_at'])) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_end();
