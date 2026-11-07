<?php
require __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect(base_url('login.php'));
}
redirect($user['role'] === 'admin' ? base_url('admin/dashboard.php') : base_url('judge/dashboard.php'));
