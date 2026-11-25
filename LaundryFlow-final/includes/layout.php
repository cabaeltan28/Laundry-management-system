<?php
/**
 * Page layout: sidebar for owner/employee, top bar for customers.
 *
 *   layout_start('Orders', 'orders', ['subtitle' => '...', 'refresh' => 60, 'actions' => '<a ...>']);
 *   ... page html ...
 *   layout_end();
 *
 * EASY TO UPDATE:
 *   - Colors: Owner > Settings > Theme (or add a theme to THEMES below)
 *   - Menu items / shortcut keys: nav_items() below
 *   - Words: includes/lang_tl.php (Tagalog)
 */

// name => [label, brand, brand-dark, brand-soft, sidebar, sidebar-hover, sidebar-text]
// To add a theme, copy one line and change the colors.
const THEMES = [
    'pink'     => ['Pink (default)', '#db2777', '#be185d', '#fdeef5', '#4a0f2e', '#6b1a45', '#fbd3e6'],
    'rose'     => ['Rose', '#e11d48', '#be123c', '#ffe4e9', '#4c0519', '#6f1028', '#fecdd3'],
    'blush'    => ['Blush', '#ec7fa9', '#d45d8b', '#fff1f6', '#5b2340', '#7a3357', '#ffd9e8'],
    'purple'   => ['Lavender', '#9333ea', '#7e22ce', '#f3e8ff', '#2e1065', '#4c1d95', '#e9d5ff'],
    'coral'    => ['Coral', '#f0645a', '#d1493f', '#fff0ee', '#4a1d18', '#6b2a22', '#ffd5cf'],
    'mint'     => ['Mint', '#10b981', '#059669', '#e7f8f1', '#073b2c', '#0d5a42', '#bff0dd'],
    'teal'     => ['Teal', '#0f8b7d', '#0b6e63', '#e3f4f1', '#0f3b37', '#164a45', '#cfe2df'],
    'blue'     => ['Blue', '#2563eb', '#1d4ed8', '#e8f0fe', '#172554', '#1e3a8a', '#c7d7fe'],
    'charcoal' => ['Charcoal', '#475569', '#334155', '#eef2f6', '#111827', '#1f2937', '#d1d5db'],
];

/** Mix a #rrggbb color with white (amount > 0) or black (amount < 0). */
function mix_color(string $hex, float $amount): string
{
    $hex = ltrim($hex, '#');
    $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $target = $amount >= 0 ? 255 : 0;
    $a = abs($amount);
    $out = '#';
    foreach ($rgb as $c) {
        $out .= str_pad(dechex((int)round($c + ($target - $c) * $a)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/** Colors for a theme key (presets or 'custom' from Settings). */
function theme_colors(string $key): array
{
    if ($key === 'custom') {
        $c = (string)setting('custom_color', '#db2777');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $c = '#db2777';
        return ['Custom', $c, mix_color($c, -0.18), mix_color($c, 0.9), mix_color($c, -0.72), mix_color($c, -0.6), mix_color($c, 0.78)];
    }
    return THEMES[$key] ?? THEMES['pink'];
}

/** The theme for the current page: the user's own choice (if allowed) or the shop default. */
function active_theme(): string
{
    $shop = (string)setting('theme', 'pink');
    $u = current_user();
    if ($u && !empty($u['theme']) && setting('allow_user_theme', '1') === '1' && (isset(THEMES[$u['theme']]) || $u['theme'] === 'custom')) {
        return $u['theme'];
    }
    return $shop;
}

function theme_css(): string
{
    $t = theme_colors(active_theme());
    return ':root{--brand:' . $t[1] . ';--brand-dark:' . $t[2] . ';--brand-soft:' . $t[3] . ';--side:' . $t[4] . ';--side-2:' . $t[5] . ';--side-text:' . $t[6] . ';}';
}

/** [key, icon, label, link, shortcut letter] — shortcut = Alt + letter */
function nav_items(string $role): array
{
    if ($role === 'owner') {
        return [
            ['dashboard', '🏠', 'Dashboard', 'owner/dashboard.php', 'h'],
            ['orders', '📋', 'Orders', 'staff/orders.php', 'o'],
            ['new_order', '➕', 'New order', 'staff/new_order.php', 'n'],
            ['pickups', '✅', 'Ready for pickup', 'staff/pickups.php', 'p'],
            ['customers', '👥', 'Customers', 'staff/customers.php', 'c'],
            ['assistant', '✨', 'AI assistant', 'owner/assistant.php', 'a'],
            ['reports', '📊', 'Reports', 'owner/reports.php', 'r'],
            ['transactions', '💳', 'Transactions', 'owner/transactions.php', 't'],
            ['expenses', '🧾', 'Expenses', 'owner/expenses.php', 'y'],
            ['closing', '💵', 'Cash closing', 'staff/closing.php', 'k'],
            ['discrepancies', '⚠️', 'Discrepancies', 'owner/discrepancies.php', 'x'],
            ['audit', '🛡️', 'Audit log', 'owner/audit.php', 'l'],
            ['employees', '🧑‍🤝‍🧑', 'Employees', 'owner/employees.php', 'm'],
            ['services', '🏷️', 'Services & Pricing', 'owner/services.php', 'v'],
            ['notifications', '🔔', 'Notifications', 'owner/notifications.php', 'b'],
            ['settings', '⚙️', 'Settings', 'owner/settings.php', 'g'],
            ['help', '❓', 'Help & shortcuts', 'help.php', 'i'],
            ['account', '🙂', 'My account', 'account.php', 'u'],
        ];
    }
    if ($role === 'employee') {
        return [
            ['dashboard', '🏠', 'Counter', 'staff/dashboard.php', 'h'],
            ['new_order', '➕', 'New order', 'staff/new_order.php', 'n'],
            ['pickups', '✅', 'Ready for pickup', 'staff/pickups.php', 'p'],
            ['orders', '📋', 'Orders', 'staff/orders.php', 'o'],
            ['customers', '👥', 'Customers', 'staff/customers.php', 'c'],
            ['closing', '💵', 'Cash closing', 'staff/closing.php', 'k'],
            ['switch', '🔁', 'Switch user', 'staff/switch.php', 'w'],
            ['help', '❓', 'Help & shortcuts', 'help.php', 'i'],
            ['account', '🙂', 'My account', 'account.php', 'u'],
        ];
    }
    return [
        ['dashboard', '🧺', 'Track', 'customer/dashboard.php', 'h'],
        ['orders', '📋', 'History', 'customer/orders.php', 'o'],
        ['help', '💬', 'Ask us', 'customer/help.php', 'a'],
        ['notifications', '🔔', 'Updates', 'customer/notifications.php', 'b'],
        ['account', '🙂', 'Account', 'account.php', 'u'],
    ];
}

function render_flashes(): void
{
    foreach (take_flashes() as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function layout_head(string $title, int $refresh = 0): void
{
    echo '<!DOCTYPE html><html lang="' . (lang() === 'tl' ? 'tl' : 'en') . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    if ($refresh > 0) {
        echo '<meta http-equiv="refresh" content="' . (int)$refresh . '">';
    }
    echo '<title>' . e($title) . ' · ' . e(shop_name()) . '</title>';
    echo '<link rel="stylesheet" href="' . e(url('assets/style.css')) . '?v=' . e(APP_VERSION) . '">';
    echo '<style>' . theme_css() . '</style>';
    echo '</head>';
}

function announcement_bar(): string
{
    $a = trim((string)setting('announcement', ''));
    return $a !== '' ? '<div class="announce">📢 ' . e($a) . '</div>' : '';
}

function layout_start(string $title, string $active, array $opt = []): void
{
    $u = current_user();
    layout_head($title, (int)($opt['refresh'] ?? 0));
    if (!$u) {
        echo '<body class="plain"><main class="plain-main' . (!empty($opt['wide']) ? ' wide' : '') . '">';
        render_flashes();
        return;
    }
    $role = $u['role'];
    $unread = unread_count($u);
    $subtitle = $opt['subtitle'] ?? date('l, j M Y · g:i A', clock());

    if ($role === 'customer') {
        echo '<body class="customer">';
        echo '<header class="c-top"><a class="brand dark" href="' . e(url('customer/dashboard.php')) . '"><span class="logo">🧺</span>' . e(shop_name()) . '</a>';
        echo '<nav class="c-nav">';
        foreach (nav_items($role) as [$key, $icon, $label, $href, $ak]) {
            $badge = ($key === 'notifications' && $unread) ? ' <span class="count">' . $unread . '</span>' : '';
            echo '<a accesskey="' . e($ak) . '" class="' . ($key === $active ? 'active' : '') . '" href="' . e(url($href)) . '"><span class="c-ico">' . $icon . '</span>' . e(t($label)) . $badge . '</a>';
        }
        echo '</nav>';
        echo '<div class="btn-row">' . lang_switch('mini') . '<form method="post" action="' . e(url('logout.php')) . '" class="inline">' . csrf_field() . '<button class="btn btn-ghost btn-sm">' . e(t('Log out')) . '</button></form></div>';
        echo '</header><main class="c-main">' . announcement_bar();
        render_flashes();
        return;
    }

    $openDisc = $role === 'owner' ? open_discrepancy_count() : 0;
    echo '<body class="role-' . e($role) . '"><div class="shell"><aside class="sidebar">';
    echo '<a class="brand" href="' . e(url(home_for($role))) . '"><span class="logo">🧺</span><span>' . e(shop_name()) .
        '<small>' . e(t($role === 'owner' ? 'OWNER CONSOLE' : 'STAFF WORKSPACE')) . '</small></span></a>';
    echo '<nav>';
    foreach (nav_items($role) as [$key, $icon, $label, $href, $ak]) {
        $badge = '';
        if ($key === 'discrepancies' && $openDisc) $badge = '<span class="count red">' . $openDisc . '</span>';
        if ($key === 'notifications' && $unread) $badge = '<span class="count">' . $unread . '</span>';
        if ($badge === '') $badge = '<kbd>' . strtoupper(e($ak)) . '</kbd>';
        echo '<a accesskey="' . e($ak) . '" class="' . ($key === $active ? 'active' : '') . '" href="' . e(url($href)) . '"><span class="ico">' . $icon . '</span>' . e(t($label)) . $badge . '</a>';
    }
    echo '</nav>';
    echo lang_switch();
    echo '<div class="me"><span class="avatar">' . e(initials($u['full_name'])) . '</span><div><b>' . e($u['full_name']) . '</b><small>' .
        e(t($role === 'owner' ? 'Business owner' : 'Counter staff')) . '</small></div></div>';
    echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field() . '<button class="btn-sidebar">🚪 ' . e(t('Log out')) . '</button></form>';
    echo '</aside><div class="content">';
    echo '<header class="topbar"><div><h1>' . e($title) . '</h1><p class="muted">' . e($subtitle) . '</p></div><div class="top-actions">';
    echo $opt['actions'] ?? '';
    if ($role === 'owner') {
        echo '<a class="bell" href="' . e(url('owner/notifications.php')) . '" title="' . e(t('Notifications')) . '">🔔' . ($unread ? '<span>' . $unread . '</span>' : '') . '</a>';
    }
    echo '</div></header><main class="page">';
    render_flashes();
}

function layout_end(): void
{
    $u = current_user();
    if (!$u) {
        echo '</main></body></html>';
        return;
    }
    if ($u['role'] === 'customer') {
        echo '</main><footer class="c-foot">' . e(shop_name()) . ' · ' . e(setting('shop_address', '')) . ' · ' . e(setting('shop_phone', '')) . '</footer></body></html>';
        return;
    }
    echo '<footer class="page-foot">' . e(t('Shortcut keys')) . ': <kbd>Alt</kbd> + ' . e(t('letter shown in the menu')) . ' · <a href="' . e(url('help.php')) . '">' . e(t('Help')) . '</a> · v' . e(APP_VERSION) . '</footer>';
    echo '</main></div></div></body></html>';
}

/** Workload card used on several dashboards. */
function workload_card(array $wl, bool $staffView = true): string
{
    $cls = strtolower($wl['level']);
    $h = '<div class="card workload"><div class="card-head"><h3>' . e(t('Current workload')) . '</h3>';
    if ($staffView) {
        $h .= '<a href="' . e(url('staff/orders.php?filter=active')) . '">' . e(t('View queue')) . '</a>';
    }
    $h .= '</div><div class="wl-row"><div><span class="pill wl-' . $cls . '">● ' . e(mb_strtoupper(t($wl['level']))) . '</span>';
    $h .= '<p class="muted small">' . (int)$wl['active'] . ' ' . e(t('active orders')) . ($staffView ? ' · ' . e($wl['load_kg']) . ' ' . e(t('kg in line')) : '') . '</p></div>';
    $h .= '<div class="right"><small class="muted">' . e(t('EST. PROCESSING')) . '</small><div class="big">' . e(str_replace('hours', t('hours'), $wl['est_text'])) . '</div></div></div>';
    $h .= '<div class="meter"><span class="wl-' . $cls . '" style="width:' . max(4, (int)$wl['percent']) . '%"></span></div>';
    if ($staffView) {
        $c = $wl['counts'];
        $h .= '<div class="chips"><span>' . e(t('Waiting')) . ' ' . ($c['received'] + $c['weighed'] + $c['queued']) . '</span><span>' . e(t('Washing')) . ' ' . $c['washing'] .
            '</span><span>' . e(t('Drying')) . ' ' . $c['drying'] . '</span><span>' . e(t('Folding')) . ' ' . $c['folding'] . '</span><span>' . e(t('Ready')) . ' ' . (int)$wl['ready'] . '</span></div>';
    }
    return $h . '</div>';
}

/** Horizontal progress stepper for an order. */
function order_stepper(string $status): string
{
    $steps = STATUS_FLOW;
    $cur = array_search($status, $steps, true);
    if ($status === 'cancelled') {
        return '<div class="alert alert-error">' . e(t('This order was cancelled.')) . '</div>';
    }
    $icons = ['received' => '📥', 'weighed' => '⚖️', 'queued' => '⏳', 'washing' => '🫧', 'drying' => '🌀', 'folding' => '👕', 'ready' => '✅', 'claimed' => '🤝'];
    $h = '<ol class="stepper">';
    foreach ($steps as $i => $s) {
        $cls = $i < $cur ? 'done' : ($i === $cur ? 'current' : '');
        if ($status === 'claimed') $cls = 'done';
        $label = $s === 'ready' ? t('Ready') : status_label($s);
        $h .= '<li class="' . $cls . '"><span class="dot">' . ($cls === 'done' ? '✓' : $icons[$s]) . '</span><small>' . e($label) . '</small></li>';
    }
    return $h . '</ol>';
}

function empty_state(string $title, string $text = ''): string
{
    return '<div class="empty"><div class="empty-ico">🫧</div><b>' . e(t($title)) . '</b>' . ($text ? '<p class="muted">' . e(t($text)) . '</p>' : '') . '</div>';
}

/** Big icon button used on the counter screen (easy for everyone). */
function tile(string $icon, string $label, string $href, string $key = '', string $class = ''): string
{
    return '<a class="tile ' . e($class) . '" href="' . e(url($href)) . '"' . '><span class="tile-ico">' . $icon .
        '</span><b>' . e(t($label)) . '</b>' . ($key ? '<kbd>Alt+' . strtoupper(e($key)) . '</kbd>' : '') . '</a>';
}
