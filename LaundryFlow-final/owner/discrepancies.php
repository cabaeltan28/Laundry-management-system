<?php
/**
 * Owner review of suspicious changes (weight decreases, voids, unpaid releases...).
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');

if (is_post()) {
    verify_csrf();
    $id = (int)post('id');
    $decision = post('decision');
    $note = post('note');
    $return = post('return');
    if (!preg_match('#^(staff|owner)/[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $return)) {
        $return = 'owner/discrepancies.php';
    }
    $d = q_one('SELECT * FROM discrepancies WHERE id = ?', [$id]);
    if (!$d || $d['status'] !== 'open') {
        flash('error', 'This discrepancy was already reviewed.');
    } elseif (!in_array($decision, ['approved', 'flagged'], true)) {
        flash('error', 'Choose approve or flag.');
    } elseif ($decision === 'flagged' && $note === '') {
        flash('error', 'Add a note explaining why you are flagging this for investigation.');
    } else {
        q('UPDATE discrepancies SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?',
            [$decision, $u['id'], $note !== '' ? $note : null, now(), $id]);
        audit($decision === 'approved' ? 'discrepancy_approved' : 'discrepancy_flagged', 'discrepancy', $id, (int)$d['order_id'],
            $d['type'], $d['old_value'], $d['new_value'], $note !== '' ? $note : null);
        flash('success', $decision === 'approved' ? 'Adjustment approved.' : 'Flagged for investigation.');
    }
    redirect($return);
}

$tab = get('tab', 'open');
$where = in_array($tab, ['open', 'approved', 'flagged'], true) ? 'WHERE d.status = ?' : '';
$rows = q_all("SELECT d.*, o.order_no, c.full_name AS customer_name, u.full_name AS reporter, r.full_name AS reviewer
               FROM discrepancies d LEFT JOIN orders o ON o.id = d.order_id LEFT JOIN customers c ON c.id = o.customer_id
               LEFT JOIN users u ON u.id = d.reported_by LEFT JOIN users r ON r.id = d.reviewed_by
               $where ORDER BY d.id DESC LIMIT 300", $where ? [$tab] : []);
$byStaff = q_all("SELECT u.full_name, COUNT(*) AS n, SUM(CASE WHEN d.status = 'flagged' THEN 1 ELSE 0 END) AS flagged
                  FROM discrepancies d JOIN users u ON u.id = d.reported_by GROUP BY u.id, u.full_name ORDER BY n DESC");

layout_start('Discrepancies', 'discrepancies', ['subtitle' => 'Changes that need the owner\'s eyes — approve or flag each one']);
?>
<div class="tabs">
  <?php foreach (['open' => 'Open', 'flagged' => 'Flagged', 'approved' => 'Approved', 'all' => 'All'] as $k => $l): ?>
    <a class="<?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<div class="grid g-main">
  <div class="card">
    <?php if (!$rows): ?><?= empty_state($tab === 'open' ? 'No open discrepancies' : 'Nothing here', $tab === 'open' ? 'Every change has been reviewed.' : '') ?><?php endif; ?>
    <?php foreach ($rows as $d): ?>
      <div class="list-item" style="align-items:flex-start">
        <div class="meta" style="flex:1">
          <b><?= e(DISCREPANCY_LABELS[$d['type']] ?? $d['type']) ?> · <?php if ($d['order_id']): ?><a href="<?= e(url('staff/order.php?id=' . $d['order_id'])) ?>"><?= e($d['order_no']) ?></a><?php else: ?>💵 <a href="<?= e(url('staff/closing.php')) ?>">Cash drawer</a><?php endif; ?></b>
          <small class="muted"><?= e($d['customer_name'] ?? 'Cash closing') ?> · reported by <?= e($d['reporter'] ?? 'system') ?> · <?= e(fmt_dt($d['created_at'])) ?></small>
          <p class="mt0" style="margin-top:6px"><?= e($d['old_value']) ?> → <b><?= e($d['new_value']) ?></b><?= $d['difference'] ? ' <span class="badge b-open">' . e($d['difference']) . '</span>' : '' ?><?= $d['percent_change'] !== null ? ' <small class="muted">(' . e(number_format((float)$d['percent_change'], 1)) . '%)</small>' : '' ?></p>
          <small>Reason: “<?= e($d['reason']) ?>”</small>
          <?php if ($d['status'] !== 'open'): ?><br><small class="muted">Reviewed by <?= e($d['reviewer']) ?> <?= e(fmt_dt($d['reviewed_at'])) ?><?= $d['review_note'] ? ' · ' . e($d['review_note']) : '' ?></small><?php endif; ?>
          <?php if ($d['status'] === 'open'): ?>
            <form method="post" class="btn-row mt">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="return" value="owner/discrepancies.php?tab=<?= e($tab) ?>">
              <input type="text" name="note" placeholder="Review note" style="flex:1;min-width:160px">
              <button class="btn btn-warn btn-sm" name="decision" value="flagged">Flag</button>
              <button class="btn btn-sm" name="decision" value="approved">✓ Approve</button>
            </form>
          <?php endif; ?>
        </div>
        <span class="badge b-<?= e($d['status']) ?>"><?= e(strtoupper($d['status'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3>By employee</h3>
    <p class="muted small">A high number of changes or flags can indicate training needs or misconduct.</p>
    <?php if (!$byStaff): ?><p class="muted small">No discrepancies recorded.</p><?php endif; ?>
    <?php foreach ($byStaff as $s): ?>
      <div class="list-item"><span><?= e($s['full_name']) ?></span><span><?= (int)$s['n'] ?> total<?= (int)$s['flagged'] ? ' · <b style="color:var(--amber)">' . (int)$s['flagged'] . ' flagged</b>' : '' ?></span></div>
    <?php endforeach; ?>
  </div>
</div>
<?php layout_end();
