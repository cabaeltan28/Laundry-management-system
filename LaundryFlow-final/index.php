<?php
require __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if ($u) {
    redirect(home_for($u['role']));
}
redirect('login.php');
