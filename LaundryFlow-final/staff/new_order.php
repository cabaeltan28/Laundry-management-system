<?php
/**
 * NEW ORDER — everything on ONE screen.
 *  - Type the mobile number: an existing customer is found automatically.
 *  - Or type the whole order in one line and press "Read order" (AI / offline reader).
 *  - Loyalty discount is applied automatically.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

$services = active_services();
$errors = [];
$preview = null;
$aiResult = null;

$f = [
    'customer_id' => (int)get('customer_id'),
    'customer_name' => '', 'phone' => '',
    'service_id' => (int)($services[0]['id'] ?? 0),
    'weight' => '', 'bag_count' => '1', 'is_rush' => false, 'notes' => '',
    'payment' => 'none', 'reference' => '', 'amount' => '', 'ai_text' => '',
];
// Prefill from an existing customer or "repeat order"
if ($f['customer_id'] && ($c = q_one('SELECT * FROM customers WHERE id = ?', [$f['customer_id']]))) {
    $f['customer_name'] = $c['full_name'];
    $f['phone'] = (string)$c['phone'];
}
if (($rid = (int)get('repeat')) && ($r = get_order($rid))) {
    $f['customer_id'] = (int)$r['customer_id'];
    $f['customer_name'] = $r['customer_name'];
    $f['phone'] = (string)$r['customer_phone'];
    $f['service_id'] = (int)$r['service_id'];
    $f['notes'] = (string)$r['notes'];
    $f['bag_count'] = (string)$r['bag_count'];
}

/** Find the customer by mobile number, or create a new one. */
function resolve_customer(array $f, int $actor, bool $create): ?array
{
    $phone = normalize_phone($f['phone']);
    if ($f['customer_id']) {
        $c = q_one('SELECT * FROM customers WHERE id = ?', [$f['customer_id']]);
        if ($c && (!$phone || $phone === $c['phone'])) {
            return $c;
        }
    }
    // Prefer the record linked to the customer's app account, so the order shows in their app
    if ($phone && ($c = q_one('SELECT * FROM customers WHERE phone = ? ORDER BY CASE WHEN user_id IS NULL THEN 1 ELSE 0 END, id LIMIT 1', [$phone]))) {
        return $c;
    }
    if (!$create) {
        return null;
    }
    q('INSERT INTO customers (full_name, phone, created_by, created_at) VALUES (?,?,?,?)',
        [mb_substr($f['customer_name'], 0, 120), $phone, $actor, now()]);
    $id = (int)db()->lastInsertId();
    audit('customer_created', 'customer', $id, null, null, null, $f['customer_name'] . ' · ' . ($phone ?: 'no phone'), null, $actor);
    return q_one('SELECT * FROM customers WHERE id = ?', [$id]);
}

if (is_post()) {
    verify_csrf();
    $action = post('action');
    foreach (['customer_name', 'phone', 'weight', 'notes', 'reference', 'amount', 'ai_text'] as $k) {
        $f[$k] = post($k);
    }
    $f['customer_id'] = (int)post('customer_id');
    $f['service_id'] = (int)post('service_id');
    $f['bag_count'] = post('bag_count', '1');
    $f['is_rush'] = post('is_rush') === '1';
    $f['payment'] = in_array(post('payment'), ['none', 'cash', 'gcash', 'maya', 'bank'], true) ? post('payment') : 'none';

    // ---- Read a typed order (AI or offline) ----
    if ($action === 'ai') {
        if ($f['ai_text'] === '') {
            $errors[] = t('Type the order first.');
        } else {
            $aiResult = ai_parse_order($f['ai_text']);
            $p = $aiResult['fields'];
            if ($p['customer_name'] !== '') $f['customer_name'] = $p['customer_name'];
            if ($p['phone'] !== '') { $f['phone'] = $p['phone']; $f['customer_id'] = 0; }
            if ($p['service_id']) $f['service_id'] = (int)$p['service_id'];
            if ($p['quantity'] !== '') $f['weight'] = (string)$p['quantity'];
            if ($p['bag_count'] !== '') $f['bag_count'] = (string)$p['bag_count'];
            $f['is_rush'] = (bool)$p['is_rush'];
            if ($p['payment'] !== '') $f['payment'] = $p['payment'];
            if ($p['reference'] !== '') $f['reference'] = $p['reference'];
            if ($p['notes'] !== '') $f['notes'] = $p['notes'];
            $action = 'preview';
        }
    }

    if (in_array($action, ['preview', 'save'], true)) {
        $service = get_service($f['service_id']);
        $weight = (float)$f['weight'];
        $phone = normalize_phone($f['phone']);
        if (!$service || !(int)$service['is_active']) $errors[] = t('Choose a service.');
        if ($f['weight'] !== '' && (!is_numeric($f['weight']) || $weight < 0 || $weight > 500)) $errors[] = t('Weight / quantity is not valid.');
        if ((int)$f['bag_count'] < 1 || (int)$f['bag_count'] > 50) $errors[] = t('Bags must be 1 to 50.');
        if ($f['phone'] !== '' && !$phone) $errors[] = t('Mobile number is not valid (example: 0917 123 4567).');
        $existing = resolve_customer($f, (int)$u['id'], false);
        if (!$existing && mb_strlen($f['customer_name']) < 2) $errors[] = t('Type the customer name.');
        if ($existing) {
            $f['customer_id'] = (int)$existing['id'];
            if ($f['customer_name'] === '') $f['customer_name'] = $existing['full_name'];
        }

        if (!$errors) {
            $disc = $existing ? loyalty_discount_for((int)$existing['id']) : 0.0;
            $price = calc_price($service, $weight, $f['is_rush'], $disc);
            $preview = [
                'customer' => $existing,
                'orders' => $existing ? (int)q_val('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$existing['id']]) : 0,
                'balance' => $existing ? (float)q_val("SELECT COALESCE(SUM(total - amount_paid),0) FROM orders WHERE customer_id = ? AND status <> 'cancelled'", [$existing['id']]) : 0,
                'price' => $price,
                'disc' => $disc,
                'text' => $weight > 0 ? price_breakdown_text($service, $weight, $f['is_rush'], $disc) : t('Weigh later — price will be computed after weighing'),
                'eta' => estimate_ready($service, $weight, $f['is_rush']),
            ];

            if ($action === 'save') {
                $payAmount = 0.0;
                if ($f['payment'] !== 'none') {
                    if ($weight <= 0) {
                        $errors[] = t('Enter the weight first before recording a payment.');
                    }
                    $payAmount = $f['amount'] === '' ? $price['total'] : (float)str_replace(',', '', $f['amount']);
                }
                if (!$errors) {
                    try {
                        $id = tx(function () use ($f, $u, $service, $weight, $payAmount) {
                            $cust = resolve_customer($f, (int)$u['id'], true);
                            return create_order([
                                'customer_id' => $cust['id'],
                                'service_id' => $service['id'],
                                'weight' => $weight,
                                'bag_count' => (int)$f['bag_count'],
                                'is_rush' => $f['is_rush'],
                                'notes' => $f['notes'],
                                'pay_amount' => $payAmount,
                                'pay_method' => $f['payment'] === 'none' ? 'cash' : $f['payment'],
                                'pay_ref' => $f['reference'],
                            ], (int)$u['id']);
                        });
                        $no = q_val('SELECT order_no FROM orders WHERE id = ?', [$id]);
                        flash('success', '✅ ' . t('Order saved') . ': ' . $no . ($payAmount > 0 ? ' · ' . t('Paid') . ' ' . money($payAmount) : ' · ' . t('Not paid yet')) . '. ' . t('Print the claim stub for the customer.'));
                        redirect('staff/receipt.php?id=' . $id . '&new=1');
                    } catch (RuntimeException $ex) {
                        $errors[] = $ex->getMessage();
                    }
                }
            }
        }
    }
}

$recent = q_all('SELECT c.id, c.full_name, c.phone, MAX(o.created_at) AS last_order FROM customers c JOIN orders o ON o.customer_id = c.id
                 GROUP BY c.id, c.full_name, c.phone ORDER BY last_order DESC LIMIT 6');
$payChoices = ['none' => ['🕒', 'Not paid yet'], 'cash' => ['💵', 'Cash'], 'gcash' => ['📱', 'GCash'], 'maya' => ['💳', 'Maya'], 'bank' => ['🏦', 'Bank transfer']];

layout_start(t('New order'), 'new_order', ['subtitle' => t('Fill in the boxes from top to bottom, then press SAVE ORDER.')]);
foreach ($errors as $er) {
    echo '<div class="alert alert-error">' . e($er) . '</div>';
}
?>
<form method="post" class="grid g-main">
  <div>
    <?= csrf_field() ?>
    <button name="action" value="preview" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px">.</button>
    <input type="hidden" name="customer_id" value="<?= (int)$f['customer_id'] ?>">

    <div class="ai-box">
      <div class="card-head" style="margin-bottom:6px"><b>✨ <?= e(t('Fast way: type the whole order in one line')) ?></b>
        <span class="ai-tag <?= ai_enabled() ? '' : 'off' ?>"><?= ai_enabled() ? 'AI' : e(t('Offline reader')) ?></span></div>
      <textarea name="ai_text" placeholder="<?= e(t('Example: Maria Santos 0917 123 4567, 8 kilo wash dry fold, rush, bayad na gcash')) ?>"><?= e($f['ai_text']) ?></textarea>
      <div class="btn-row mt"><button class="btn btn-outline" name="action" value="ai">✨ <?= e(t('Read order')) ?></button>
        <small class="muted"><?= e(t('It fills the form below. Always check before saving.')) ?></small></div>
      <?php if ($aiResult): $p = $aiResult['fields']; ?>
        <div class="understood">
          <?php if ($p['customer_name']): ?><span>👤 <?= e($p['customer_name']) ?></span><?php endif; ?>
          <?php if ($p['phone']): ?><span>📞 <?= e(phone_fmt($p['phone'])) ?></span><?php endif; ?>
          <?php if ($p['quantity'] !== ''): ?><span>⚖️ <?= e($p['quantity']) ?></span><?php endif; ?>
          <?php if ($p['is_rush']): ?><span>⚡ Rush</span><?php endif; ?>
          <?php if ($p['payment']): ?><span>💵 <?= e($p['payment']) ?></span><?php endif; ?>
          <?php if ($p['notes']): ?><span>📝 <?= e($p['notes']) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>1️⃣ <?= e(t('Customer')) ?></h3>
      <div class="row">
        <div class="field"><label>📞 <?= e(t('Mobile number')) ?></label><input type="tel" name="phone" value="<?= e($f['phone']) ?>" placeholder="09XX XXX XXXX" autofocus>
          <div class="hint"><?= e(t('Old customer? Just type the number and press CHECK PRICE — we find them.')) ?></div></div>
        <div class="field"><label>👤 <?= e(t('Customer name')) ?></label><input type="text" name="customer_name" value="<?= e($f['customer_name']) ?>"></div>
      </div>
      <?php if ($recent && !$f['customer_name'] && !$f['phone']): ?>
        <p class="small muted mt0"><?= e(t('Recent customers')) ?>:
          <?php foreach ($recent as $c): ?><a class="badge" style="margin:2px" href="?customer_id=<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?></a><?php endforeach; ?></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>2️⃣ <?= e(t('Service')) ?></h3>
      <div class="choice-grid big">
        <?php foreach ($services as $s): ?>
          <label class="choice"><input type="radio" name="service_id" value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $f['service_id'] ? 'checked' : '' ?>>
            <b><?= e($s['name']) ?></b><small><?= e(money($s['price'])) ?> / <?= $s['unit'] === 'kg' ? 'kg' : 'pc' ?><?= (float)$s['min_qty'] > 1 ? ' · min ' . e(qty_fmt($s['min_qty'], $s['unit'])) : '' ?></small></label>
        <?php endforeach; ?>
      </div>
      <div class="row mt">
        <div class="field"><label>⚖️ <?= e(t('Weight (kilo) or number of pieces')) ?></label><input type="number" name="weight" step="0.1" min="0" value="<?= e($f['weight']) ?>" placeholder="8.5" style="font-size:20px;font-weight:700">
          <div class="hint"><?= e(t('Leave empty if you will weigh later.')) ?></div></div>
        <div class="field"><label>🛍️ <?= e(t('Number of bags')) ?></label><input type="number" name="bag_count" min="1" max="50" value="<?= e($f['bag_count']) ?>" style="font-size:20px;font-weight:700"></div>
      </div>
      <div class="row">
        <label class="rush-box check"><input type="checkbox" name="is_rush" value="1" <?= $f['is_rush'] ? 'checked' : '' ?>>
          <span><b>⚡ <?= e(t('Rush order')) ?></b><br><small class="muted">+<?= e(setting('rush_percent', 25)) ?>% · <?= e(t('faster')) ?></small></span></label>
        <div class="field"><label>📝 <?= e(t('Special instructions')) ?></label><input type="text" name="notes" value="<?= e($f['notes']) ?>" placeholder="<?= e(t('e.g. unscented, separate whites')) ?>"></div>
      </div>
    </div>

    <div class="card">
      <h3>3️⃣ <?= e(t('Payment')) ?></h3>
      <div class="choice-grid big">
        <?php foreach ($payChoices as $k => [$ico, $label]): ?>
          <label class="choice"><input type="radio" name="payment" value="<?= e($k) ?>" <?= $f['payment'] === $k ? 'checked' : '' ?>><b><?= $ico ?> <?= e(t($label)) ?></b></label>
        <?php endforeach; ?>
      </div>
      <div class="row mt">
        <div class="field"><label><?= e(t('Reference no. (GCash/Maya/Bank)')) ?></label><input type="text" name="reference" value="<?= e($f['reference']) ?>"></div>
        <div class="field"><label><?= e(t('Amount received (empty = full)')) ?></label><input type="number" name="amount" step="0.01" min="0" value="<?= e($f['amount']) ?>"></div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card" style="position:sticky;top:90px">
      <h3>🧾 <?= e(t('Summary')) ?></h3>
      <?php if ($preview): ?>
        <?php if ($preview['customer']): ?>
          <div class="alert alert-success">👤 <?= e(t('Old customer')) ?>: <b><?= e($preview['customer']['full_name']) ?></b> · <?= (int)$preview['orders'] ?> <?= e(t('orders')) ?>
            <?= $preview['balance'] > 0 ? '<br>⚠️ ' . e(t('Unpaid balance')) . ': <b>' . e(money($preview['balance'])) . '</b>' : '' ?></div>
        <?php else: ?>
          <div class="alert alert-info">🆕 <?= e(t('New customer — will be saved automatically.')) ?></div>
        <?php endif; ?>
        <?php if ($preview['disc'] > 0): ?><div class="alert alert-success">🎁 <?= e(t('Loyalty reward!')) ?> −<?= e((float)$preview['disc']) ?>%</div><?php endif; ?>
        <div class="total-box"><small><?= e($preview['text']) ?></small><div class="amount"><?= e(money($preview['price']['total'])) ?></div></div>
        <div class="eta"><div><small class="muted"><?= e(t('READY BY')) ?></small><br><b><?= e(fmt_when($preview['eta'])) ?></b></div><?= $f['is_rush'] ? '<span class="badge b-rush">⚡ RUSH</span>' : '' ?></div>
      <?php else: ?>
        <p class="muted"><?= e(t('Press CHECK PRICE to see the total and the ready time.')) ?></p>
      <?php endif; ?>
      <button class="btn btn-outline btn-block btn-big mb" name="action" value="preview">🧮 <?= e(t('Check price')) ?></button>
      <button class="btn btn-xl" name="action" value="save" accesskey="z">💾 <?= e(t('Save order')) ?></button>
      <p class="small muted center mt"><kbd>Alt+Z</kbd> <?= e(t('Save order')) ?></p>
    </div>
    <?= workload_card(workload(), false) ?>
  </div>
</form>
<?php layout_end();
