<?php
/**
 * DEMO DATA (Owner > Settings).
 *
 *  load_demo_data()   – one week of realistic orders, payments, status history,
 *                       cash closings, expenses and a weight discrepancy.
 *                       Made with the real business functions so everything is consistent.
 *  delete_demo_data() – removes everything marked is_demo = 1, at any time,
 *                       without touching real data.
 */

const DEMO_TABLES = ['customers', 'orders', 'payments', 'discrepancies', 'notifications', 'expenses', 'cash_closings'];

function demo_counts(): array
{
    return [
        'orders'    => (int)q_val('SELECT COUNT(*) FROM orders WHERE is_demo = 1'),
        'customers' => (int)q_val('SELECT COUNT(*) FROM customers WHERE is_demo = 1'),
        'users'     => (int)q_val("SELECT COUNT(*) FROM users WHERE is_demo = 1 AND role <> 'owner'"),
        'expenses'  => (int)q_val('SELECT COUNT(*) FROM expenses WHERE is_demo = 1'),
    ];
}

function load_demo_data(): int
{
    if ((int)q_val('SELECT COUNT(*) FROM orders WHERE is_demo = 1') > 0) {
        throw new RuntimeException('Demo data is already loaded. Delete it first if you want fresh demo data.');
    }
    // Highest ids before the demo starts: everything above them is demo data
    $before = [];
    foreach (DEMO_TABLES as $t) {
        $before[$t] = (int)q_val("SELECT COALESCE(MAX(id), 0) FROM $t");
    }
    $hasRealPayments = (int)q_val('SELECT COUNT(*) FROM payments WHERE is_demo = 0') > 0;
    $GLOBALS['LF_DEMO'] = true;
    $realNow = time();

    try {
        // Staff: demo employees if available, otherwise any active employee, otherwise the owner
        $staff = array_map('intval', array_column(q_all("SELECT id FROM users WHERE role = 'employee' AND is_active = 1 AND is_demo = 1"), 'id'));
        if (!$staff) $staff = array_map('intval', array_column(q_all("SELECT id FROM users WHERE role = 'employee' AND is_active = 1"), 'id'));
        if (!$staff) $staff = [(int)user_id()];

        // Demo customers
        $names = [
            ['Marites Dela Cruz', '09171230001'], ['Ramon Villanueva', '09171230002'], ['Alyssa Garcia', '09171230003'],
            ['Noel Castillo', '09171230004'], ['Grace Tan', '09171230005'], ['Ana Bautista', '09171230006'],
            ['Josefa Cruz', '09171230007'], ['Mark Aquino', '09171230008'],
        ];
        foreach ($names as [$n, $p]) {
            if (!q_val('SELECT id FROM customers WHERE phone = ?', [$p])) {
                q('INSERT INTO customers (full_name, phone, created_by, is_demo, created_at) VALUES (?,?,?,1,?)',
                    [$n, $p, $staff[0], date('Y-m-d H:i:s', strtotime('-8 days', $realNow))]);
            }
        }
        $customers = array_map('intval', array_column(q_all('SELECT id FROM customers WHERE is_demo = 1'), 'id'));
        $services = q_all('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order, id');
        if (!$services) {
            throw new RuntimeException('Add at least one active service first.');
        }
        $methods = ['cash', 'cash', 'cash', 'gcash', 'gcash', 'maya'];
        $count = 0;
        $kgServices = array_values(array_filter($services, fn($s) => $s['unit'] === 'kg'));

        mt_srand(20261121);
        for ($day = 6; $day >= 0; $day--) {
            $perDay = $day === 0 ? 7 : mt_rand(4, 7);
            $base = strtotime(date('Y-m-d', strtotime("-$day day", $realNow)) . ' 08:15:00');
            for ($i = 0; $i < $perDay; $i++) {
                $t = $base + $i * mt_rand(3000, 5400);
                if ($t > $realNow - 600) {
                    break;
                }
                $GLOBALS['LF_CLOCK'] = $t;
                $svc = ($kgServices && mt_rand(0, 9) < 8) ? $kgServices[mt_rand(0, count($kgServices) - 1)] : $services[mt_rand(0, count($services) - 1)];
                $qty = $svc['unit'] === 'kg' ? mt_rand(30, 120) / 10 : mt_rand(1, 3);
                $actor = $staff[array_rand($staff)];
                $payNow = mt_rand(1, 10) <= 6;
                $method = $methods[array_rand($methods)];
                $rush = mt_rand(1, 10) === 1;
                $cid = $customers[array_rand($customers)];
                $total = calc_price($svc, $qty, $rush, loyalty_discount_for($cid))['total'];
                $id = create_order([
                    'customer_id' => $cid,
                    'service_id' => $svc['id'],
                    'weight' => $qty,
                    'bag_count' => mt_rand(1, 3),
                    'is_rush' => $rush,
                    'notes' => mt_rand(1, 6) === 1 ? 'Use hypoallergenic detergent' : '',
                    'pay_amount' => $payNow ? $total : 0,
                    'pay_method' => $method,
                    'pay_ref' => $method === 'cash' ? '' : strtoupper(substr($method, 0, 2)) . '-' . mt_rand(10000000, 99999999),
                ], $actor);
                $count++;

                // Walk the order through the process, never into the future
                $step = $svc['unit'] === 'kg' ? ($rush ? 1200 : mt_rand(2400, 3600)) : 14400;
                $clock = $t;
                while (($o = get_order($id)) && ($next = next_status($o['status']))) {
                    $clock += $step + mt_rand(0, 900);
                    if ($clock > $realNow - 300) {
                        break;
                    }
                    $GLOBALS['LF_CLOCK'] = $clock;
                    change_status($id, $next, $staff[array_rand($staff)]);
                }
                $o = get_order($id);
                if ($o['status'] === 'ready' && mt_rand(1, 10) <= ($day > 1 ? 9 : 5)) {
                    $claimAt = $clock + mt_rand(1800, 6 * 3600);
                    if ($claimAt < $realNow - 120) {
                        $GLOBALS['LF_CLOCK'] = $claimAt;
                        $a = $staff[array_rand($staff)];
                        $bal = round((float)$o['total'] - (float)$o['amount_paid'], 2);
                        if ($bal > 0) {
                            record_payment($id, $bal, 'cash', '', $a);
                        }
                        claim_order($id, $o['pickup_code'], $a);
                    }
                }
            }
            // End-of-day cash closing (one day is ₱50 short). Skipped if the shop
            // already has real payments, so real cash closings are never mixed up.
            $closeAt = strtotime(date('Y-m-d', strtotime("-$day day", $realNow)) . ' 19:30:00');
            if (!$hasRealPayments && $day >= 1 && $closeAt < $realNow) {
                $GLOBALS['LF_CLOCK'] = $closeAt;
                $exp = expected_cash(cash_period_start(), now());
                $short = $day === 3 ? 50 : 0;
                close_cash_drawer($exp['total'] - $short, $short ? 'Gave wrong change, I think' : '', $staff[array_rand($staff)]);
            }
        }

        // Expenses so profit reports have data
        $GLOBALS['LF_CLOCK'] = $realNow - 3600;
        $owner = (int)(q_val("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: $staff[0]);
        foreach ([['Detergent & supplies', 1850, 'Ariel 2 gal + fabcon'], ['Electricity', 4200, 'Meralco'], ['Water', 950, ''],
                  ['LPG / gas', 1100, 'Dryer LPG'], ['Plastic & packaging', 380, 'Plastic bags'], ['Salaries', 6000, 'Weekly salary']] as $i => [$cat, $amt, $note]) {
            q('INSERT INTO expenses (expense_date, category, amount, note, created_by, is_demo, created_at) VALUES (?,?,?,?,?,1,?)',
                [date('Y-m-d', strtotime('-' . ($i + 1) . ' day', $realNow)), $cat, $amt, $note ?: null, $owner, now()]);
        }

        // One realistic weight discrepancy (demo orders only)
        $o = q_one("SELECT o.id FROM orders o JOIN services s ON s.id = o.service_id
                    WHERE o.id > ? AND s.unit = 'kg' AND o.status IN ('washing','drying','folding','queued','ready') ORDER BY o.id DESC LIMIT 1", [$before['orders']]);
        if ($o) {
            $full = get_order((int)$o['id']);
            $GLOBALS['LF_CLOCK'] = max(strtotime($full['updated_at']) + 60, $realNow - 1800);
            update_order_details((int)$full['id'], [
                'service_id' => $full['service_id'],
                'weight' => max(1, round((float)$full['weight'] * 0.75, 1)),
                'bag_count' => $full['bag_count'],
                'is_rush' => (bool)$full['is_rush'],
                'notes' => (string)$full['notes'],
            ], 'Incorrect tare setting on scale #2. Re-weighed with customer bag removed.', $staff[array_rand($staff)]);
        }
    } finally {
        // Mark everything created above as demo data
        foreach (DEMO_TABLES as $t) {
            q("UPDATE $t SET is_demo = 1 WHERE id > ?", [$before[$t]]);
        }
        unset($GLOBALS['LF_CLOCK'], $GLOBALS['LF_DEMO']);
    }
    set_setting('last_reminder_run', '');
    set_setting('ai_insight_date', '');
    return $count;
}

/** Remove ALL demo data. Real orders, payments, customers and logs are never touched. */
function delete_demo_data(int $actor): array
{
    $result = tx(function () {
        $orderIds = array_map('intval', array_column(q_all('SELECT id FROM orders WHERE is_demo = 1'), 'id'));
        $in = $orderIds ? implode(',', $orderIds) : '0';
        $r = ['orders' => count($orderIds)];
        q("DELETE FROM notifications WHERE is_demo = 1 OR order_id IN ($in)");
        q("DELETE FROM discrepancies WHERE is_demo = 1 OR order_id IN ($in)");
        q("DELETE FROM payments WHERE is_demo = 1 OR order_id IN ($in)");
        q("DELETE FROM order_status_history WHERE order_id IN ($in)");
        q('DELETE FROM audit_logs WHERE is_demo = 1');
        q("DELETE FROM orders WHERE id IN ($in)");
        $r['expenses'] = (int)q_val('SELECT COUNT(*) FROM expenses WHERE is_demo = 1');
        q('DELETE FROM expenses WHERE is_demo = 1');
        q('DELETE FROM cash_closings WHERE is_demo = 1');

        // Demo customers that have no real orders left
        $custIds = array_map('intval', array_column(q_all('SELECT c.id FROM customers c WHERE c.is_demo = 1
            AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.id)'), 'id'));
        if ($custIds) {
            $cin = implode(',', $custIds);
            q("DELETE FROM notifications WHERE customer_id IN ($cin)");
            q("DELETE FROM customers WHERE id IN ($cin)");
        }
        $r['customers'] = count($custIds);

        // Demo accounts (jessa, carlo, angela): delete if unused by real data, otherwise deactivate
        $r['users_deleted'] = 0;
        $r['users_deactivated'] = 0;
        foreach (q_all("SELECT id FROM users WHERE is_demo = 1 AND role <> 'owner'") as $u) {
            $id = (int)$u['id'];
            $used = (int)q_val('SELECT COUNT(*) FROM orders WHERE created_by = ? OR claimed_by = ?', [$id, $id])
                + (int)q_val('SELECT COUNT(*) FROM payments WHERE received_by = ? OR voided_by = ?', [$id, $id])
                + (int)q_val('SELECT COUNT(*) FROM customers WHERE user_id = ?', [$id])
                + (int)q_val('SELECT COUNT(*) FROM audit_logs WHERE actor_id = ?', [$id])
                + (int)q_val('SELECT COUNT(*) FROM cash_closings WHERE closed_by = ?', [$id])
                + (int)q_val('SELECT COUNT(*) FROM discrepancies WHERE reported_by = ? OR reviewed_by = ?', [$id, $id]);
            if ($used === 0) {
                q('DELETE FROM notifications WHERE user_id = ?', [$id]);
                q('DELETE FROM users WHERE id = ?', [$id]);
                $r['users_deleted']++;
            } else {
                q('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);
                $r['users_deactivated']++;
            }
        }
        return $r;
    });
    set_setting('ai_insight_date', '');
    set_setting('last_reminder_run', '');
    audit('demo_deleted', 'settings', null, null, null, null,
        $result['orders'] . ' orders, ' . $result['customers'] . ' customers, ' . $result['users_deleted'] . ' accounts removed', null, $actor);
    return $result;
}
