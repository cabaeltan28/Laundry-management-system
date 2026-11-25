<?php
/**
 * CASH CLOSING (end of shift). Staff counts the cash in the drawer; the system
 * compares it with the cash payments it recorded. Any shortage/overage goes
 * to the owner as a discrepancy.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

if (is_post()) {
    verify_csrf();
    $raw = str_replace(',', '', post('counted'));
    if ($raw === '' || !is_numeric($raw) || (float)$raw < 0) {
        flash('error', t('Type the amount of cash you counted.'));
    } else {
        $r = close_cash_drawer((float)$raw, post('note'), (int)$u['id']);
        if (abs($r['diff']) < 0.01) {
            flash('success', '✅ ' . t('Cash is complete. Thank you!'));
        } else {
            flash('error', ($r['diff'] < 0 ? t('Cash is SHORT by') : t('Cash is OVER by')) . ' ' . money(abs($r['diff'])) . '. ' . t('The owner was notified.'));
        }
    }
    redirect('staff/closing.php');
}

$start = cash_period_start();
$exp = expected_cash($start, now());
$history = q_all('SELECT k.*, u.full_name FROM cash_closings k LEFT JOIN users u ON u.id = k.closed_by ORDER BY k.id DESC LIMIT 15');

layout_start(t('Cash closing'), 'closing', ['subtitle' => t('Count the cash in the drawer at the end of your shift')]);
?>
<div class="grid g2">
  <div class="card">
    <h3>💵 <?= e(t('Count the cash')) ?></h3>
    <p class="muted small"><?= e(t('Since')) ?> <?= e(fmt_when($start)) ?> · <?= (int)$exp['count'] ?> <?= e(t('cash payment(s) recorded')) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label><?= e(t('How much cash is in the drawer? (do not include starting change)')) ?></label>
        <input type="number" name="counted" step="0.01" min="0" required class="code-input" style="letter-spacing:1px" placeholder="0.00" autofocus></div>
      <div class="field"><label><?= e(t('Note (if the amount is not exact, explain why)')) ?></label><input type="text" name="note"></div>
      <button class="btn btn-xl">✅ <?= e(t('Close cash')) ?></button>
    </form>
    <?php if ($u['role'] === 'owner'): ?>
      <p class="small muted mt"><?= e(t('Expected by the system')) ?>: <b><?= e(money($exp['total'])) ?></b> (<?= e(t('only the owner sees this before closing')) ?>)</p>
    <?php else: ?>
      <p class="small muted mt">🔒 <?= e(t('Count first. The system shows the expected amount after you close.')) ?></p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3><?= e(t('Recent closings')) ?></h3>
    <?php if (!$history): ?><?= empty_state('No closings yet') ?><?php endif; ?>
    <?php foreach ($history as $k): $d = (float)$k['difference']; ?>
      <div class="list-item">
        <div class="meta"><b><?= e(fmt_dt($k['created_at'], 'M j, g:i A')) ?> · <?= e($k['full_name']) ?></b>
          <small class="muted"><?= e(t('Expected')) ?> <?= e(money($k['expected_cash'])) ?> · <?= e(t('Counted')) ?> <?= e(money($k['counted_cash'])) ?><?= $k['note'] ? ' · ' . e($k['note']) : '' ?></small></div>
        <span class="badge <?= abs($d) < 0.01 ? 'pay-paid' : 'pay-unpaid' ?>"><?= abs($d) < 0.01 ? '✓ ' . e(t('Complete')) : ($d < 0 ? '−' : '+') . e(money(abs($d))) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php layout_end();
