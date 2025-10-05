<?php
// Delivery role start
include '../includes/auth.php';
require_login();
include_once '../classes/Database.php';
include_once '../classes/Delivery.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $delivery = new Delivery($db);
    $delivery->id = (int)($_POST['delivery_order_id'] ?? 0);
    $delivery->assigned_to = (int)($_POST['delivery_person_id'] ?? 0);

    if ($delivery->assignDeliveryPerson()) {
        // Redirect back to the recent sales page with a success message
        $_SESSION['flash_message'] = 'Delivery assigned successfully!';
    } else {
        // Handle error, maybe set an error message
        $_SESSION['flash_message'] = 'Failed to assign delivery.';
    }
    header("Location: recent_sales.php");
    exit;
}
// Delivery role end
