<?php
/**
 * AI features (Claude API) with a built-in OFFLINE mode.
 *
 *  1. Order reader   – type an order in one line ("Maria 0917..., 8 kilo wash dry fold rush")
 *                      and the form fills itself. Works in English, Tagalog and Taglish.
 *  2. Business assistant – the owner asks questions about sales, staff, unpaid orders...
 *  3. Daily insights – short tips on the owner dashboard.
 *  4. Customer help  – customers ask "kailan ready?" and get an answer about THEIR orders.
 *
 * If AI is not configured (config.php) or the internet is down, every feature
 * still works using the rule-based logic below.
 */

function ai_enabled(): bool
{
    return AI_ENABLED && AI_API_KEY !== '' && function_exists('curl_init');
}

function ai_last_error(): string
{
    return (string)($GLOBALS['AI_ERROR'] ?? '');
}

/** Call the Claude Messages API. Returns the reply text, or null on any error. */
function ai_call(string $system, array $messages, int $maxTokens = 700): ?string
{
    if (!ai_enabled()) {
        return null;
    }
    // After an error, wait 5 minutes before trying again (keeps pages fast when offline)
    $until = (int)setting('ai_fail_until', 0);
    if ($until > time()) {
        $GLOBALS['AI_ERROR'] = (string)setting('ai_fail_reason', 'AI temporarily unavailable') . ' (retrying in ' . ceil(($until - time()) / 60) . ' min)';
        return null;
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . AI_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => AI_MODEL,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => $messages,
        ]),
    ];
    if (AI_CA_BUNDLE !== '') {
        $opts[CURLOPT_CAINFO] = AI_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        $GLOBALS['AI_ERROR'] = $err !== '' ? $err : ('HTTP ' . $code . ' ' . mb_substr((string)$res, 0, 200));
        set_setting('ai_fail_until', (string)(time() + 300));
        set_setting('ai_fail_reason', mb_substr($GLOBALS['AI_ERROR'], 0, 200));
        return null;
    }
    $json = json_decode((string)$res, true);
    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    return $text !== '' ? trim($text) : null;
}

/** Pull the first {...} JSON object out of a reply. */
function ai_extract_json(?string $text): ?array
{
    if (!$text) return null;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

// =====================================================================
// 1. ORDER READER
// =====================================================================

/** Keywords for matching services. Add your own words here. */
function service_keywords(array $service): array
{
    $n = mb_strtolower($service['name']);
    $words = [preg_replace('/[^a-z0-9 ]+/', '', str_replace('&', 'and', $n)), $n];
    if (strpos($n, 'dry clean') !== false) array_push($words, 'dry clean', 'dryclean', 'barong', 'gown', 'suit', 'amerikana', 'gowns');
    if (strpos($n, 'comforter') !== false) array_push($words, 'comforter', 'kumot', 'blanket', 'duvet', 'kobre kama');
    if (strpos($n, 'wash only') !== false) array_push($words, 'wash only', 'wash lang', 'labada lang', 'self fold', 'hugas lang', 'wash-only');
    if (strpos($n, 'wash & fold') !== false || strpos($n, 'wash and fold') !== false) array_push($words, 'wash and fold', 'wash & fold', 'wash fold', 'w&f', 'wash n fold');
    if (strpos($n, 'dry & fold') !== false || strpos($n, 'dry and fold') !== false) array_push($words, 'wash dry fold', 'wash dry and fold', 'wash, dry', 'wdf', 'full service', 'full serve', 'dry fold', 'wash dry & fold', 'wash-dry-fold');
    return array_values(array_unique(array_filter($words)));
}

/** Offline order reader (no AI needed). */
function parse_order_rules(string $text, array $services): array
{
    $low = ' ' . mb_strtolower(str_replace(["\r", "\n"], ', ', $text)) . ' ';
    $f = ['customer_name' => '', 'phone' => '', 'service_id' => 0, 'quantity' => '', 'bag_count' => '', 'is_rush' => false,
          'payment' => '', 'reference' => '', 'notes' => ''];

    // Phone number
    if (preg_match('/(?:\+?63|0)\s?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/', $text, $m)) {
        $p = normalize_phone($m[0]);
        if ($p) $f['phone'] = $p;
    }
    // Quantity: kilos or pieces
    $isPiece = false;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:kg|kgs|kilo|kilos|kilogram|kilograms|k)\b/u', $low, $m)) {
        $f['quantity'] = (float)str_replace(',', '.', $m[1]);
    } elseif (preg_match('/(\d+)\s*(?:pcs|pc|piece|pieces|piraso|pirasong|pirasa)\b/u', $low, $m)) {
        $f['quantity'] = (float)$m[1];
        $isPiece = true;
    }
    // Bags
    if (preg_match('/(\d+)\s*(?:bag|bags|supot|sako|basket|baskets|plastic)\b/u', $low, $m)) {
        $f['bag_count'] = (int)$m[1];
    }
    // Rush
    if (preg_match('/\b(rush|madalian|urgent|express|apura|asap|mabilisan|bilisan|same day)\b/u', $low)) {
        $f['is_rush'] = true;
    }
    // Service: best keyword match
    $best = 0;
    $bestLen = 0;
    foreach ($services as $s) {
        foreach (service_keywords($s) as $kw) {
            if ($kw !== '' && strpos($low, $kw) !== false && mb_strlen($kw) > $bestLen) {
                $best = (int)$s['id'];
                $bestLen = mb_strlen($kw);
            }
        }
    }
    if (!$best) {
        foreach ($services as $s) {
            if (($isPiece && $s['unit'] === 'piece') || (!$isPiece && $s['unit'] === 'kg')) {
                $best = (int)$s['id'];
                break;
            }
        }
    }
    $f['service_id'] = $best;
    // Payment
    if (preg_match('/(hindi pa bayad|di pa bayad|unpaid|not paid|utang|bayad mamaya|pay later|later na)/u', $low)) {
        $f['payment'] = 'none';
    } elseif (preg_match('/\bgcash\b/u', $low)) {
        $f['payment'] = 'gcash';
    } elseif (preg_match('/\b(maya|paymaya)\b/u', $low)) {
        $f['payment'] = 'maya';
    } elseif (preg_match('/\b(bank|bdo|bpi|landbank|transfer)\b/u', $low)) {
        $f['payment'] = 'bank';
    } elseif (preg_match('/\b(paid|bayad na|bayad|cash|cash na)\b/u', $low)) {
        $f['payment'] = 'cash';
    }
    if (preg_match('/\b(?:ref|reference|ref no|ref\.)\s*[#:.]?\s*([a-z0-9\-]{4,})/iu', $text, $m)) {
        $f['reference'] = strtoupper($m[1]);
    }
    // Notes: chunks that contain care instructions
    $noteWords = '/(unscented|walang amoy|no scent|hypoallergenic|sensitive|ihiwalay|separate|puti|white|delicate|maselan|fabcon|downy|tide|ariel|hanger|plantsa|iron|bleach|walang bleach|cold water|malamig|gentle|ingat|stain|mantsa|note|paalala)/u';
    $chunks = preg_split('/[,;\n]+/', $text);
    $notes = [];
    $nameChunk = '';
    foreach ($chunks as $c) {
        $c = trim($c);
        if ($c === '') continue;
        $cl = mb_strtolower($c);
        if (preg_match($noteWords, $cl, $nm, PREG_OFFSET_CAPTURE)) {
            // keep only the part starting from the care instruction
            $part = trim(substr($c, $nm[0][1]));
            $notes[] = preg_replace('/^(note|notes|paalala)\s*[:\-]\s*/iu', '', $part);
            if ($nm[0][1] > 0) {
                $c = trim(substr($c, 0, $nm[0][1]));
                $cl = mb_strtolower($c);
            } else {
                continue;
            }
        }
        if ($nameChunk === '' && $f['customer_name'] === '') {
            // Remove phone numbers, quantities and keywords; what is left may be a name
            $clean = preg_replace('/(?:\+?63|0)\s?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}/', ' ', $c);
            $clean = preg_replace('/\d+(?:[.,]\d+)?\s*[a-z]*/iu', ' ', $clean);
            $clean = preg_replace('/\b(si|kay|for|name|pangalan|customer|ni|po|ma\'?am|sir|order)\b/iu', ' ', $clean);
            $isKeyword = preg_match('/(rush|wash|dry|fold|clean|comforter|kumot|gcash|maya|paid|bayad|cash|bag|supot|kilo|express|utang|lang|only|full|bank)/iu', $clean);
            $clean = trim(preg_replace('/\s+/', ' ', $clean));
            if (!$isKeyword && preg_match('/\p{L}{2,}/u', $clean)) {
                $nameChunk = $clean;
            }
        }
    }
    $stop = ['wash', 'dry', 'fold', 'only', 'clean', 'dryclean', 'comforter', 'kumot', 'blanket', 'rush', 'express', 'urgent', 'paid', 'bayad', 'gcash',
             'maya', 'paymaya', 'cash', 'full', 'service', 'kilo', 'kilos', 'kg', 'pcs', 'pc', 'piraso', 'bag', 'bags', 'supot', 'sako', 'utang', 'lang',
             'and', 'barong', 'gown', 'suit', 'ref', 'reference', 'order', 'po', 'na', 'ng', 'muna', 'mamaya', 'bank', 'madalian', 'apura', 'with', 'at'];
    $isNameWord = fn($w) => preg_match('/^\p{L}[\p{L}\.\'\-]*$/u', $w) && !in_array(mb_strtolower(rtrim($w, '.')), $stop, true);
    if (preg_match('/\b(?:si|kay|for|name|pangalan|customer)\s*[:\-]?\s*([\p{L}\.\']+(?:\s+[\p{L}\.\']+){0,2})/iu', $text, $m)) {
        $words = [];
        foreach (preg_split('/\s+/', $m[1]) as $w) {
            if (!$isNameWord($w)) break;
            $words[] = $w;
        }
        $f['customer_name'] = implode(' ', $words);
    }
    if ($f['customer_name'] === '') {
        // Usually the name is written first: take the first words until a number or keyword
        $words = [];
        foreach (preg_split('/[\s,;]+/u', trim($text)) as $w) {
            if (!$isNameWord($w) || count($words) >= 4) break;
            $words[] = $w;
        }
        $f['customer_name'] = $words ? implode(' ', $words) : implode(' ', array_slice(explode(' ', $nameChunk), 0, 3));
    }
    $f['customer_name'] = mb_convert_case(trim($f['customer_name']), MB_CASE_TITLE, 'UTF-8');
    $f['notes'] = mb_substr(implode(', ', $notes), 0, 200);
    return $f;
}

/** Read an order from free text. Uses AI if available, otherwise the offline reader. */
function ai_parse_order(string $text): array
{
    $services = active_services();
    $rules = parse_order_rules($text, $services);
    if (!ai_enabled()) {
        return ['fields' => $rules, 'source' => 'offline'];
    }
    $list = '';
    foreach ($services as $s) {
        $list .= '- id ' . $s['id'] . ': ' . $s['name'] . ' (priced per ' . $s['unit'] . ")\n";
    }
    $system = "You read laundry shop orders typed by Filipino counter staff (English, Tagalog or Taglish). " .
        "Return ONLY a JSON object with keys: customer_name (string), phone (Philippine mobile, string or empty), " .
        "service_id (integer from the list), quantity (number: kilos for kg services, pieces for piece services), " .
        "bag_count (integer or null), is_rush (boolean), payment (one of: none, cash, gcash, maya, bank, or empty if not mentioned), " .
        "reference (string), notes (short care instructions or empty). Services:\n" . $list .
        "If the service is not mentioned, choose the most common full-service kg service. Do not invent a phone number or name.";
    $reply = ai_call($system, [['role' => 'user', 'content' => mb_substr($text, 0, 600)]], 300);
    $j = ai_extract_json($reply);
    if (!$j) {
        return ['fields' => $rules, 'source' => 'offline'];
    }
    $ids = array_map('intval', array_column($services, 'id'));
    $out = $rules;
    if (!empty($j['customer_name']) && is_string($j['customer_name'])) $out['customer_name'] = mb_substr(trim($j['customer_name']), 0, 80);
    if (!empty($j['phone']) && ($p = normalize_phone((string)$j['phone']))) $out['phone'] = $p;
    if (isset($j['service_id']) && in_array((int)$j['service_id'], $ids, true)) $out['service_id'] = (int)$j['service_id'];
    if (isset($j['quantity']) && is_numeric($j['quantity']) && (float)$j['quantity'] > 0 && (float)$j['quantity'] < 500) $out['quantity'] = (float)$j['quantity'];
    if (isset($j['bag_count']) && is_numeric($j['bag_count']) && (int)$j['bag_count'] > 0) $out['bag_count'] = min(50, (int)$j['bag_count']);
    if (isset($j['is_rush'])) $out['is_rush'] = (bool)$j['is_rush'];
    if (isset($j['payment']) && in_array($j['payment'], ['none', 'cash', 'gcash', 'maya', 'bank'], true)) $out['payment'] = $j['payment'];
    if (!empty($j['reference']) && is_string($j['reference'])) $out['reference'] = mb_substr($j['reference'], 0, 60);
    if (!empty($j['notes']) && is_string($j['notes'])) $out['notes'] = mb_substr($j['notes'], 0, 200);
    return ['fields' => $out, 'source' => 'ai'];
}

// =====================================================================
// 2. BUSINESS SNAPSHOT (used by assistant + insights)
// =====================================================================
function business_snapshot(): array
{
    $now = clock();
    $d = fn($s) => date('Y-m-d H:i:s', strtotime($s, $now));
    $today = date('Y-m-d 00:00:00', $now);
    $sum = fn($from, $to) => (float)q_val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND created_at >= ? AND created_at < ?', [$from, $to]);
    $cnt = fn($from, $to) => (int)q_val("SELECT COUNT(*) FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ?", [$from, $to]);
    $end = date('Y-m-d H:i:s', $now + 1);
    $monthStart = date('Y-m-01 00:00:00', $now);
    $s = [
        'shop' => shop_name(),
        'now' => date('l, F j, Y g:i A', $now),
        'revenue_today' => $sum($today, $end),
        'revenue_yesterday' => $sum(date('Y-m-d 00:00:00', strtotime('-1 day', $now)), $today),
        'revenue_7d' => $sum($d('-7 days'), $end),
        'revenue_prev_7d' => $sum($d('-14 days'), $d('-7 days')),
        'revenue_month' => $sum($monthStart, $end),
        'expenses_month' => (float)q_val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?', [date('Y-m-01', $now), date('Y-m-d', $now)]),
        'orders_today' => $cnt($today, $end),
        'orders_7d' => $cnt($d('-7 days'), $end),
        'orders_prev_7d' => $cnt($d('-14 days'), $d('-7 days')),
        'unpaid_total' => (float)q_val("SELECT COALESCE(SUM(total - amount_paid),0) FROM orders WHERE status <> 'cancelled' AND payment_status <> 'paid'"),
        'unpaid_count' => (int)q_val("SELECT COUNT(*) FROM orders WHERE status <> 'cancelled' AND payment_status <> 'paid' AND total > 0"),
        'ready' => (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'ready'"),
        'unclaimed_24h' => (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'ready' AND ready_at < ?", [$d('-24 hours')]),
        'unclaimed_7d' => (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'ready' AND ready_at < ?", [$d('-7 days')]),
        'open_discrepancies' => open_discrepancy_count(),
        'cash_short_30d' => (float)q_val('SELECT COALESCE(SUM(difference),0) FROM cash_closings WHERE difference < 0 AND created_at >= ?', [$d('-30 days')]),
    ];
    $s['profit_month'] = $s['revenue_month'] - $s['expenses_month'];
    $wl = workload();
    $s['workload'] = $wl['level'];
    $s['active'] = $wl['active'];

    // 30-day details aggregated in PHP
    $rows = q_all("SELECT o.*, s.name AS service_name, s.unit FROM orders o JOIN services s ON s.id = o.service_id
                   WHERE o.status <> 'cancelled' AND o.created_at >= ?", [$d('-30 days')]);
    $svc = []; $hours = []; $days = []; $onTime = 0; $readyN = 0; $turn = 0.0; $cust = []; $sales = 0.0; $rush = 0;
    foreach ($rows as $r) {
        $svc[$r['service_name']]['orders'] = ($svc[$r['service_name']]['orders'] ?? 0) + 1;
        $svc[$r['service_name']]['sales'] = ($svc[$r['service_name']]['sales'] ?? 0) + (float)$r['total'];
        $h = (int)date('G', strtotime($r['created_at']));
        $hours[$h] = ($hours[$h] ?? 0) + 1;
        $dw = date('l', strtotime($r['created_at']));
        $days[$dw] = ($days[$dw] ?? 0) + 1;
        $cust[$r['customer_id']] = ($cust[$r['customer_id']] ?? 0) + 1;
        $sales += (float)$r['total'];
        if ($r['is_rush']) $rush++;
        if ($r['ready_at']) {
            $readyN++;
            $turn += (strtotime($r['ready_at']) - strtotime($r['created_at'])) / 3600;
            if (strtotime($r['ready_at']) <= strtotime($r['estimated_ready_at']) + 900) $onTime++;
        }
    }
    uasort($svc, fn($a, $b) => $b['sales'] <=> $a['sales']);
    arsort($hours);
    arsort($days);
    $s['orders_30d'] = count($rows);
    $s['avg_order_30d'] = count($rows) ? round($sales / count($rows), 2) : 0;
    $s['rush_share_30d'] = count($rows) ? round($rush / count($rows) * 100) : 0;
    $s['top_services'] = array_slice($svc, 0, 5, true);
    $s['busiest_hours'] = array_slice($hours, 0, 3, true);
    $s['busiest_days'] = array_slice($days, 0, 2, true);
    $s['on_time_rate'] = $readyN ? round($onTime / $readyN * 100) : null;
    $s['avg_turnaround_h'] = $readyN ? round($turn / $readyN, 1) : null;
    $s['customers_30d'] = count($cust);
    $s['repeat_customers_30d'] = count(array_filter($cust, fn($n) => $n >= 2));

    $s['staff'] = q_all("SELECT u.full_name,
        (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id AND o.created_at >= ?) AS orders,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.received_by = u.id AND p.is_void = 0 AND p.created_at >= ?) AS collected,
        (SELECT COUNT(*) FROM discrepancies x WHERE x.reported_by = u.id AND x.created_at >= ?) AS discrepancies
        FROM users u WHERE u.role = 'employee' AND u.is_active = 1 ORDER BY u.full_name", [$d('-30 days'), $d('-30 days'), $d('-30 days')]);
    $s['services_list'] = q_all('SELECT name, unit, price, min_qty, base_hours FROM services WHERE is_active = 1 ORDER BY sort_order');
    return $s;
}

function snapshot_text(array $s): string
{
    $l = [];
    $l[] = "Shop: {$s['shop']} (single branch). Now: {$s['now']}.";
    $l[] = 'Revenue collected: today ' . money($s['revenue_today']) . ', yesterday ' . money($s['revenue_yesterday']) .
        ', last 7 days ' . money($s['revenue_7d']) . ' (previous 7 days ' . money($s['revenue_prev_7d']) . '), this month ' . money($s['revenue_month']) . '.';
    $l[] = 'This month expenses ' . money($s['expenses_month']) . ', profit ' . money($s['profit_month']) . '.';
    $l[] = "Orders: today {$s['orders_today']}, last 7 days {$s['orders_7d']} (previous 7 days {$s['orders_prev_7d']}), last 30 days {$s['orders_30d']}, average order " . money($s['avg_order_30d']) . ", rush {$s['rush_share_30d']}%.";
    $l[] = "Now: {$s['active']} in process, workload {$s['workload']}, {$s['ready']} ready for pickup ({$s['unclaimed_24h']} waiting over 24h, {$s['unclaimed_7d']} over 7 days).";
    $l[] = "Unpaid balances: " . money($s['unpaid_total']) . " on {$s['unpaid_count']} orders. Open discrepancies: {$s['open_discrepancies']}. Cash drawer shortages last 30 days: " . money(abs($s['cash_short_30d'])) . '.';
    $l[] = 'On-time rate (30d): ' . ($s['on_time_rate'] === null ? 'n/a' : $s['on_time_rate'] . '%') . ', average turnaround ' . ($s['avg_turnaround_h'] ?? 'n/a') . ' h.';
    $l[] = "Customers (30d): {$s['customers_30d']}, repeat customers {$s['repeat_customers_30d']}.";
    $svc = [];
    foreach ($s['top_services'] as $n => $v) $svc[] = "$n {$v['orders']} orders " . money($v['sales']);
    $l[] = 'Top services (30d): ' . ($svc ? implode('; ', $svc) : 'none') . '.';
    $h = [];
    foreach ($s['busiest_hours'] as $hr => $n) $h[] = date('g A', mktime((int)$hr, 0)) . " ($n)";
    $l[] = 'Busiest drop-off hours: ' . ($h ? implode(', ', $h) : 'n/a') . '. Busiest days: ' . ($s['busiest_days'] ? implode(', ', array_keys($s['busiest_days'])) : 'n/a') . '.';
    $st = [];
    foreach ($s['staff'] as $x) $st[] = "{$x['full_name']}: {$x['orders']} orders, collected " . money($x['collected']) . ", {$x['discrepancies']} discrepancies";
    $l[] = 'Staff (30d): ' . ($st ? implode('; ', $st) : 'none') . '.';
    $sv = [];
    foreach ($s['services_list'] as $x) $sv[] = $x['name'] . ' ' . money($x['price']) . '/' . $x['unit'];
    $l[] = 'Price list: ' . implode('; ', $sv) . '. Rush +' . setting('rush_percent', 25) . '%. Loyalty: every ' . setting('loyalty_every', 0) . 'th order ' . setting('loyalty_discount_percent', 0) . '% off.';
    return implode("\n", $l);
}

/** Rule-based tips (always available). */
function rule_insights(array $s): array
{
    $tips = [];
    if ($s['revenue_prev_7d'] > 0) {
        $ch = round(($s['revenue_7d'] - $s['revenue_prev_7d']) / $s['revenue_prev_7d'] * 100);
        $tips[] = $ch >= 0
            ? "📈 Sales this week are up {$ch}% vs last week (" . money($s['revenue_7d']) . ').'
            : '📉 Sales this week are down ' . abs($ch) . '% vs last week. Consider a promo or texting regular customers.';
    }
    if ($s['unclaimed_24h'] > 0) {
        $tips[] = "🧺 {$s['unclaimed_24h']} ready order(s) waiting over 24 hours. Reminders are automatic — call those still not claimed after 3 days.";
    }
    if ($s['unpaid_total'] > 0) {
        $tips[] = '💸 ' . money($s['unpaid_total']) . " is unpaid on {$s['unpaid_count']} order(s). Collect at pickup.";
    }
    if ($s['open_discrepancies'] > 0) {
        $tips[] = "⚠️ {$s['open_discrepancies']} discrepancy(ies) need your review.";
    }
    if ($s['on_time_rate'] !== null && $s['on_time_rate'] < 85) {
        $tips[] = "⏱️ On-time rate is {$s['on_time_rate']}%. Lower the shop capacity in Settings so promised times are more realistic.";
    }
    if ($s['busiest_hours']) {
        $hr = (int)array_key_first($s['busiest_hours']);
        $tips[] = '👥 Busiest drop-off time is around ' . date('g A', mktime($hr, 0)) . '. Have 2 people at the counter then.';
    }
    if ($s['top_services']) {
        $tips[] = '⭐ Best seller: ' . array_key_first($s['top_services']) . '.';
    }
    if (abs($s['cash_short_30d']) > 0) {
        $tips[] = '🔎 Cash drawer was short by ' . money(abs($s['cash_short_30d'])) . ' in the last 30 days. Check the cash closings.';
    }
    if (!$tips) {
        $tips[] = '✅ Everything looks good. Keep recording every order so the reports stay accurate.';
    }
    return array_slice($tips, 0, 5);
}

/** Owner dashboard tips. AI version is cached once per day. */
function daily_insights(bool $refresh = false): array
{
    $snap = business_snapshot();
    if (ai_enabled()) {
        $today = date('Y-m-d', clock());
        if (!$refresh && setting('ai_insight_date') === $today && setting('ai_insight_text')) {
            $cached = json_decode((string)setting('ai_insight_text'), true);
            if (is_array($cached) && $cached) return ['items' => $cached, 'source' => 'ai'];
        }
        $reply = ai_call(
            'You are a practical business coach for a small Philippine laundry shop owner. Using ONLY the data given, write 4 short, specific, actionable tips ' .
            '(max 25 words each, start each with one emoji). Return ONLY a JSON object: {"tips": ["...", "..."]}.',
            [['role' => 'user', 'content' => snapshot_text($snap)]], 500);
        $j = ai_extract_json($reply);
        if ($j && !empty($j['tips']) && is_array($j['tips'])) {
            $tips = array_slice(array_map(fn($x) => mb_substr((string)$x, 0, 220), $j['tips']), 0, 5);
            set_setting('ai_insight_date', $today);
            set_setting('ai_insight_text', json_encode($tips, JSON_UNESCAPED_UNICODE));
            return ['items' => $tips, 'source' => 'ai'];
        }
    }
    return ['items' => rule_insights($snap), 'source' => 'offline'];
}

// =====================================================================
// 3. OWNER BUSINESS ASSISTANT
// =====================================================================
function ai_business_answer(string $question, array $history = []): array
{
    $snap = business_snapshot();
    if (ai_enabled()) {
        $msgs = [];
        foreach (array_slice($history, -6) as $h) {
            $msgs[] = ['role' => 'user', 'content' => $h['q']];
            $msgs[] = ['role' => 'assistant', 'content' => $h['a']];
        }
        $msgs[] = ['role' => 'user', 'content' => mb_substr($question, 0, 800)];
        $reply = ai_call(
            "You are the business assistant inside the owner's laundry management system. Answer using ONLY the shop data below; " .
            "if the data does not contain the answer, say so and suggest which report page to check (Reports, Transactions, Audit log, Discrepancies, Expenses). " .
            "Be brief (max 6 short lines), practical and friendly. Use ₱. Reply in the same language the owner used (English, Tagalog or Taglish).\n\nSHOP DATA:\n" .
            snapshot_text($snap), $msgs, 600);
        if ($reply) {
            return ['text' => $reply, 'source' => 'ai'];
        }
    }
    return ['text' => offline_business_answer($question, $snap), 'source' => 'offline'];
}

function offline_business_answer(string $q, array $s): string
{
    $q = mb_strtolower($q);
    $has = fn($re) => preg_match($re, $q);
    if ($has('/(profit|tubo|expense|gastos|gastusin|net)/u')) {
        return 'This month: sales ' . money($s['revenue_month']) . ', expenses ' . money($s['expenses_month']) . ', profit ' . money($s['profit_month']) . '. Record expenses in the Expenses page so profit stays accurate.';
    }
    if ($has('/(sale|sales|revenue|kita|benta|income|kinita|earn)/u')) {
        return 'Sales collected — today: ' . money($s['revenue_today']) . ' · yesterday: ' . money($s['revenue_yesterday']) . ' · last 7 days: ' . money($s['revenue_7d']) .
            ' (previous 7 days ' . money($s['revenue_prev_7d']) . ') · this month: ' . money($s['revenue_month']) . '.';
    }
    if ($has('/(best|top|service|serbisyo|pinakamabenta|popular|mabenta)/u')) {
        $o = [];
        foreach ($s['top_services'] as $n => $v) $o[] = "$n — {$v['orders']} orders, " . money($v['sales']);
        return $o ? "Top services (last 30 days):\n" . implode("\n", $o) : 'No orders in the last 30 days yet.';
    }
    if ($has('/(busy|busiest|peak|oras|matao|dami ng tao|schedule)/u')) {
        $h = [];
        foreach ($s['busiest_hours'] as $hr => $n) $h[] = date('g A', mktime((int)$hr, 0)) . " ($n orders)";
        return 'Busiest drop-off hours: ' . ($h ? implode(', ', $h) : 'not enough data') . '. Busiest days: ' . ($s['busiest_days'] ? implode(', ', array_keys($s['busiest_days'])) : 'not enough data') . '.';
    }
    if ($has('/(unpaid|utang|balance|hindi bayad|di bayad|collect|singil)/u')) {
        return money($s['unpaid_total']) . " is still unpaid on {$s['unpaid_count']} order(s). See Orders → Unpaid/Partial.";
    }
    if ($has('/(unclaimed|hindi kinukuha|di kinukuha|ready|pickup|kunin)/u')) {
        return "{$s['ready']} order(s) are ready for pickup — {$s['unclaimed_24h']} waiting over 24 hours, {$s['unclaimed_7d']} over 7 days.";
    }
    if ($has('/(staff|employee|empleyado|trabahador|worker|tauhan)/u')) {
        $o = [];
        foreach ($s['staff'] as $x) $o[] = "{$x['full_name']}: {$x['orders']} orders, collected " . money($x['collected']) . ", {$x['discrepancies']} discrepancies";
        return $o ? "Staff, last 30 days:\n" . implode("\n", $o) : 'No active employees.';
    }
    if ($has('/(discrep|anomal|suspicious|daya|nawawala|kulang|short|missing|cash)/u')) {
        return "Open discrepancies: {$s['open_discrepancies']}. Cash drawer shortages (30 days): " . money(abs($s['cash_short_30d'])) . '. Review them on the Discrepancies page.';
    }
    if ($has('/(late|on time|ontime|delay|huli|matagal|turnaround)/u')) {
        return 'On-time rate (30 days): ' . ($s['on_time_rate'] === null ? 'not enough data' : $s['on_time_rate'] . '%') . '. Average turnaround: ' . ($s['avg_turnaround_h'] ?? '—') . ' hours.';
    }
    if ($has('/(customer|suki|repeat|loyal)/u')) {
        return "Customers in the last 30 days: {$s['customers_30d']}, of which {$s['repeat_customers_30d']} came back 2 or more times.";
    }
    return "Here's a quick summary:\n• Today: " . money($s['revenue_today']) . " from {$s['orders_today']} orders\n• This month: " . money($s['revenue_month']) .
        ' sales, ' . money($s['profit_month']) . " profit\n• Ready for pickup: {$s['ready']} · Unpaid: " . money($s['unpaid_total']) .
        "\n• Open discrepancies: {$s['open_discrepancies']}\nTry asking about sales, profit, best services, busiest hours, unpaid, unclaimed, staff or discrepancies.";
}

// =====================================================================
// 4. CUSTOMER HELP
// =====================================================================
function customer_context(int $userId): array
{
    $ids = customer_ids_for_user($userId);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return q_all("SELECT o.*, s.name AS service_name, s.unit FROM orders o JOIN services s ON s.id = o.service_id
                  WHERE o.customer_id IN ($ph) ORDER BY o.id DESC LIMIT 8", $ids);
}

function ai_customer_answer(array $user, string $question): array
{
    $orders = customer_context((int)$user['id']);
    $active = array_filter($orders, fn($o) => !in_array($o['status'], ['claimed', 'cancelled'], true));
    $info = 'Shop: ' . shop_name() . ', ' . setting('shop_address', '') . ', phone ' . setting('shop_phone', '') .
        '. Open ' . date('g A', mktime((int)setting('open_hour', 8), 0)) . ' to ' . date('g A', mktime((int)setting('close_hour', 20) % 24, 0)) . ".\n";
    foreach (active_services() as $s) {
        $info .= '- ' . $s['name'] . ': ' . money($s['price']) . ' per ' . $s['unit'] . ((float)$s['min_qty'] > 0 ? ' (minimum ' . qty_fmt($s['min_qty'], $s['unit']) . ')' : '') . "\n";
    }
    $info .= 'Rush: +' . setting('rush_percent', 25) . '% and faster. Payments: Cash, GCash, Maya, bank transfer. Shop workload now: ' . workload()['level'] . ".\n";
    $info .= "This customer's orders:\n";
    foreach ($orders as $o) {
        $info .= "- {$o['order_no']}: {$o['service_name']} " . qty_fmt($o['weight'], $o['unit']) . ', status ' . STATUS_LABELS[$o['status']] .
            ($o['status'] === 'ready' ? ', pickup code ' . $o['pickup_code'] : '') .
            (!in_array($o['status'], ['ready', 'claimed', 'cancelled'], true) ? ', estimated ready ' . fmt_when($o['estimated_ready_at']) : '') .
            ', total ' . money($o['total']) . ', balance ' . money(max(0, (float)$o['total'] - (float)$o['amount_paid'])) . "\n";
    }
    if (!$orders) $info .= "(no orders yet)\n";
    if (setting('announcement')) $info .= 'Announcement: ' . setting('announcement') . "\n";

    if (ai_enabled()) {
        $reply = ai_call("You are the friendly help assistant of a laundry shop in the Philippines. Answer the customer using ONLY this information. " .
            "Be short (max 4 sentences), warm and clear. Reply in the customer's language (English, Tagalog or Taglish). " .
            "If you cannot answer, ask them to call the shop. Never make up prices, times or orders.\n\n" . $info,
            [['role' => 'user', 'content' => mb_substr($question, 0, 500)]], 350);
        if ($reply) return ['text' => $reply, 'source' => 'ai'];
    }

    // Offline answers (English or Tagalog, following the customer's language)
    $tl = lang() === 'tl';
    $open = date('g A', mktime((int)setting('open_hour', 8), 0));
    $close = date('g A', mktime((int)setting('close_hour', 20) % 24, 0));
    $q = mb_strtolower($question);
    if (preg_match('/(magkano|presyo|price|how much|rate|bayad sa|cost)/u', $q)) {
        $lines = [];
        foreach (active_services() as $s) {
            $lines[] = '• ' . $s['name'] . ': ' . money($s['price']) . ' / ' . ($s['unit'] === 'kg' ? 'kg' : 'pc') .
                ((float)$s['min_qty'] > 1 ? ' (min ' . qty_fmt($s['min_qty'], $s['unit']) . ')' : '');
        }
        return ['text' => ($tl ? "Ang aming presyo:\n" : "Our prices:\n") . implode("\n", $lines) . "\n" .
            ($tl ? 'Rush: dagdag na ' : 'Rush: +') . setting('rush_percent', 25) . '%.', 'source' => 'offline'];
    }
    if (preg_match('/(open|bukas|oras|hours|sarado|close|anong oras)/u', $q)) {
        return ['text' => ($tl ? "Bukas kami $open hanggang $close sa " : "We are open $open to $close at ") . setting('shop_address', '') . '. ' .
            ($tl ? 'Tumawag sa ' : 'Call ') . setting('shop_phone', '') . ($tl ? ' para sa ibang tanong.' : ' for questions.'), 'source' => 'offline'];
    }
    if (preg_match('/(code|claim|kunin|pickup|pick up|pick-up)/u', $q)) {
        $ready = array_filter($active, fn($o) => $o['status'] === 'ready');
        if ($ready) {
            $t = [];
            foreach ($ready as $o) $t[] = "{$o['order_no']}: pickup code {$o['pickup_code']}";
            return ['text' => ($tl ? 'Handa na! Ipakita ang code na ito sa counter: ' : 'Ready for pickup! Show this code at the counter: ') . implode('; ', $t), 'source' => 'offline'];
        }
        return ['text' => $tl ? 'Wala pang handang order. Aabisuhan ka namin agad kapag handa na.' : 'None of your orders is ready yet. We will notify you the moment it is ready.', 'source' => 'offline'];
    }
    if (preg_match('/(gcash|maya|pay|bayad|balance|utang)/u', $q)) {
        $bal = array_sum(array_map(fn($o) => max(0, (float)$o['total'] - (float)$o['amount_paid']), $active));
        return ['text' => ($tl ? 'Puwede kang magbayad ng Cash, GCash, Maya o bank transfer sa counter. Babayaran mo ngayon: ' : 'You can pay by Cash, GCash, Maya or bank transfer at the counter. Your current balance: ') . money($bal) . '.', 'source' => 'offline'];
    }
    if ($active) {
        $t = [];
        foreach ($active as $o) {
            $t[] = $o['order_no'] . ': ' . t(STATUS_LABELS[$o['status']]) . ($o['status'] === 'ready'
                ? ' — pickup code ' . $o['pickup_code']
                : ($tl ? ', handa sa ' : ', ready by ') . fmt_when($o['estimated_ready_at']));
        }
        return ['text' => implode("\n", $t), 'source' => 'offline'];
    }
    return ['text' => $tl ? 'Wala kang labadang ginagawa ngayon. Para sa ibang tanong, tumawag sa ' . setting('shop_phone', '') . '.'
                          : 'You have no laundry in process right now. For other questions please call us at ' . setting('shop_phone', '') . '.', 'source' => 'offline'];
}
