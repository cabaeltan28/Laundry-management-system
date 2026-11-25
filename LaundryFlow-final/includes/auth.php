<?php
/**
 * Login, roles and access control.
 */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $row = q_one('SELECT id, role, full_name, username, email, phone, lang, theme, is_active FROM users WHERE id = ?', [$id]);
    if (!$row || !(int)$row['is_active']) {
        // Account removed or deactivated -> force logout
        $_SESSION = [];
        return $user = null;
    }
    return $user = $row;
}

function user_id(): ?int
{
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

function home_for(string $role): string
{
    switch ($role) {
        case 'owner':    return 'owner/dashboard.php';
        case 'employee': return 'staff/dashboard.php';
        default:         return 'customer/dashboard.php';
    }
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('info', 'Please log in to continue.');
        redirect('login.php');
    }
    return $u;
}

/** Allow only the listed roles. Example: require_role(['owner','employee']) */
function require_role($roles): array
{
    $roles = (array)$roles;
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        layout_start('Access denied', '');
        echo '<div class="card"><h2>Access denied</h2><p class="muted">Your account does not have permission to open this page.</p>';
        echo '<a class="btn" href="' . e(url(home_for($u['role']))) . '">Go to my dashboard</a></div>';
        layout_end();
        exit;
    }
    return $u;
}

function is_staff(): bool
{
    $u = current_user();
    return $u && in_array($u['role'], ['owner', 'employee'], true);
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------
// Brute-force protection: max 5 failed logins per 15 minutes
// ---------------------------------------------------------------------
function login_locked(string $identifier): bool
{
    $since = date('Y-m-d H:i:s', time() - 15 * 60);
    // 5 wrong tries for the same account/code, or 30 from the same computer/network
    $perId = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND created_at >= ?', [mb_strtolower($identifier), $since]);
    $perIp = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND created_at >= ?', [client_ip(), $since]);
    return $perId >= 5 || $perIp >= 30;
}

function record_failed_login(string $identifier): void
{
    q('INSERT INTO login_attempts (identifier, ip_address, created_at) VALUES (?, ?, ?)',
        [mb_strtolower($identifier), client_ip(), now()]);
}

function clear_failed_logins(string $identifier): void
{
    q('DELETE FROM login_attempts WHERE identifier = ?', [mb_strtolower($identifier)]);
}

/** Password rule: at least 8 characters with a letter and a number. */
function password_problem(string $pw): ?string
{
    if (strlen($pw) < 8) return t('Password must be at least 8 characters.');
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return t('Password must contain letters and numbers.');
    return null;
}
