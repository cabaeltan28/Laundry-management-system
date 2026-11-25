<?php
/**
 * Switch the screen language (Tagalog / English). Saved per user.
 */
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $lang = post('lang') === 'tl' ? 'tl' : 'en';
    $_SESSION['lang'] = $lang;
    if ($uid = user_id()) {
        q('UPDATE users SET lang = ? WHERE id = ?', [$lang, $uid]);
    }
    $back = post('back');
    // Only allow going back to a page inside this system
    $base = base_url() . '/';
    if ($back !== '' && strpos($back, $base) === 0 && !preg_match('#//|\\\\#', substr($back, strlen($base)))) {
        header('Location: ' . $back);
        exit;
    }
}
redirect('index.php');
