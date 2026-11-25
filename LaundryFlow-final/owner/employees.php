<?php
/**
 * Owner: manage employee accounts (add, edit, deactivate, reset password/PIN)
 * and see each employee's activity.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('owner');

if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        if ($action === 'create') {
            $name = post('full_name');
            $username = post('username');
            $phone = normalize_phone(post('phone'));
            $role = post('role') === 'owner' ? 'owner' : 'employee';
            $pw = $_POST['password'] ?? '';
            $pin = post('pin');
            if (mb_strlen($name) < 3) throw new RuntimeException('Enter the full name.');
            if (!preg_match('/^[A-Za-z0-9_.]{4,30}$/', $username)) throw new RuntimeException('Username must be 4–30 letters, numbers, dots or underscores.');
            if (q_val('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) throw new RuntimeException('Username already taken.');
            if (post('phone') !== '' && !$phone) throw new RuntimeException('Invalid mobile number.');
            if ($p = password_problem($pw)) throw new RuntimeException($p);
            if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) throw new RuntimeException('PIN must be 4–6 digits.');
            q('INSERT INTO users (role, full_name, username, phone, password_hash, pin_hash, lang, is_active, created_at) VALUES (?,?,?,?,?,?,?,1,?)',
                [$role, $name, $username, $phone, password_hash($pw, PASSWORD_DEFAULT), $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null,
                 $role === 'employee' ? (string)setting('staff_default_lang', 'tl') : 'en', now()]);
            $nid = (int)db()->lastInsertId();
            audit('employee_created', 'user', $nid, null, 'role', null, $name . ' (' . $role . ')');
            flash('success', $name . ' was added. Give them their username and temporary password.');
        } elseif ($action === 'toggle') {
            $id = (int)post('id');
            if ($id === (int)$u['id']) throw new RuntimeException('You cannot deactivate your own account.');
            $emp = q_one("SELECT * FROM users WHERE id = ? AND role IN ('employee','owner')", [$id]);
            if (!$emp) throw new RuntimeException('Employee not found.');
            $new = (int)$emp['is_active'] ? 0 : 1;
            q('UPDATE users SET is_active = ? WHERE id = ?', [$new, $id]);
            audit('employee_updated', 'user', $id, null, 'is_active', $emp['is_active'] ? 'Active' : 'Inactive', $new ? 'Active' : 'Inactive');
            flash('success', $emp['full_name'] . ($new ? ' reactivated.' : ' deactivated. They can no longer log in.'));
        } elseif ($action === 'reset') {
            $id = (int)post('id');
            $emp = q_one("SELECT * FROM users WHERE id = ? AND role IN ('employee','owner')", [$id]);
            if (!$emp) throw new RuntimeException('Employee not found.');
            $pw = $_POST['password'] ?? '';
            $pin = post('pin');
            if ($pw === '' && $pin === '') throw new RuntimeException('Enter a new password and/or PIN.');
            if ($pw !== '') {
                if ($p = password_problem($pw)) throw new RuntimeException($p);
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            }
            if ($pin !== '') {
                if (!preg_match('/^\d{4,6}$/', $pin)) throw new RuntimeException('PIN must be 4–6 digits.');
                q('UPDATE users SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
            }
            audit('employee_updated', 'user', $id, null, 'credentials', null, ($pw !== '' ? 'password reset ' : '') . ($pin !== '' ? 'PIN reset' : ''));
            flash('success', 'Credentials updated for ' . $emp['full_name'] . '.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('owner/employees.php');
}

$month = date('Y-m-01 00:00:00');
$staff = q_all("SELECT u.*,
    (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id AND o.created_at >= ?) AS orders_month,
    (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.received_by = u.id AND p.is_void = 0 AND p.created_at >= ?) AS collected_month,
    (SELECT COUNT(*) FROM audit_logs a WHERE a.actor_id = u.id AND a.action = 'status_changed' AND a.created_at >= ?) AS steps_month,
    (SELECT COUNT(*) FROM discrepancies d WHERE d.reported_by = u.id AND d.created_at >= ?) AS disc_month
  FROM users u WHERE u.role IN ('employee','owner') ORDER BY u.is_active DESC, u.role DESC, u.full_name", [$month, $month, $month, $month]);

layout_start('Employees', 'employees', ['subtitle' => 'Accounts and this month\'s activity']);
?>
<div class="grid g-main">
  <div class="card">
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th class="num">Orders</th><th class="num">Collected</th><th class="num">Status updates</th><th class="num">Discrepancies</th><th>Last login</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($staff as $s): ?>
        <tr style="<?= $s['is_active'] ? '' : 'opacity:.55' ?>">
          <td><b><?= e($s['full_name']) ?></b><br><small class="muted"><?= e(phone_fmt($s['phone'])) ?></small></td>
          <td class="mono small"><?= e($s['username']) ?></td>
          <td><?= $s['role'] === 'owner' ? '<span class="badge st-folding">Owner</span>' : '<span class="badge">Staff</span>' ?><?= $s['is_active'] ? '' : ' <span class="badge st-cancelled">Inactive</span>' ?></td>
          <td class="num"><?= (int)$s['orders_month'] ?></td>
          <td class="num"><?= e(money($s['collected_month'])) ?></td>
          <td class="num"><?= (int)$s['steps_month'] ?></td>
          <td class="num"><?= (int)$s['disc_month'] ? '<b style="color:var(--red)">' . (int)$s['disc_month'] . '</b>' : '0' ?></td>
          <td class="small muted nowrap"><?= e($s['last_login_at'] ? fmt_dt($s['last_login_at']) : 'Never') ?></td>
          <td class="nowrap">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('owner/audit.php?actor=' . $s['id'])) ?>">Activity</a>
            <?php if ((int)$s['id'] !== (int)$u['id']): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-outline btn-sm"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
            <?php endif; ?>
            <details class="inline-form"><summary class="small">Reset password / PIN</summary>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <div class="field"><input type="password" name="password" placeholder="New password (optional)" aria-label="New password"></div>
                <div class="field"><input type="password" name="pin" placeholder="New PIN (optional)" inputmode="numeric" aria-label="New PIN"></div>
                <button class="btn btn-sm">Update</button></form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h3>Add employee</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="field"><label>Full name</label><input type="text" name="full_name" required></div>
      <div class="field"><label>Mobile number</label><input type="tel" name="phone"></div>
      <div class="field"><label>Username</label><input type="text" name="username" required></div>
      <div class="field"><label>Temporary password</label><input type="password" name="password" required><div class="hint">8+ characters with letters and numbers. Ask them to change it.</div></div>
      <div class="field"><label>Quick-switch PIN (optional)</label><input type="password" name="pin" inputmode="numeric" maxlength="6"></div>
      <div class="field"><label>Role</label><select name="role"><option value="employee">Employee / counter staff</option><option value="owner">Owner / manager (full access)</option></select></div>
      <button class="btn">Add employee</button>
    </form>
  </div>
</div>
<?php layout_end();
