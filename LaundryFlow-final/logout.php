<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    if ($uid = user_id()) {
        audit('logout', 'user', $uid, null, null, null, null, null, $uid);
    }
    logout_user();
    session_start();
    session_regenerate_id(true);
    flash('success', 'You have been logged out.');
}
redirect('login.php');
