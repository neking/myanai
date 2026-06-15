<?php
session_start();
$_SESSION['admin'] = true;
$_SESSION['demo_mode'] = true;
$_SESSION['login_time'] = time();
// Use tenant 1 (NoodleHaus Main - has real orders/data)
unset($_SESSION['tenant_id']);
header('Location: admin.php');
exit;
