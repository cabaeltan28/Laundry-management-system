<?php
/**
 * Business logic: pricing, ready-time estimates, workload, status flow,
 * payments, audit trail, discrepancies and notifications.
 *
 * Pages never write to orders/payments directly - they call these
 * functions, so every change is validated, audited and transparent.
 */

const STATUS_FLOW = ['received', 'weighed', 'queued', 'washing', 'drying', 'folding', 'ready', 'claimed'];
const ACTIVE_STATUSES = ['received', 'weighed', 'queued', 'washing', 'drying', 'folding'];
const STATUS_LABELS = [
    'received'  => 'Received',
    'weighed'   => 'Weighed',
    'queued'    => 'Queued',
    'washing'   => 'Washing',
    'drying'    => 'Drying',
    'folding'   => 'Folding',
    'ready'     => 'Ready for pickup',
    'claimed'   => 'Claimed',
    'cancelled' => 'Cancelled',
];
const STATUS_HINTS = [
    'received' => 'Your laundry was received at the counter.',
    'weighed'  => 'Your laundry was weighed and priced.',
    'queued'   => 'Your laundry is in line for a machine.',
    'washing'  => 'Your laundry is being washed.',
    'drying'   => 'Your laundry is being dried.',
    'folding'  => 'Your laundry is being folded.',
    'ready'    => 'Your laundry is ready for pickup!',
    'claimed'  => 'Your laundry was claimed. Thank you!',
    'cancelled'=> 'This order was cancelled.',
];
const PAYMENT_METHODS = [
    'cash'  => 'Cash',
    'gcash' => 'GCash',
    'maya'  => 'Maya',
    'bank'  => 'Bank transfer',
    'other' => 'Other',
];
const DISCREPANCY_LABELS = [
    'weight_change'         => 'Weight change',
    'price_decrease'        => 'Price decrease',
    'service_change'        => 'Service changed after processing',
    'change_after_payment'  => 'Changed after payment',
    'payment_voided'        => 'Payment voided',
    'order_cancelled'       => 'Order cancelled',
    'released_unpaid'       => 'Released with unpaid balance',
    'status_reverted'       => 'Status moved backward',
    'cash_short'            => 'Cash drawer short',
    'cash_over'             => 'Cash drawer over',
];

// ---------------------------------------------------------------------
// Small display helpers
// ---------------------------------------------------------------------
function status_label(string $s): string
{
    return t(STATUS_LABELS[$s] ?? ucfirst($s));
}

function status_badge(string $s): string
{
    return '<span class="badge st-' . e($s) . '">' . e(status_label($s)) . '</span>';
}

function pay_badge(string $ps): string
{
    $labels = ['paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid'];
    return '<span class="badge pay-' . e($ps) . '">' . e(t($labels[$ps] ?? $ps)) . '</span>';
}

function next_status(string $s): ?string
{
    $i = array_search($s, STATUS_FLOW, true);
    if ($i === false || $s === 'ready' || $s === 'claimed') {
        return null; // ready -> claimed only through "Confirm pickup"
    }
    return STATUS_FLOW[$i + 1];
}

function prev_status(string $s): ?string
{
    $i = array_search($s, STATUS_FLOW, true);
    if ($i === false || $i === 0 || $s === 'claimed') {
        return null;
    }
    return STATUS_FLOW[$i - 1];
}

/** 4-digit pickup code that no other open order is using (so the counter never mixes them up). */
function new_pickup_code(): string
{
    for ($i = 0; $i < 30; $i++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (!q_val("SELECT COUNT(*) FROM orders WHERE pickup_code = ? AND status NOT IN ('claimed','cancelled')", [$code])) {
            return $code;
        }
    }
    return $code;
}

/** Run code inside a DB transaction (nested calls reuse the outer one). */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// ---------------------------------------------------------------------
// Services & pricing
// ---------------------------------------------------------------------
function get_service(int $id): ?array
{
    return q_one('SELECT * FROM services WHERE id = ?', [$id]);
}

function active_services(): array
{
    return q_all('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order, name');
}

/**
 * Price calculation.
 * total = billable qty x price, + rush surcharge %, - discount % (loyalty)
 * Minimum billable quantity applies.
 */
function calc_price(array $service, float $qty, bool $rush, float $discountPct = 0.0, ?float $rushPct = null): array
{
    $billable = $qty > 0 ? max($qty, (float)$service['min_qty']) : 0;
    $subtotal = round($billable * (float)$service['price'], 2);
    $rushPct  = $rush ? ($rushPct ?? (float)setting('rush_percent', 25)) : 0.0;
    $withRush = round($subtotal * (1 + $rushPct / 100), 2);
    $discount = round($withRush * $discountPct / 100, 2);
    return [
        'unit_price'       => (float)$service['price'],
        'billable_qty'     => $billable,
        'subtotal'         => $subtotal,
        'rush_percent'     => $rushPct,
        'rush_amount'      => round($withRush - $subtotal, 2),
        'discount_percent' => $discountPct,
        'discount_amount'  => $discount,
        'total'            => round($withRush - $discount, 2),
    ];
}

/**
 * Loyalty reward: every Nth order gets a discount (Settings).
 * Returns the discount % for the customer's NEXT order (0 if none).
 */
function loyalty_discount_for(int $customerId): float
{
    $every = (int)setting('loyalty_every', 0);
    $pct = (float)setting('loyalty_discount_percent', 0);
    if ($every < 2 || $pct <= 0) {
        return 0.0;
    }
    $done = customer_order_count($customerId);
    return (($done + 1) % $every === 0) ? $pct : 0.0;
}

/**
 * All customer records that belong to the same person: if the record is linked
 * to an app account, every record linked to that account counts together.
 */
function customer_group_ids(int $customerId): array
{
    $uid = q_val('SELECT user_id FROM customers WHERE id = ?', [$customerId]);
    if ($uid) {
        return customer_ids_for_user((int)$uid);
    }
    return [$customerId];
}

/** Non-cancelled orders of this customer (all linked records together). */
function customer_order_count(int $customerId): int
{
    $ids = customer_group_ids($customerId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return (int)q_val("SELECT COUNT(*) FROM orders WHERE customer_id IN ($ph) AND status <> 'cancelled'", $ids);
}

/** How many more orders until the next loyalty reward (null if loyalty is off). */
function loyalty_orders_left(int $customerId): ?int
{
    $every = (int)setting('loyalty_every', 0);
    if ($every < 2 || (float)setting('loyalty_discount_percent', 0) <= 0) {
        return null;
    }
    $done = customer_order_count($customerId);
    $left = $every - (($done + 1) % $every);
    return $left === $every ? 0 : $left;
}

function price_breakdown_text(array $service, float $qty, bool $rush, float $discountPct = 0.0): string
{
    $p = calc_price($service, $qty, $rush, $discountPct);
    $txt = qty_fmt($p['billable_qty'], $service['unit']) . ' × ' . money($p['unit_price']);
    if ($p['billable_qty'] > $qty) {
        $txt .= ' (minimum ' . qty_fmt($service['min_qty'], $service['unit']) . ')';
    }
    if ($rush) {
        $txt .= ' + ' . rtrim(rtrim(number_format($p['rush_percent'], 2), '0'), '.') . '% rush';
    }
    if ($discountPct > 0) {
        $txt .= ' − ' . rtrim(rtrim(number_format($discountPct, 2), '0'), '.') . '% loyalty';
    }
    return $txt;
}

/** Convert an order quantity into "kg of machine work". */
function load_kg(string $unit, float $qty): float
{
    return $unit === 'piece' ? $qty * (float)setting('piece_kg_equivalent', 3) : $qty;
}

// ---------------------------------------------------------------------
// Workload indicator & ready-time estimate
// ---------------------------------------------------------------------
function workload(?int $excludeOrderId = null): array
{
    $in = "'" . implode("','", ACTIVE_STATUSES) . "'";
    $rows = q_all("SELECT o.id, o.weight, o.status, o.is_rush, s.unit
                   FROM orders o JOIN services s ON s.id = o.service_id
                   WHERE o.status IN ($in)");
    $counts = array_fill_keys(ACTIVE_STATUSES, 0);
    $kg = 0.0;
    $active = 0;
    foreach ($rows as $r) {
        if ($excludeOrderId && (int)$r['id'] === $excludeOrderId) {
            continue;
        }
        $active++;
        $counts[$r['status']]++;
        // Orders still waiting count fully; orders already in machines count half.
        $factor = in_array($r['status'], ['received', 'weighed', 'queued'], true) ? 1.0 : 0.5;
        $kg += load_kg($r['unit'], (float)$r['weight']) * $factor;
    }
    $cap = max(1.0, (float)setting('capacity_kg_per_hour', 15));
    $backlog = $kg / $cap;

    if ($backlog < 2) {
        $level = 'Light';
    } elseif ($backlog < 5) {
        $level = 'Moderate';
    } else {
        $level = 'Busy';
    }
    $typical = (float)(q_val("SELECT MIN(base_hours) FROM services WHERE is_active = 1 AND unit = 'kg'") ?: 5);
    $low  = (int)floor($typical + $backlog);
    $high = (int)ceil($typical + $backlog) + 1;
    $ready = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'ready'");

    return [
        'active'        => $active,
        'counts'        => $counts,
        'load_kg'       => round($kg, 1),
        'backlog_hours' => round($backlog, 2),
        'level'         => $level,
        'est_text'      => $low . '–' . $high . ' hours',
        'ready'         => $ready,
        'percent'       => (int)min(100, round($backlog / 6 * 100)),
    ];
}

/** Add working hours to a timestamp, respecting shop opening hours. */
function add_shop_hours(int $start, float $hours): int
{
    $open  = (int)setting('open_hour', 8);
    $close = (int)setting('close_hour', 20);
    if ($close <= $open) {
        return $start + (int)round($hours * 3600); // 24h shop
    }
    $cur = $start;
    $align = function (int $ts) use ($open, $close): int {
        $d = date('Y-m-d', $ts);
        $openTs  = strtotime("$d $open:00:00");
        $closeTs = strtotime("$d $close:00:00");
        if ($ts < $openTs) return $openTs;
        if ($ts >= $closeTs) return strtotime('+1 day', $openTs);
        return $ts;
    };
    if ($hours >= 24) {
        // Long jobs (dry clean, comforters) are counted in calendar hours.
        return $align($start + (int)round($hours * 3600));
    }
    $remaining = $hours * 3600;
    $cur = $align($cur);
    while ($remaining > 0) {
        $closeTs = strtotime(date('Y-m-d', $cur) . " $close:00:00");
        $avail = $closeTs - $cur;
        if ($remaining <= $avail) {
            $cur += (int)round($remaining);
            $remaining = 0;
        } else {
            $remaining -= $avail;
            $cur = $align($closeTs);
        }
    }
    return $cur;
}

function estimate_ready(array $service, float $qty, bool $rush, ?int $excludeOrderId = null, ?int $from = null): string
{
    $base = (float)$service['base_hours'];
    $wl = workload($excludeOrderId);
    $backlog = $wl['backlog_hours'];
    // Big loads take longer than the base turnaround
    $own = max(0, load_kg($service['unit'], $qty) - 8) / max(1.0, (float)setting('capacity_kg_per_hour', 15));
    if ($rush) {
        $f = (float)setting('rush_speed_factor', 0.5);
        $base *= $f;
        $backlog *= $f;
    }
    $ts = add_shop_hours($from ?? clock(), $base + $backlog + $own);
    $ts = (int)(ceil($ts / 900) * 900); // round up to next 15 minutes
    return date('Y-m-d H:i:s', $ts);
}

// ---------------------------------------------------------------------
// Audit trail
// ---------------------------------------------------------------------
function audit(string $action, string $entityType, ?int $entityId, ?int $orderId = null,
               ?string $field = null, $old = null, $new = null, ?string $reason = null, ?int $actorId = null): void
{
    q('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, order_id, field, old_value, new_value, reason, ip_address, is_demo, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $actorId ?? user_id(),
        $action,
        $entityType,
        $entityId,
        $orderId,
        $field,
        $old === null ? null : mb_substr((string)$old, 0, 255),
        $new === null ? null : mb_substr((string)$new, 0, 255),
        $reason === null ? null : mb_substr($reason, 0, 255),
        client_ip(),
        empty($GLOBALS['LF_DEMO']) ? 0 : 1,
        now(),
    ]);
}

function audit_action_label(string $a): string
{
    $map = [
        'order_created' => 'Order created', 'field_updated' => 'Order updated', 'total_recalculated' => 'Total recalculated',
        'status_changed' => 'Status updated', 'status_reverted' => 'Status moved back', 'payment_recorded' => 'Payment recorded',
        'payment_voided' => 'Payment voided', 'order_claimed' => 'Pickup confirmed', 'order_cancelled' => 'Order cancelled',
        'discrepancy_approved' => 'Discrepancy approved', 'discrepancy_flagged' => 'Flagged for investigation',
        'login' => 'Logged in', 'logout' => 'Logged out', 'user_switched' => 'Switched user (PIN)',
        'service_created' => 'Service added', 'service_updated' => 'Service updated', 'employee_created' => 'Employee added',
        'employee_updated' => 'Employee updated', 'customer_created' => 'Customer added', 'customer_updated' => 'Customer updated',
        'settings_updated' => 'Settings updated', 'customer_linked' => 'Order linked to account', 'password_changed' => 'Password changed',
        'customer_notified' => 'Customer notified', 'account_registered' => 'Account registered',
        'cash_closed' => 'Cash drawer closed', 'expense_added' => 'Expense added', 'expense_deleted' => 'Expense deleted',
        'language_changed' => 'Language changed', 'demo_deleted' => 'Demo data deleted',
    ];
    return $map[$a] ?? ucfirst(str_replace('_', ' ', $a));
}

// ---------------------------------------------------------------------
// Notifications (in-app + optional SMS)
// ---------------------------------------------------------------------
function send_sms(?string $phone, string $message, ?int $customerId = null, ?int $orderId = null): void
{
    if (!$phone) {
        return;
    }
    $status = 'simulated';
    if (SMS_ENABLED && SMS_API_KEY !== '' && function_exists('curl_init')) {
        $ch = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => SMS_API_KEY,
                'number' => $phone,
                'message' => $message,
                'sendername' => SMS_SENDER_NAME,
            ]),
        ]);
        $ok = curl_exec($ch);
        $status = $ok === false ? 'failed' : 'sent';
        curl_close($ch);
    }
    q('INSERT INTO notifications (user_id, customer_id, order_id, type, title, message, channel, is_read, created_at)
       VALUES (NULL, ?, ?, ?, ?, ?, ?, 1, ?)',
        [$customerId, $orderId, 'sms', 'SMS ' . $status . ' to ' . mask_phone($phone), mb_substr($message, 0, 255), 'sms_log', now()]);
}

function notify_customer(array $order, string $type, string $title, string $message, bool $sms = false): void
{
    $cust = q_one('SELECT id, user_id, phone FROM customers WHERE id = ?', [$order['customer_id']]);
    if (!$cust) {
        return;
    }
    q('INSERT INTO notifications (user_id, customer_id, order_id, type, title, message, channel, is_read, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
        [$cust['user_id'], $cust['id'], $order['id'], $type, $title, mb_substr($message, 0, 255), 'in_app', now()]);
    if ($sms) {
        send_sms($cust['phone'], shop_name() . ': ' . $message, (int)$cust['id'], (int)$order['id']);
    }
}

function notify_owners(string $type, string $title, string $message, ?int $orderId = null): void
{
    foreach (q_all("SELECT id FROM users WHERE role = 'owner' AND is_active = 1") as $o) {
        q('INSERT INTO notifications (user_id, customer_id, order_id, type, title, message, channel, is_read, created_at)
           VALUES (?, NULL, ?, ?, ?, ?, ?, 0, ?)',
            [$o['id'], $orderId, $type, $title, mb_substr($message, 0, 255), 'in_app', now()]);
    }
}

function unread_count(array $user): int
{
    if ($user['role'] === 'customer') {
        return (int)q_val("SELECT COUNT(*) FROM notifications n JOIN customers c ON c.id = n.customer_id
                           WHERE c.user_id = ? AND n.channel = 'in_app' AND n.is_read = 0", [$user['id']]);
    }
    return (int)q_val("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND channel = 'in_app'", [$user['id']]);
}

// ---------------------------------------------------------------------
// Discrepancies
// ---------------------------------------------------------------------
function create_discrepancy(?int $orderId, string $type, $old, $new, ?string $diff, ?float $pct, string $reason, int $actor): void
{
    q('INSERT INTO discrepancies (order_id, type, old_value, new_value, difference, percent_change, reason, reported_by, status, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$orderId, $type, (string)$old, (string)$new, $diff, $pct, mb_substr($reason, 0, 255), $actor, 'open', now()]);
    $no = $orderId ? q_val('SELECT order_no FROM orders WHERE id = ?', [$orderId]) : t('Cash drawer');
    notify_owners('discrepancy', (DISCREPANCY_LABELS[$type] ?? 'Discrepancy') . ' needs review',
        $no . ': ' . $old . ' → ' . $new . ($reason ? ' · "' . $reason . '"' : ''), $orderId);
}

function open_discrepancy_count(): int
{
    return (int)q_val("SELECT COUNT(*) FROM discrepancies WHERE status = 'open'");
}

// ---------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------
function get_order(int $id): ?array
{
    return q_one('SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone, c.user_id AS customer_user_id,
                         s.name AS service_name, s.unit AS service_unit, s.min_qty AS service_min_qty,
                         u.full_name AS created_by_name
                  FROM orders o
                  JOIN customers c ON c.id = o.customer_id
                  JOIN services s ON s.id = o.service_id
                  LEFT JOIN users u ON u.id = o.created_by
                  WHERE o.id = ?', [$id]);
}

function add_history(int $orderId, string $status, ?int $actor, string $note = ''): void
{
    q('INSERT INTO order_status_history (order_id, status, changed_by, note, created_at) VALUES (?,?,?,?,?)',
        [$orderId, $status, $actor, $note !== '' ? $note : null, now()]);
}

function order_timeline(int $orderId): array
{
    return q_all('SELECT h.*, u.full_name AS actor_name FROM order_status_history h
                  LEFT JOIN users u ON u.id = h.changed_by
                  WHERE h.order_id = ? ORDER BY h.id', [$orderId]);
}

/**
 * Create a new order. $d keys: customer_id, service_id, weight, bag_count,
 * is_rush, notes, pay_amount, pay_method, pay_ref
 */
function create_order(array $d, int $actor): int
{
    return tx(function () use ($d, $actor) {
        $service = get_service((int)$d['service_id']);
        if (!$service || !(int)$service['is_active']) {
            throw new RuntimeException(t('Please select a valid service.'));
        }
        $weight = round((float)$d['weight'], 2);
        $rush = !empty($d['is_rush']);
        $disc = loyalty_discount_for((int)$d['customer_id']);
        $price = calc_price($service, $weight, $rush, $disc);
        $status = $weight > 0 ? 'weighed' : 'received';
        $eta = estimate_ready($service, $weight, $rush);
        $t = now();

        q('INSERT INTO orders (order_no, customer_id, service_id, weight, initial_weight, bag_count, is_rush, notes,
                               unit_price, rush_percent, discount_percent, discount_note, subtotal, total, amount_paid, payment_status, status,
                               estimated_ready_at, tracking_token, pickup_code, created_by, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?)', [
            'TMP-' . random_code(10),
            (int)$d['customer_id'],
            (int)$service['id'],
            $weight,
            $weight,
            max(1, (int)$d['bag_count']),
            $rush ? 1 : 0,
            $d['notes'] !== '' ? mb_substr($d['notes'], 0, 255) : null,
            $price['unit_price'],
            $price['rush_percent'],
            $disc,
            $disc > 0 ? 'Loyalty reward' : null,
            $price['subtotal'],
            $price['total'],
            'unpaid',
            $status,
            $eta,
            random_code(8),
            new_pickup_code(),
            $actor,
            $t,
            $t,
        ]);
        $id = (int)db()->lastInsertId();
        $orderNo = 'LF-' . (10000 + $id);
        q('UPDATE orders SET order_no = ? WHERE id = ?', [$orderNo, $id]);

        add_history($id, 'received', $actor);
        if ($status === 'weighed') {
            add_history($id, 'weighed', $actor, qty_fmt($weight, $service['unit']));
        }
        audit('order_created', 'order', $id, $id, null, null,
            $orderNo . ' · ' . $service['name'] . ' · ' . qty_fmt($weight, $service['unit']) . ' · ' . money($price['total']), null, $actor);

        $order = get_order($id);
        notify_customer($order, 'order_created', 'Order ' . $orderNo . ' received',
            'We received your laundry (' . $service['name'] . ', ' . qty_fmt($weight, $service['unit']) . ', ' . money($price['total']) .
            ')' . ($disc > 0 ? ' with your ' . rtrim(rtrim(number_format($disc, 2), '0'), '.') . '% loyalty discount' : '') .
            '. Estimated ready: ' . fmt_when($eta) . '. Pickup code: ' . $order['pickup_code'] . '.', true);

        if ((float)($d['pay_amount'] ?? 0) > 0) {
            record_payment($id, (float)$d['pay_amount'], (string)$d['pay_method'], (string)$d['pay_ref'], $actor);
        }
        return $id;
    });
}

/**
 * Edit weight / service / rush / bags / notes. A reason is required.
 * Suspicious changes create discrepancies for the owner.
 */
function update_order_details(int $orderId, array $new, string $reason, int $actor): array
{
    return tx(function () use ($orderId, $new, $reason, $actor) {
        $o = get_order($orderId);
        if (!$o) {
            throw new RuntimeException(t('Order not found.'));
        }
        if (in_array($o['status'], ['claimed', 'cancelled'], true)) {
            throw new RuntimeException(t('Claimed or cancelled orders can no longer be edited.'));
        }
        $actorRole = q_val('SELECT role FROM users WHERE id = ?', [$actor]);
        $service = get_service((int)$new['service_id']);
        if (!$service) {
            throw new RuntimeException(t('Please select a valid service.'));
        }
        $weight = round((float)$new['weight'], 2);
        if ($weight < 0 || $weight > 500) {
            throw new RuntimeException(t('Weight / quantity is not valid.'));
        }
        if ($weight <= 0 && (float)$o['weight'] > 0) {
            throw new RuntimeException(t('Weight cannot be changed to zero. Cancel the order instead.'));
        }
        $rush = !empty($new['is_rush']);
        $bags = max(1, (int)$new['bag_count']);
        $notes = mb_substr(trim((string)$new['notes']), 0, 255);

        $changes = [];
        if ((int)$service['id'] !== (int)$o['service_id']) {
            $changes['service'] = [$o['service_name'], $service['name']];
        }
        if (abs($weight - (float)$o['weight']) > 0.001) {
            $changes['weight'] = [(float)$o['weight'], $weight];
        }
        if ($rush !== (bool)$o['is_rush']) {
            $changes['rush'] = [$o['is_rush'] ? 'Yes' : 'No', $rush ? 'Yes' : 'No'];
        }
        if ($bags !== (int)$o['bag_count']) {
            $changes['bags'] = [(int)$o['bag_count'], $bags];
        }
        if ($notes !== (string)$o['notes']) {
            $changes['notes'] = [(string)$o['notes'], $notes];
        }
        if (!$changes) {
            return [];
        }
        if (trim($reason) === '') {
            throw new RuntimeException(t('Please give a reason for the change.'));
        }

        // Prices are kept from when the order was created unless the service changes
        // (so owner price changes do not silently alter existing orders).
        $priceService = $service;
        if (!isset($changes['service'])) {
            $priceService['price'] = $o['unit_price'];
        }
        $rushPctBefore = (float)$o['rush_percent'];
        $price = calc_price($priceService, $weight, $rush, (float)$o['discount_percent'],
            ($rush && !isset($changes['rush']) && $rushPctBefore > 0) ? $rushPctBefore : null);

        $firstWeigh = (float)$o['weight'] == 0.0 && $weight > 0;
        $eta = $o['estimated_ready_at'];
        if ((isset($changes['service']) || isset($changes['weight']) || isset($changes['rush'])) && !in_array($o['status'], ['ready'], true)) {
            $eta = estimate_ready($service, $weight, $rush, $orderId);
        }

        q('UPDATE orders SET service_id = ?, weight = ?, initial_weight = ?, bag_count = ?, is_rush = ?, notes = ?,
                             unit_price = ?, rush_percent = ?, subtotal = ?, total = ?, estimated_ready_at = ?, updated_at = ?
           WHERE id = ?', [
            $service['id'], $weight, $firstWeigh ? $weight : $o['initial_weight'], $bags, $rush ? 1 : 0, $notes !== '' ? $notes : null,
            $price['unit_price'], $price['rush_percent'], $price['subtotal'], $price['total'], $eta, now(), $orderId,
        ]);

        $unit = $o['service_unit'];
        foreach ($changes as $field => [$old, $newV]) {
            if ($field === 'weight') {
                $old = qty_fmt($old, $unit);
                $newV = qty_fmt($newV, $service['unit']);
            }
            audit('field_updated', 'order', $orderId, $orderId, $field, $old, $newV, $reason, $actor);
        }
        if (abs($price['total'] - (float)$o['total']) > 0.001) {
            audit('total_recalculated', 'order', $orderId, $orderId, 'total', money($o['total']), money($price['total']), 'Automatic', $actor);
        }

        // ---- Discrepancy rules ----
        $flagged = false;
        $threshold = (float)setting('discrepancy_threshold_percent', 10);
        if (isset($changes['weight']) && !$firstWeigh) {
            [$ow, $nw] = $changes['weight'];
            $pct = $ow > 0 ? round(($nw - $ow) / $ow * 100, 2) : null;
            if ($nw < $ow || ($pct !== null && abs($pct) >= $threshold)) {
                $diff = ($nw - $ow > 0 ? '+' : '') . rtrim(rtrim(number_format($nw - $ow, 2), '0'), '.') . ($unit === 'piece' ? ' pc' : ' kg');
                create_discrepancy($orderId, 'weight_change', qty_fmt($ow, $unit), qty_fmt($nw, $service['unit']), $diff, $pct, $reason, $actor);
                $flagged = true;
            }
        }
        if (!$flagged && $price['total'] < (float)$o['total'] - 0.001 && !$firstWeigh) {
            $pct = (float)$o['total'] > 0 ? round(($price['total'] - $o['total']) / $o['total'] * 100, 2) : null;
            create_discrepancy($orderId, 'price_decrease', money($o['total']), money($price['total']),
                '-' . money($o['total'] - $price['total']), $pct, $reason, $actor);
            $flagged = true;
        }
        if (!$flagged && isset($changes['service']) && !in_array($o['status'], ['received', 'weighed'], true)) {
            create_discrepancy($orderId, 'service_change', $changes['service'][0], $changes['service'][1], null, null, $reason, $actor);
            $flagged = true;
        }
        if (!$flagged && (float)$o['amount_paid'] > 0 && abs($price['total'] - (float)$o['total']) > 0.001 && $actorRole !== 'owner') {
            create_discrepancy($orderId, 'change_after_payment', money($o['total']), money($price['total']), null, null, $reason, $actor);
        }

        refresh_payment_totals($orderId);

        // Customer transparency: tell them what changed
        if (isset($changes['weight']) || abs($price['total'] - (float)$o['total']) > 0.001 || isset($changes['service'])) {
            $msg = 'Order details updated: ' . $service['name'] . ', ' . qty_fmt($weight, $service['unit']) . ', total ' . money($price['total']) . '.';
            if (isset($changes['weight']) && !$firstWeigh) {
                $msg = 'Weight updated from ' . qty_fmt($changes['weight'][0], $unit) . ' to ' . qty_fmt($weight, $service['unit']) .
                    '. New total: ' . money($price['total']) . '.';
            }
            notify_customer($o, 'order_updated', $o['order_no'] . ' updated', $msg);
        }

        // First weighing moves a "received" order to "weighed"
        if ($firstWeigh && $o['status'] === 'received') {
            change_status($orderId, 'weighed', $actor, qty_fmt($weight, $service['unit']));
        }
        return array_keys($changes);
    });
}

function change_status(int $orderId, string $newStatus, int $actor, string $note = ''): void
{
    tx(function () use ($orderId, $newStatus, $actor, $note) {
        $o = get_order($orderId);
        if (!$o) {
            throw new RuntimeException(t('Order not found.'));
        }
        if (next_status($o['status']) !== $newStatus) {
            throw new RuntimeException(t('This order cannot move to that step.') . ' (' . status_label($o['status']) . ' → ' . status_label($newStatus) . ')');
        }
        if ((float)$o['weight'] <= 0) {
            throw new RuntimeException(t('Record the weight/quantity first before moving this order forward.'));
        }
        $t = now();
        if ($newStatus === 'ready') {
            q('UPDATE orders SET status = ?, ready_at = ?, updated_at = ? WHERE id = ?', [$newStatus, $t, $t, $orderId]);
        } else {
            q('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?', [$newStatus, $t, $orderId]);
        }
        add_history($orderId, $newStatus, $actor, $note);
        audit('status_changed', 'order', $orderId, $orderId, 'status', status_label($o['status']), status_label($newStatus), $note ?: null, $actor);

        if ($newStatus === 'ready') {
            $bal = (float)$o['total'] - (float)$o['amount_paid'];
            $msg = 'Your laundry ' . $o['order_no'] . ' is READY for pickup at ' . shop_name() . '. Pickup code: ' . $o['pickup_code'] .
                ($bal > 0 ? '. Balance to pay: ' . money($bal) . '.' : '. Fully paid.');
            notify_customer($o, 'ready', 'Ready for pickup', $msg, true);
        } else {
            notify_customer($o, 'status', $o['order_no'] . ': ' . status_label($newStatus), STATUS_HINTS[$newStatus] ?? '');
        }
    });
}

function revert_status(int $orderId, string $reason, int $actor): void
{
    tx(function () use ($orderId, $reason, $actor) {
        $o = get_order($orderId);
        $prev = $o ? prev_status($o['status']) : null;
        if (!$o || !$prev) {
            throw new RuntimeException(t('This order cannot be moved back.'));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(t('Please give a reason for moving the order back.'));
        }
        q('UPDATE orders SET status = ?, ready_at = ?, updated_at = ? WHERE id = ?',
            [$prev, $prev === 'ready' ? $o['ready_at'] : null, now(), $orderId]);
        add_history($orderId, $prev, $actor, 'Moved back: ' . $reason);
        audit('status_reverted', 'order', $orderId, $orderId, 'status', status_label($o['status']), status_label($prev), $reason, $actor);
        if (q_val('SELECT role FROM users WHERE id = ?', [$actor]) !== 'owner') {
            create_discrepancy($orderId, 'status_reverted', status_label($o['status']), status_label($prev), null, null, $reason, $actor);
        }
    });
}

function cancel_order(int $orderId, string $reason, int $actor): void
{
    tx(function () use ($orderId, $reason, $actor) {
        $o = get_order($orderId);
        if (!$o || in_array($o['status'], ['claimed', 'cancelled'], true)) {
            throw new RuntimeException(t('This order cannot be cancelled.'));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(t('Please give a reason for cancelling.'));
        }
        q('UPDATE orders SET status = ?, cancel_reason = ?, updated_at = ? WHERE id = ?', ['cancelled', $reason, now(), $orderId]);
        add_history($orderId, 'cancelled', $actor, $reason);
        audit('order_cancelled', 'order', $orderId, $orderId, 'status', status_label($o['status']), 'Cancelled', $reason, $actor);
        if (q_val('SELECT role FROM users WHERE id = ?', [$actor]) !== 'owner' || (float)$o['amount_paid'] > 0) {
            create_discrepancy($orderId, 'order_cancelled', status_label($o['status']) . ' · ' . money($o['total']),
                'Cancelled' . ((float)$o['amount_paid'] > 0 ? ' · ' . money($o['amount_paid']) . ' paid' : ''), null, null, $reason, $actor);
        }
        notify_customer($o, 'cancelled', $o['order_no'] . ' cancelled', 'Your order was cancelled. Reason: ' . $reason);
    });
}

// ---------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------
function refresh_payment_totals(int $orderId): void
{
    $paid = (float)q_val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND is_void = 0', [$orderId]);
    $total = (float)q_val('SELECT total FROM orders WHERE id = ?', [$orderId]);
    if ($paid <= 0) {
        $ps = 'unpaid';
    } elseif ($paid + 0.001 >= $total) {
        $ps = 'paid';
    } else {
        $ps = 'partial';
    }
    q('UPDATE orders SET amount_paid = ?, payment_status = ? WHERE id = ?', [$paid, $ps, $orderId]);
}

function record_payment(int $orderId, float $amount, string $method, string $ref, int $actor): int
{
    return tx(function () use ($orderId, $amount, $method, $ref, $actor) {
        $o = get_order($orderId);
        if (!$o || $o['status'] === 'cancelled') {
            throw new RuntimeException(t('Payments cannot be recorded for this order.'));
        }
        $amount = round($amount, 2);
        $balance = round((float)$o['total'] - (float)$o['amount_paid'], 2);
        if ($amount <= 0) {
            throw new RuntimeException(t('Enter a payment amount greater than zero.'));
        }
        if ($amount > $balance + 0.001) {
            throw new RuntimeException(t('Amount is more than the balance') . ' (' . money($balance) . '). ' . t('For cash, record only the balance and give the change.'));
        }
        if (!isset(PAYMENT_METHODS[$method])) {
            throw new RuntimeException(t('Choose a payment method.'));
        }
        if (in_array($method, ['gcash', 'maya', 'bank'], true) && trim($ref) === '') {
            throw new RuntimeException(t('Enter the reference number for') . ' ' . PAYMENT_METHODS[$method] . '.');
        }
        q('INSERT INTO payments (order_id, amount, method, reference_no, received_by, is_void, created_at) VALUES (?,?,?,?,?,0,?)',
            [$orderId, $amount, $method, $ref !== '' ? mb_substr($ref, 0, 60) : null, $actor, now()]);
        $pid = (int)db()->lastInsertId();
        refresh_payment_totals($orderId);
        audit('payment_recorded', 'payment', $pid, $orderId, 'payment', null,
            money($amount) . ' ' . PAYMENT_METHODS[$method] . ($ref !== '' ? ' · Ref ' . $ref : ''), null, $actor);
        $after = get_order($orderId);
        $left = (float)$after['total'] - (float)$after['amount_paid'];
        notify_customer($o, 'payment', 'Payment received', 'We received ' . money($amount) . ' via ' . PAYMENT_METHODS[$method] .
            ' for ' . $o['order_no'] . '.' . ($left > 0 ? ' Remaining balance: ' . money($left) . '.' : ' Fully paid.'));
        return $pid;
    });
}

function void_payment(int $paymentId, string $reason, int $actor): void
{
    tx(function () use ($paymentId, $reason, $actor) {
        $p = q_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || (int)$p['is_void']) {
            throw new RuntimeException(t('Payment not found or already voided.'));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(t('Please give a reason for voiding the payment.'));
        }
        q('UPDATE payments SET is_void = 1, void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ?',
            [mb_substr($reason, 0, 255), $actor, now(), $paymentId]);
        refresh_payment_totals((int)$p['order_id']);
        $label = money($p['amount']) . ' ' . (PAYMENT_METHODS[$p['method']] ?? $p['method']);
        audit('payment_voided', 'payment', $paymentId, (int)$p['order_id'], 'payment', $label, 'Voided', $reason, $actor);
        create_discrepancy((int)$p['order_id'], 'payment_voided', $label, 'Voided', '-' . money($p['amount']), null, $reason, $actor);
    });
}

// ---------------------------------------------------------------------
// Pickup / claim
// ---------------------------------------------------------------------
function claim_order(int $orderId, string $code, int $actor, bool $releaseWithBalance = false, string $reason = ''): void
{
    tx(function () use ($orderId, $code, $actor, $releaseWithBalance, $reason) {
        $o = get_order($orderId);
        if (!$o || $o['status'] !== 'ready') {
            throw new RuntimeException(t('Only orders that are Ready for pickup can be claimed.'));
        }
        if (!hash_equals((string)$o['pickup_code'], trim($code))) {
            throw new RuntimeException(t('Pickup code does not match. Ask the customer for the 4-digit code on their claim stub or SMS.'));
        }
        $balance = round((float)$o['total'] - (float)$o['amount_paid'], 2);
        if ($balance > 0 && !$releaseWithBalance) {
            throw new RuntimeException(t('This order still has a balance of') . ' ' . money($balance) . '. ' . t('Record the payment first.'));
        }
        if ($balance > 0 && trim($reason) === '') {
            throw new RuntimeException(t('Give a reason for releasing the laundry with an unpaid balance.'));
        }
        $t = now();
        q('UPDATE orders SET status = ?, claimed_at = ?, claimed_by = ?, updated_at = ? WHERE id = ?', ['claimed', $t, $actor, $t, $orderId]);
        add_history($orderId, 'claimed', $actor, 'Pickup code verified');
        audit('order_claimed', 'order', $orderId, $orderId, 'status', 'Ready for pickup', 'Claimed', $balance > 0 ? $reason : 'Pickup code verified', $actor);
        if ($balance > 0) {
            create_discrepancy($orderId, 'released_unpaid', money($o['total']), 'Released with ' . money($balance) . ' unpaid', '-' . money($balance), null, $reason, $actor);
        }
        notify_customer($o, 'claimed', $o['order_no'] . ' claimed', 'Your laundry was released on ' . fmt_dt($t) . '. Thank you for choosing ' . shop_name() . '!');
    });
}

/** Sends ready-for-pickup reminders. Runs at most every 10 minutes (on page load). */
function run_pickup_reminders(): int
{
    $last = (string)setting('last_reminder_run', '');
    if ($last !== '' && strtotime($last) > time() - 600) {
        return 0;
    }
    set_setting('last_reminder_run', now());
    $after = (float)setting('reminder_after_hours', 24);
    $max = (int)setting('max_reminders', 3);
    $cut = date('Y-m-d H:i:s', time() - (int)($after * 3600));
    $rows = q_all("SELECT * FROM orders WHERE status = 'ready' AND ready_at <= ? AND reminder_count < ?
                   AND (last_reminder_at IS NULL OR last_reminder_at <= ?)", [$cut, $max, $cut]);
    foreach ($rows as $o) {
        q('UPDATE orders SET reminder_count = reminder_count + 1, last_reminder_at = ? WHERE id = ?', [now(), $o['id']]);
        $bal = (float)$o['total'] - (float)$o['amount_paid'];
        notify_customer($o, 'reminder', 'Pickup reminder',
            'Reminder: your laundry ' . $o['order_no'] . ' has been ready since ' . fmt_when($o['ready_at']) . '. Pickup code: ' . $o['pickup_code'] .
            ($bal > 0 ? '. Balance: ' . money($bal) : '') . '.', true);
    }
    return count($rows);
}

/** IDs of customer records that belong to a customer login. */
function customer_ids_for_user(int $uid): array
{
    return array_map('intval', array_column(q_all('SELECT id FROM customers WHERE user_id = ?', [$uid]), 'id'));
}

// ---------------------------------------------------------------------
// Fast pickup: find by pickup code, collect balance and release in one step
// ---------------------------------------------------------------------
function find_ready_by_code(string $code): array
{
    $code = preg_replace('/\D+/', '', $code);
    if ($code === '') {
        return [];
    }
    return q_all("SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone, s.name AS service_name, s.unit AS service_unit
                  FROM orders o JOIN customers c ON c.id = o.customer_id JOIN services s ON s.id = o.service_id
                  WHERE o.status = 'ready' AND o.pickup_code = ? ORDER BY o.ready_at", [$code]);
}

function pay_balance_and_claim(int $orderId, string $code, string $method, string $ref, int $actor): float
{
    return tx(function () use ($orderId, $code, $method, $ref, $actor) {
        $o = get_order($orderId);
        if (!$o || $o['status'] !== 'ready') {
            throw new RuntimeException(t('Only orders that are Ready for pickup can be claimed.'));
        }
        if (!hash_equals((string)$o['pickup_code'], trim($code))) {
            throw new RuntimeException(t('Pickup code does not match.'));
        }
        $bal = round((float)$o['total'] - (float)$o['amount_paid'], 2);
        if ($bal > 0) {
            record_payment($orderId, $bal, $method, $ref, $actor);
        }
        claim_order($orderId, $code, $actor);
        return $bal;
    });
}

// ---------------------------------------------------------------------
// Cash drawer closing (end of shift)
// ---------------------------------------------------------------------
function cash_period_start(): string
{
    $last = q_val('SELECT MAX(period_end) FROM cash_closings');
    if ($last) {
        return (string)$last;
    }
    $first = q_val("SELECT MIN(created_at) FROM payments WHERE method = 'cash'");
    return $first ? date('Y-m-d H:i:s', strtotime((string)$first) - 1) : date('Y-m-d 00:00:00', clock());
}

function expected_cash(string $start, string $end): array
{
    $row = q_one("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n FROM payments
                  WHERE method = 'cash' AND is_void = 0 AND created_at > ? AND created_at <= ?", [$start, $end]);
    return ['total' => round((float)$row['total'], 2), 'count' => (int)$row['n']];
}

function close_cash_drawer(float $counted, string $note, int $actor): array
{
    return tx(function () use ($counted, $note, $actor) {
        $start = cash_period_start();
        $end = now();
        $exp = expected_cash($start, $end);
        $diff = round($counted - $exp['total'], 2);
        q('INSERT INTO cash_closings (period_start, period_end, expected_cash, counted_cash, difference, payment_count, note, closed_by, created_at)
           VALUES (?,?,?,?,?,?,?,?,?)', [$start, $end, $exp['total'], round($counted, 2), $diff, $exp['count'], $note !== '' ? mb_substr($note, 0, 255) : null, $actor, $end]);
        $id = (int)db()->lastInsertId();
        audit('cash_closed', 'cash_closing', $id, null, 'cash', money($exp['total']) . ' expected', money($counted) . ' counted', $note ?: null, $actor);
        if (abs($diff) >= 0.01) {
            create_discrepancy(null, $diff < 0 ? 'cash_short' : 'cash_over', money($exp['total']) . ' expected', money($counted) . ' counted',
                ($diff > 0 ? '+' : '−') . money(abs($diff)), null, $note !== '' ? $note : 'No explanation given', $actor);
        }
        return ['expected' => $exp['total'], 'counted' => $counted, 'diff' => $diff, 'count' => $exp['count']];
    });
}

// ---------------------------------------------------------------------
// Linking counter (walk-in) orders to a customer's app account
// ---------------------------------------------------------------------
function track_url(array $order): string
{
    $q = 'track.php?o=' . rawurlencode($order['order_no']) . '&t=' . rawurlencode($order['tracking_token']);
    if (defined('PUBLIC_URL') && PUBLIC_URL !== '') {
        return rtrim(PUBLIC_URL, '/') . '/' . $q;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url('track.php') . '?o=' . rawurlencode($order['order_no']) . '&t=' . rawurlencode($order['tracking_token']);
}

/** Attach walk-in customer records to an app account. Returns number of orders now visible. */
function link_customer_records(array $customerIds, int $userId, string $how): int
{
    return tx(function () use ($customerIds, $userId, $how) {
        $n = 0;
        foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
            $c = q_one('SELECT id, user_id FROM customers WHERE id = ?', [$cid]);
            if (!$c || $c['user_id']) {
                continue;
            }
            q('UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $cid]);
            q('UPDATE notifications SET user_id = ? WHERE customer_id = ?', [$userId, $cid]);
            $n += (int)q_val('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$cid]);
            audit('customer_linked', 'customer', $cid, null, 'user_id', null,
                (string)q_val('SELECT username FROM users WHERE id = ?', [$userId]) . ' · ' . $how, null, user_id() ?? $userId);
        }
        return $n;
    });
}

/** Link using the order number + tracking code (QR code / claim stub). */
function link_by_tracking(string $orderNo, string $token, int $userId): array
{
    $o = q_one('SELECT o.id, o.tracking_token, o.customer_id, c.user_id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.order_no = ?',
        [strtoupper(trim($orderNo))]);
    if (!$o || !hash_equals((string)$o['tracking_token'], strtoupper(str_replace(' ', '', $token)))) {
        return ['ok' => false, 'msg' => t('Order number and tracking code do not match.')];
    }
    if ((int)$o['user_id'] === $userId) {
        return ['ok' => true, 'msg' => t('This order is already in your account.')];
    }
    if ($o['user_id']) {
        return ['ok' => false, 'msg' => t('This order belongs to another account. Please ask the counter for help.')];
    }
    $n = link_customer_records([(int)$o['customer_id']], $userId, 'claim stub');
    return ['ok' => true, 'msg' => t('Saved to your account!') . ' (' . $n . ' ' . t('order(s)') . ')'];
}

/** Walk-in records with the same mobile number as the account (not yet linked). */
function unlinked_records_for_phone(?string $phone): array
{
    if (!$phone) {
        return [];
    }
    return q_all('SELECT c.id, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS n
                  FROM customers c WHERE c.phone = ? AND c.user_id IS NULL', [$phone]);
}

/** Link all same-number records after the customer proves it with any pickup code. */
function link_by_phone_and_code(array $user, string $code): array
{
    $key = 'link:' . $user['id'];
    if (login_locked($key)) {
        return ['ok' => false, 'msg' => t('Too many attempts. Try again in 15 minutes.')];
    }
    $rows = unlinked_records_for_phone($user['phone'] ?? null);
    $ids = array_map('intval', array_column($rows, 'id'));
    $code = preg_replace('/\D+/', '', $code);
    if (!$ids || $code === '') {
        return ['ok' => false, 'msg' => t('No orders found with your mobile number.')];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $match = (int)q_val("SELECT COUNT(*) FROM orders WHERE customer_id IN ($ph) AND pickup_code = ?", array_merge($ids, [$code]));
    if (!$match) {
        record_failed_login($key);
        return ['ok' => false, 'msg' => t('That pickup code does not match your orders. Check your claim stub or SMS.')];
    }
    clear_failed_logins($key);
    $n = link_customer_records($ids, (int)$user['id'], 'mobile number + pickup code');
    return ['ok' => true, 'msg' => t('Done! Your orders are now in your account.') . ' (' . $n . ')'];
}

function demo_badge(array $row): string
{
    return !empty($row['is_demo']) ? ' <span class="badge b-demo">DEMO</span>' : '';
}
