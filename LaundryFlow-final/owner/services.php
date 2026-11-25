<?php
/**
 * Owner: services & pricing. Price changes are audited and only affect
 * NEW orders (existing orders keep the price agreed at drop-off).
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');

function service_input(): array
{
    $d = [
        'name' => post('name'),
        'description' => post('description'),
        'unit' => post('unit') === 'piece' ? 'piece' : 'kg',
        'price' => post('price'),
        'min_qty' => post('min_qty', '0'),
        'base_hours' => post('base_hours', '6'),
        'sort_order' => (int)post('sort_order', '0'),
    ];
    if (mb_strlen($d['name']) < 2) throw new RuntimeException('Enter the service name.');
    if (!is_numeric($d['price']) || (float)$d['price'] <= 0) throw new RuntimeException('Enter a valid price.');
    if (!is_numeric($d['min_qty']) || (float)$d['min_qty'] < 0) throw new RuntimeException('Minimum quantity must be 0 or more.');
    if (!is_numeric($d['base_hours']) || (float)$d['base_hours'] <= 0 || (float)$d['base_hours'] > 240) throw new RuntimeException('Turnaround hours must be between 0 and 240.');
    return $d;
}

if (is_post()) {
    verify_csrf();
    try {
        $action = post('action');
        if ($action === 'create') {
            $d = service_input();
            q('INSERT INTO services (name, description, unit, price, min_qty, base_hours, is_active, sort_order, created_at) VALUES (?,?,?,?,?,?,1,?,?)',
                [$d['name'], $d['description'] ?: null, $d['unit'], (float)$d['price'], (float)$d['min_qty'], (float)$d['base_hours'], $d['sort_order'], now()]);
            $sid = (int)db()->lastInsertId();
            audit('service_created', 'service', $sid, null, null, null, $d['name'] . ' ' . money($d['price']) . '/' . $d['unit']);
            flash('success', 'Service added.');
        } elseif ($action === 'update') {
            $id = (int)post('id');
            $s = get_service($id);
            if (!$s) throw new RuntimeException('Service not found.');
            $d = service_input();
            q('UPDATE services SET name = ?, description = ?, unit = ?, price = ?, min_qty = ?, base_hours = ?, sort_order = ?, updated_at = ? WHERE id = ?',
                [$d['name'], $d['description'] ?: null, $d['unit'], (float)$d['price'], (float)$d['min_qty'], (float)$d['base_hours'], $d['sort_order'], now(), $id]);
            foreach (['name' => 'name', 'unit' => 'unit', 'price' => 'price', 'min_qty' => 'min_qty', 'base_hours' => 'base_hours'] as $k => $col) {
                if ((string)$s[$col] != (string)$d[$k] && !(is_numeric($s[$col]) && (float)$s[$col] == (float)$d[$k])) {
                    audit('service_updated', 'service', $id, null, $k, $s[$col], $d[$k]);
                }
            }
            flash('success', 'Service updated. New prices apply to new orders only.');
        } elseif ($action === 'toggle') {
            $id = (int)post('id');
            $s = get_service($id);
            if (!$s) throw new RuntimeException('Service not found.');
            $new = (int)$s['is_active'] ? 0 : 1;
            q('UPDATE services SET is_active = ?, updated_at = ? WHERE id = ?', [$new, now(), $id]);
            audit('service_updated', 'service', $id, null, 'is_active', $s['is_active'] ? 'Active' : 'Hidden', $new ? 'Active' : 'Hidden');
            flash('success', $s['name'] . ($new ? ' is now available.' : ' is hidden from new orders.'));
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('owner/services.php');
}

$services = q_all('SELECT s.*, (SELECT COUNT(*) FROM orders o WHERE o.service_id = s.id) AS used FROM services s ORDER BY s.is_active DESC, s.sort_order, s.name');

function service_fields(array $s = []): string
{
    $v = fn($k, $d = '') => e($s[$k] ?? $d);
    $unit = $s['unit'] ?? 'kg';
    return '<div class="field"><label>Name</label><input type="text" name="name" value="' . $v('name') . '" required></div>
      <div class="field"><label>Description</label><input type="text" name="description" value="' . $v('description') . '"></div>
      <div class="row">
        <div class="field"><label>Priced per</label><select name="unit"><option value="kg"' . ($unit === 'kg' ? ' selected' : '') . '>Kilogram</option><option value="piece"' . ($unit === 'piece' ? ' selected' : '') . '>Piece</option></select></div>
        <div class="field"><label>Price (₱)</label><input type="number" step="0.01" min="0.01" name="price" value="' . $v('price') . '" required></div>
      </div>
      <div class="row">
        <div class="field"><label>Minimum qty</label><input type="number" step="0.1" min="0" name="min_qty" value="' . $v('min_qty', '0') . '"></div>
        <div class="field"><label>Turnaround (hours)</label><input type="number" step="0.5" min="0.5" name="base_hours" value="' . $v('base_hours', '6') . '"></div>
        <div class="field"><label>Sort order</label><input type="number" name="sort_order" value="' . $v('sort_order', '0') . '"></div>
      </div>';
}

layout_start('Services & Pricing', 'services', ['subtitle' => 'Price changes apply to new orders only — existing orders keep their agreed price']);
?>
<div class="grid g-main">
  <div class="card">
    <div class="table-wrap"><table>
      <thead><tr><th>Service</th><th class="num">Price</th><th class="num">Minimum</th><th class="num">Turnaround</th><th class="num">Orders</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($services as $s): ?>
        <tr style="<?= $s['is_active'] ? '' : 'opacity:.55' ?>">
          <td><b><?= e($s['name']) ?></b><?= $s['is_active'] ? '' : ' <span class="badge">Hidden</span>' ?><br><small class="muted"><?= e($s['description']) ?></small></td>
          <td class="num"><?= e(money($s['price'])) ?> / <?= $s['unit'] === 'kg' ? 'kg' : 'pc' ?></td>
          <td class="num"><?= (float)$s['min_qty'] > 0 ? e(qty_fmt($s['min_qty'], $s['unit'])) : '—' ?></td>
          <td class="num"><?= e((float)$s['base_hours']) ?> h</td>
          <td class="num"><?= (int)$s['used'] ?></td>
          <td class="nowrap">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-outline btn-sm"><?= $s['is_active'] ? 'Hide' : 'Show' ?></button></form>
            <details class="inline-form"><summary class="small">Edit</summary>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <?= service_fields($s) ?><button class="btn btn-sm">Save</button></form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="stack">
    <div class="card">
      <h3>Add service</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><?= service_fields() ?><button class="btn">Add service</button></form>
    </div>
    <div class="card">
      <h3>Rush pricing</h3>
      <p class="muted small">Rush orders add <b><?= e(setting('rush_percent', 25)) ?>%</b> and are processed about <?= e(round((1 - (float)setting('rush_speed_factor', 0.5)) * 100)) ?>% faster. Change this in <a href="<?= e(url('owner/settings.php')) ?>">Settings</a>.</p>
    </div>
  </div>
</div>
<?php layout_end();
