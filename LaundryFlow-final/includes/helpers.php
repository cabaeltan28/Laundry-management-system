<?php
/**
 * Small helper functions used everywhere.
 */

/** Escape output for HTML (prevents XSS). Use for EVERY value printed. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (BASE_URL !== '') {
        return $base = rtrim(BASE_URL, '/');
    }
    $root = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));
    $doc  = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = ($doc !== '' && stripos($root, $doc) === 0) ? substr($root, strlen($doc)) : '';
    return $base = rtrim($base, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Current date-time string in the shop's timezone. */
function now(): string
{
    return date('Y-m-d H:i:s', clock());
}

/** Current timestamp. The demo-data generator can move this clock back in time. */
function clock(): int
{
    return $GLOBALS['LF_CLOCK'] ?? time();
}

function money($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function qty_fmt($qty, string $unit): string
{
    $n = rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');
    return $unit === 'piece' ? $n . ' pc' . ((float)$qty == 1 ? '' : 's') : $n . ' kg';
}

function fmt_dt(?string $dt, string $format = 'M j, g:i A'): string
{
    return $dt ? date($format, strtotime($dt)) : '—';
}

/** "Today · 6:30 PM", "Tomorrow · 9:00 AM", "Oct 7 · 2:00 PM" */
function fmt_when(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d')) {
        $day = 'Today';
    } elseif ($d === date('Y-m-d', strtotime('+1 day'))) {
        $day = 'Tomorrow';
    } elseif ($d === date('Y-m-d', strtotime('-1 day'))) {
        $day = 'Yesterday';
    } else {
        $day = date('M j', $ts);
    }
    return $day . ' · ' . date('g:i A', $ts);
}

/** "5 min ago", "3 h ago" */
function time_ago(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    return floor($diff / 86400) . ' d ago';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

/** Read a trimmed POST value. */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/** Read a trimmed GET value. */
function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// ---------------------------------------------------------------------
// CSRF protection: every form includes csrf_field(), every POST handler
// calls verify_csrf().
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Security check failed (expired form). Please go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------------
// Flash messages (shown once after redirect)
// ---------------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------
// Settings (key/value table)
// ---------------------------------------------------------------------
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reload') {
        $cache = [];
        foreach (q_all('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
        if ($key === '__reload') {
            return null;
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $exists = q_val('SELECT COUNT(*) FROM settings WHERE skey = ?', [$key]);
    if ($exists) {
        q('UPDATE settings SET svalue = ? WHERE skey = ?', [$value, $key]);
    } else {
        q('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [$key, $value]);
    }
    setting('__reload');
}

function shop_name(): string
{
    return (string)setting('shop_name', 'LaundryFlow');
}

/** Validate a Philippine mobile number. Returns normalized 09XXXXXXXXX or null. */
function normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
}

function phone_fmt(?string $p): string
{
    if (!$p || strlen($p) !== 11) return (string)$p;
    return substr($p, 0, 4) . ' ' . substr($p, 4, 3) . ' ' . substr($p, 7);
}

function mask_phone(?string $p): string
{
    if (!$p || strlen($p) < 7) return '••••';
    return substr($p, 0, 4) . ' ••• ' . substr($p, -4);
}

function random_code(int $len, string $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/**
 * Write one CSV row safely: values starting with = + - @ are prefixed with '
 * so Excel never runs them as formulas (CSV injection protection).
 */
function csv_row($out, array $row): void
{
    $row = array_map(function ($v) {
        $v = (string)($v ?? '');
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'" . $v : $v;
    }, $row);
    fputcsv($out, $row, ',', '"', '\\');
}
