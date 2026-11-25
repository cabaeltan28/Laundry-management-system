<?php
/**
 * Owner: expenses (detergent, electricity, water, rent, salaries...) so
 * reports can show real PROFIT, not just sales.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');

const EXPENSE_CATEGORIES = ['Detergent & supplies', 'Electricity', 'Water', 'LPG / gas', 'Rent', 'Salaries', 'Repairs & maintenance', 'Plastic & packaging', 'Other'];

if (is_post()) {
    verify_csrf();
    try {
        if (post('action') === 'delete') {
            $x = q_one('SELECT * FROM expenses WHERE id = ?', [(int)post('id')]);
            if (!$x) throw new RuntimeException('Expense not found.');
            q('DELETE FROM expenses WHERE id = ?', [$x['id']]);
            audit('expense_deleted', 'expense', (int)$x['id'], null, $x['category'], money($x['amount']) . ' · ' . $x['expense_date'], 'Deleted');
            flash('success', 'Expense deleted (recorded in the audit log).');
        } else {
            $date = post('expense_date');
            $cat = post('category');
            $amount = (float)str_replace(',', '', post('amount'));
            if (!strtotime($date)) throw new RuntimeException('Choose a date.');
            if (!in_array($cat, EXPENSE_CATEGORIES, true)) throw new RuntimeException('Choose a category.');
            if ($amount <= 0) throw new RuntimeException('Enter the amount.');
            q('INSERT INTO expenses (expense_date, category, amount, note, created_by, created_at) VALUES (?,?,?,?,?,?)',
                [date('Y-m-d', strtotime($date)), $cat, round($amount, 2), post('note') ?: null, $u['id'], now()]);
            audit('expense_added', 'expense', (int)db()->lastInsertId(), null, $cat, null, money($amount) . ' · ' . $date);
            flash('success', 'Expense saved.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('owner/expenses.php?month=' . urlencode(get('month', date('Y-m', clock()))));
}

$month = preg_match('/^\d{4}-\d{2}$/', get('month')) ? get('month') : date('Y-m', clock());
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$rows = q_all('SELECT x.*, u.full_name FROM expenses x LEFT JOIN users u ON u.id = x.created_by WHERE expense_date >= ? AND expense_date <= ? ORDER BY expense_date DESC, id DESC', [$from, $to]);
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
$revenue = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND created_at >= ? AND created_at <= ?', [$from . ' 00:00:00', $to . ' 23:59:59']);
$byCat = [];
foreach ($rows as $r) $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float)$r['amount'];
arsort($byCat);
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));

layout_start('Expenses', 'expenses', [
    'subtitle' => date('F Y', strtotime($from)),
    'actions' => '<a class="btn btn-outline" href="?month=' . e($prev) . '">← ' . e(date('M', strtotime($prev . '-01'))) . '</a> <a class="btn btn-outline" href="?month=' . e($next) . '">' . e(date('M', strtotime($next . '-01'))) . ' →</a>',
]);
?>
<div class="grid g3 mb">
  <div class="card kpi"><small>Sales collected</small><div class="val"><?= e(money($revenue)) ?></div></div>
  <div class="card kpi"><small>Expenses</small><div class="val" style="color:var(--red)"><?= e(money($total)) ?></div></div>
  <div class="card kpi"><small>Profit</small><div class="val" style="color:<?= $revenue - $total >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= e(money($revenue - $total)) ?></div>
    <div class="sub"><?= $revenue > 0 ? 'Margin ' . round(($revenue - $total) / $revenue * 100) . '%' : '' ?></div></div>
</div>
<div class="grid g-main">
  <div class="card">
    <?php if (!$rows): ?><?= empty_state('No expenses this month', 'Add detergent, electricity, rent… to see real profit.') ?><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Category</th><th>Note</th><th>By</th><th class="num">Amount</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= e(date('M j', strtotime($r['expense_date']))) ?></td><td><?= e($r['category']) ?></td><td class="small"><?= e($r['note']) ?></td>
          <td class="small muted"><?= e($r['full_name']) ?></td><td class="num"><?= e(money($r['amount'])) ?></td>
          <td><details class="inline-form"><summary class="small">Delete</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger btn-sm">Confirm delete</button></form></details></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="stack">
    <div class="card">
      <h3>➕ Add expense</h3>
      <form method="post">
        <?= csrf_field() ?>
        <div class="row">
          <div class="field"><label>Date</label><input type="date" name="expense_date" value="<?= e(date('Y-m-d', clock())) ?>" required></div>
          <div class="field"><label>Amount (₱)</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
        </div>
        <div class="field"><label>Category</label><select name="category"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Note</label><input type="text" name="note" placeholder="e.g. 2 gallons Ariel"></div>
        <button class="btn">Save expense</button>
      </form>
    </div>
    <?php if ($byCat): $max = max($byCat); ?>
    <div class="card"><h3>By category</h3>
      <?php foreach ($byCat as $c => $amt): ?>
        <div class="hbar"><span class="small"><?= e($c) ?></span><div class="track"><span style="width:<?= round($amt / $max * 100) ?>%"></span></div><b class="right small"><?= e(money($amt)) ?></b></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
