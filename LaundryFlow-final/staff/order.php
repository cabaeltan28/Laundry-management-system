<?php
/**
 * Order detail for employees and owner: status, edits, payments, pickup,
 * discrepancies, timeline and full audit history.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);
$isOwner = $u['role'] === 'owner';

$id = (int)get('id');
$o = get_order($id);
if (!$o) {
    flash('error', 'Order not found.');
    redirect('staff/orders.php');
}
$services = q_all('SELECT * FROM services WHERE is_active = 1 OR id = ? ORDER BY sort_order, name', [$o['service_id']]);
$payments = q_all('SELECT p.*, u.full_name AS received_name, v.full_name AS voided_name FROM payments p
                   LEFT JOIN users u ON u.id = p.received_by LEFT JOIN users v ON v.id = p.voided_by
                   WHERE p.order_id = ? ORDER BY p.id', [$id]);
$timeline = order_timeline($id);
$auditRows = q_all('SELECT a.*, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                    WHERE a.order_id = ? ORDER BY a.id DESC', [$id]);
$discs = q_all('SELECT d.*, u.full_name AS reporter, r.full_name AS reviewer FROM discrepancies d
                LEFT JOIN users u ON u.id = d.reported_by LEFT JOIN users r ON r.id = d.reviewed_by
                WHERE d.order_id = ? ORDER BY d.id DESC', [$id]);
$notifs = q_all('SELECT * FROM notifications WHERE order_id = ? AND customer_id IS NOT NULL ORDER BY id DESC LIMIT 10', [$id]);

$balance = round((float)$o['total'] - (float)$o['amount_paid'], 2);
$next = next_status($o['status']);
$prev = prev_status($o['status']);
$closed = in_array($o['status'], ['claimed', 'cancelled'], true);
$unit = $o['service_unit'];
$self = 'staff/order.php?id=' . $id;

function action_form(int $id, string $action, string $self, string $inner): string
{
    return '<form method="post" action="' . e(url('staff/order_action.php')) . '">' . csrf_field() .
        '<input type="hidden" name="order_id" value="' . $id . '"><input type="hidden" name="action" value="' . e($action) . '">' .
        '<input type="hidden" name="return" value="' . e($self) . '">' . $inner . '</form>';
}

$actions = '<a class="btn btn-outline" href="' . e(url('staff/new_order.php?repeat=' . $id)) . '">🔁 ' . e(t('Repeat order')) . '</a> <a class="btn btn-outline" href="' . e(url('staff/receipt.php?id=' . $id)) . '">🖨️ ' . e(t('Claim stub')) . '</a>';
layout_start('Order ' . $o['order_no'], 'orders', [
    'subtitle' => 'Created ' . fmt_dt($o['created_at'], 'j M Y · g:i A') . ' by ' . ($o['created_by_name'] ?? 'system'),
    'actions' => $actions,
]);

if (get('created')): ?>
  <div class="alert alert-info"><b>Next:</b> open the <a href="<?= e(url('staff/receipt.php?id=' . $id)) ?>">claim stub</a> and print it (Ctrl+P) for the customer. It has their pickup code and tracking code.</div>
<?php endif; ?>

<div class="section-title">
  <div>
    <h2 class="mt0"><?= e($o['customer_name']) ?> <?= status_badge($o['status']) ?> <?= $o['is_rush'] ? '<span class="badge b-rush">RUSH</span>' : '' ?><?= demo_badge($o) ?></h2>
    <p class="muted"><?= e($o['service_name']) ?> · <?= e(qty_fmt($o['weight'], $unit)) ?> · <?= (int)$o['bag_count'] ?> <?= e(t('bag(s)')) ?> · <?= e(phone_fmt($o['customer_phone'])) ?></p>
  </div>
  <?php if (!$closed) echo action_form($id, 'resend', $self, '<button class="btn btn-outline">✉ ' . e(t('Send update to customer')) . '</button>'); ?>
</div>

<?php foreach ($discs as $d): if ($d['status'] !== 'open') continue; ?>
  <div class="card disc-card mb">
    <div class="card-head"><div><h3>⚠ <?= e(DISCREPANCY_LABELS[$d['type']] ?? $d['type']) ?> — owner review required</h3>
      <p class="muted">Reported by <?= e($d['reporter'] ?? 'system') ?> · <?= e(fmt_dt($d['created_at'])) ?></p></div><span class="badge b-open">OPEN</span></div>
    <div class="disc-cmp">
      <div class="box"><small class="muted">BEFORE</small><b><?= e($d['old_value']) ?></b></div><span class="arrow">➜</span>
      <div class="box"><small class="muted">AFTER</small><b><?= e($d['new_value']) ?></b></div>
      <?php if ($d['difference'] !== null): ?><div class="box diff"><small>DIFFERENCE</small><b><?= e($d['difference']) ?></b><?= $d['percent_change'] !== null ? '<small>' . e(number_format((float)$d['percent_change'], 1)) . '%</small>' : '' ?></div><?php endif; ?>
    </div>
    <p class="small">Staff reason: “<?= e($d['reason']) ?>”</p>
    <?php if ($isOwner): ?>
      <form method="post" action="<?= e(url('owner/discrepancies.php')) ?>" class="btn-row">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="return" value="<?= e($self) ?>">
        <input type="text" name="note" placeholder="Review note (required to flag)" style="flex:1;min-width:200px">
        <button class="btn btn-warn" name="decision" value="flagged">Flag for investigation</button>
        <button class="btn" name="decision" value="approved">✓ Approve adjustment</button>
      </form>
    <?php else: ?>
      <p class="small muted">The owner has been notified and will review this change.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3><?= e(t('Order status')) ?></h3><?= $o['is_rush'] ? '<span class="badge b-rush">RUSH</span>' : '' ?></div>
      <?= order_stepper($o['status']) ?>
      <?php if (!$closed): ?>
        <div class="list-item mt">
          <div class="meta"><small class="muted"><?= e(t('NOW')) ?></small><b><?= e(status_label($o['status'])) ?></b>
            <small class="muted"><?= e(t('Ready by')) ?>: <?= e(fmt_when($o['estimated_ready_at'])) ?></small></div>
          <?php if ($next && (float)$o['weight'] > 0): ?>
            <?= action_form($id, 'advance', $self, '<input type="hidden" name="to" value="' . e($next) . '"><button class="btn btn-big">→ ' . e(t('Done, move to')) . ' ' . e(status_label($next)) . '</button>') ?>
          <?php elseif ((float)$o['weight'] <= 0): ?>
            <span class="badge pay-unpaid"><?= e(t('Enter weight below to continue')) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($prev): ?>
          <details class="inline-form"><summary>↶ <?= e(t('Move back to')) ?> <?= e(status_label($prev)) ?></summary>
            <?= action_form($id, 'revert', $self, '<div class="row"><input type="text" name="reason" placeholder="' . e(t('Reason (required)')) . '" required><button class="btn btn-outline">' . e(t('Move back')) . '</button></div>' . ($isOwner ? '' : '<p class="hint small muted">Moving back is reported to the owner.</p>')) ?>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><div><h3><?= e(t('Order information')) ?></h3></div></div>
      <div class="info-grid">
        <div class="info"><small><?= e(t('Customer')) ?></small><b><?= e($o['customer_name']) ?></b><span><?= e(phone_fmt($o['customer_phone'])) ?></span></div>
        <div class="info"><small><?= e(t('Service')) ?></small><b><?= e($o['service_name']) ?></b><span><?= e(money($o['unit_price'])) ?>/<?= $unit === 'kg' ? 'kg' : 'pc' ?></span></div>
        <div class="info"><small><?= e(t('Weight / qty')) ?></small><b><?= e(qty_fmt($o['weight'], $unit)) ?></b><span><?= e(t('First entry')) ?>: <?= e(qty_fmt($o['initial_weight'], $unit)) ?></span></div>
        <div class="info"><small><?= e(t('Price')) ?></small><b><?= e(money($o['total'])) ?></b><span><?= (float)$o['rush_percent'] > 0 ? '+' . e((float)$o['rush_percent']) . '% rush' : ((float)$o['discount_percent'] > 0 ? '🎁 −' . e((float)$o['discount_percent']) . '% loyalty' : e(t('Standard'))) ?></span></div>
        <div class="info"><small><?= e(t('Payment')) ?></small><b><?= pay_badge($o['payment_status']) ?></b><span><?= e(t('Paid')) ?> <?= e(money($o['amount_paid'])) ?><?= $balance > 0 ? ' · ' . e(t('Balance')) . ' ' . e(money($balance)) : '' ?></span></div>
        <div class="info"><small><?= e(t('Ready by')) ?></small><b><?= e(fmt_when($o['estimated_ready_at'])) ?></b><span><?= $o['ready_at'] ? e(t('Finished')) . ' ' . e(fmt_dt($o['ready_at'])) : '' ?></span></div>
      </div>
      <?php if ($o['notes']): ?><p class="mt small"><b>Instructions:</b> <?= e($o['notes']) ?></p><?php endif; ?>
      <?php if ($o['cancel_reason']): ?><p class="mt small" style="color:var(--red)"><b>Cancelled:</b> <?= e($o['cancel_reason']) ?></p><?php endif; ?>

      <?php if (!$closed): ?>
      <details class="inline-form" <?= (float)$o['weight'] <= 0 ? 'open' : '' ?>><summary>✎ <?= e(t('Change weight, service or details')) ?></summary>
        <form method="post" action="<?= e(url('staff/order_action.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="return" value="<?= e($self) ?>">
          <div class="row">
            <div class="field"><label><?= e(t('Service')) ?></label><select name="service_id">
              <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$o['service_id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(money($s['price'])) ?>/<?= $s['unit'] === 'kg' ? 'kg' : 'pc' ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="field"><label><?= e(t('Weight (kilo) or number of pieces')) ?></label><input type="number" step="0.1" min="0" name="weight" value="<?= e((float)$o['weight']) ?>" required></div>
            <div class="field"><label><?= e(t('Number of bags')) ?></label><input type="number" min="1" max="50" name="bag_count" value="<?= (int)$o['bag_count'] ?>"></div>
          </div>
          <div class="field"><label><?= e(t('Special instructions')) ?></label><input type="text" name="notes" value="<?= e($o['notes']) ?>"></div>
          <label class="check mb"><input type="checkbox" name="is_rush" value="1" <?= $o['is_rush'] ? 'checked' : '' ?>> ⚡ <?= e(t('Rush order')) ?></label>
          <div class="field"><label><?= e(t('Reason for the change (required)')) ?></label><input type="text" name="reason" required placeholder="<?= (float)$o['weight'] <= 0 ? 'e.g. Initial weighing' : 'e.g. Re-weighed without customer bag' ?>"></div>
          <button class="btn btn-big">💾 <?= e(t('Save changes')) ?></button>
          <p class="hint small muted">Weight decreases, large changes (≥<?= e(setting('discrepancy_threshold_percent', 10)) ?>%), price decreases and changes after payment are sent to the owner for review. The customer is notified of weight/price changes.</p>
        </form>
      </details>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><div><h3>💵 <?= e(t('Payments')) ?></h3></div>
        <div class="total-box"><small><?= e(t('Balance')) ?></small><b style="font-size:20px;color:<?= $balance > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= e(money(max(0, $balance))) ?></b></div></div>
      <?php if ($payments): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Time</th><th>Method</th><th>Reference</th><th>Received by</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr style="<?= $p['is_void'] ? 'opacity:.6' : '' ?>">
            <td class="nowrap"><?= e(fmt_dt($p['created_at'])) ?></td>
            <td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
            <td class="mono small"><?= e($p['reference_no'] ?: '—') ?></td>
            <td><?= e($p['received_name']) ?></td>
            <td class="num"><?= $p['is_void'] ? '<s>' . e(money($p['amount'])) . '</s>' : e(money($p['amount'])) ?></td>
            <td class="num"><?php if ($p['is_void']): ?><span class="badge st-cancelled" title="<?= e($p['void_reason']) ?>">Voided by <?= e($p['voided_name']) ?></span>
              <?php elseif (!$closed || $isOwner): ?>
                <details class="inline-form"><summary class="small"><?= e(t('Void')) ?></summary>
                  <?= action_form($id, 'void', $self, '<input type="hidden" name="payment_id" value="' . (int)$p['id'] . '"><input type="text" name="reason" placeholder="' . e(t('Reason (required)')) . '" required style="min-width:150px"><button class="btn btn-danger btn-sm mt">Void payment</button>') ?>
                </details>
              <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p class="muted"><?= e(t('No payments yet.')) ?></p><?php endif; ?>
      <?php if ($balance > 0 && $o['status'] !== 'cancelled'): ?>
        <form method="post" action="<?= e(url('staff/order_action.php')) ?>" class="mt">
          <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="return" value="<?= e($self) ?>">
          <div class="row">
            <div class="field"><label><?= e(t('Amount')) ?></label><input type="number" step="0.01" min="0.01" max="<?= e($balance) ?>" name="amount" value="<?= e(number_format($balance, 2, '.', '')) ?>" required></div>
            <div class="field"><label><?= e(t('Paid with')) ?></label><select name="method"><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label><?= e(t('Reference no. (GCash/Maya/Bank)')) ?></label><input type="text" name="reference"></div>
          </div>
          <button class="btn btn-big">💵 <?= e(t('Record payment')) ?></button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><div><h3>🛡️ <?= e(t('Change history')) ?></h3><p class="muted"><?= e(t('Cannot be edited or deleted')) ?></p></div></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Time</th><th>Actor</th><th>Event</th><th>Change</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($auditRows as $a): ?>
          <tr>
            <td class="nowrap"><?= e(fmt_dt($a['created_at'], 'M j, g:i A')) ?></td>
            <td><?= e($a['actor_name'] ?? 'System') ?></td>
            <td><?= e(audit_action_label($a['action'])) ?><?= $a['field'] ? ' <small class="muted">(' . e($a['field']) . ')</small>' : '' ?></td>
            <td class="small"><?= $a['old_value'] !== null ? e($a['old_value']) . ' → ' : '' ?><?= e($a['new_value']) ?></td>
            <td class="small"><?= $a['reason'] === 'Automatic' ? '<span class="badge b-auto">Automatic</span>' : e($a['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="stack">
    <?php if ($o['status'] === 'ready'): ?>
    <div class="card">
      <h3>✅ <?= e(t('Release laundry')) ?></h3>
      <p class="muted small">Release only after matching the customer's 4-digit pickup code.</p>
      <form method="post" action="<?= e(url('staff/order_action.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="return" value="<?= e($self) ?>">
        <div class="field"><label><?= e(t('Pickup code from customer')) ?></label><input type="text" name="pickup_code" inputmode="numeric" maxlength="6" class="mono code-box" style="font-size:22px" required></div>
        <?php if ($balance > 0): ?>
          <div class="alert alert-warn">Balance of <?= e(money($balance)) ?> is unpaid. Record the payment first, or release with a reason (reported to owner).</div>
          <label class="check"><input type="checkbox" name="release_unpaid" value="1"> <?= e(t('Release even if not fully paid')) ?></label>
          <div class="field mt"><input type="text" name="reason" placeholder="<?= e(t('Reason (required)')) ?>"></div>
        <?php endif; ?>
        <button class="btn btn-xl">✅ <?= e(t('Release laundry')) ?></button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3><?= e(t('Status timeline')) ?></h3>
      <p class="muted small">End-to-end processing history</p>
      <?php $seen = array_column($timeline, 'status'); ?>
      <ul class="timeline">
        <?php foreach ($timeline as $t): ?>
          <li><span class="tdot">✓</span><div><b><?= e(status_label($t['status'])) ?></b><small><?= e($t['actor_name'] ?? 'System') ?><?= $t['note'] ? ' · ' . e($t['note']) : '' ?></small></div><small><?= e(fmt_dt($t['created_at'], 'M j g:i A')) ?></small></li>
        <?php endforeach; ?>
        <?php if (!$closed): foreach (STATUS_FLOW as $s): if (in_array($s, $seen, true)) continue; ?>
          <li class="pending"><span class="tdot"></span><div><b><?= e(status_label($s)) ?></b><small>—</small></div><small><?= e(t('Pending')) ?></small></li>
        <?php endforeach; endif; ?>
      </ul>
    </div>

    <div class="card">
      <h3><?= e(t('Pickup information')) ?></h3>
      <div class="info-grid">
        <div class="info"><small><?= e(t('Pickup code')) ?></small><b class="code-box" style="font-size:24px"><?= e($o['pickup_code']) ?></b><span><?= $o['status'] === 'ready' ? 'Ready now' : ($o['status'] === 'claimed' ? 'Claimed ' . e(fmt_dt($o['claimed_at'])) : 'Not yet ready') ?></span></div>
        <div class="info"><small><?= e(t('Tracking code')) ?></small><b class="mono"><?= e($o['tracking_token']) ?></b><span><?= e(t('For online tracking')) ?></span></div>
      </div>
      <?php if ((int)$o['reminder_count'] > 0): ?><p class="small muted mt">Pickup reminders sent: <?= (int)$o['reminder_count'] ?> (last <?= e(fmt_dt($o['last_reminder_at'])) ?>)</p><?php endif; ?>
    </div>

    <div class="card">
      <h3><?= e(t('Customer notifications')) ?></h3>
      <?php if (!$notifs): ?><p class="muted small">None yet.</p><?php endif; ?>
      <?php foreach ($notifs as $n): ?>
        <div class="activity"><div><b class="small"><?= e($n['title']) ?></b><br><small class="muted"><?= e($n['message']) ?></small></div><span class="when"><?= e(fmt_dt($n['created_at'], 'g:i A')) ?></span></div>
      <?php endforeach; ?>
    </div>

    <?php if (!$closed): ?>
    <div class="card">
      <details class="inline-form"><summary style="color:var(--red)"><?= e(t('Cancel this order')) ?></summary>
        <?= action_form($id, 'cancel', $self, '<div class="field"><input type="text" name="reason" placeholder="' . e(t('Reason (required)')) . '" required></div><button class="btn btn-danger">' . e(t('Cancel order')) . '</button>' . ($isOwner ? '' : '<p class="small muted mt">Cancellations by staff are reported to the owner.</p>')) ?>
      </details>
    </div>
    <?php endif; ?>

    <?php if (array_filter($discs, fn($d) => $d['status'] !== 'open')): ?>
    <div class="card"><h3>Reviewed discrepancies</h3>
      <?php foreach ($discs as $d): if ($d['status'] === 'open') continue; ?>
        <div class="activity"><div><b class="small"><?= e(DISCREPANCY_LABELS[$d['type']] ?? $d['type']) ?>: <?= e($d['old_value']) ?> → <?= e($d['new_value']) ?></b><br>
          <small class="muted"><span class="badge b-<?= e($d['status']) ?>"><?= e(ucfirst($d['status'])) ?></span> by <?= e($d['reviewer']) ?><?= $d['review_note'] ? ' · ' . e($d['review_note']) : '' ?></small></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
