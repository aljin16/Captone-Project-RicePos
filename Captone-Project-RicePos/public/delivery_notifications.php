<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_login();
require_delivery_staff();
// Notifications page removed; redirect to Delivery Dashboard for staff
header('Location: delivery_dashboard.php');
exit;
?>

