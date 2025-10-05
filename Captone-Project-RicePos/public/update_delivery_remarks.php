<?php
// Delivery role start
include '../includes/auth.php';
include_once '../classes/Database.php';
include_once '../classes/Delivery.php';

if ($_SESSION['role'] !== 'delivery') {
    header("Location: /ricepos/public/index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    if ($deliveryId > 0) {
        // verify ownership
        $chk = $db->prepare('SELECT 1 FROM delivery_orders WHERE id = ? AND assigned_to = ?');
        $chk->execute([$deliveryId, $_SESSION['user_id']]);
        if ($chk->fetchColumn()) {
            $delivery = new Delivery($db);
            $delivery->id = $deliveryId;
            $delivery->notes = $notes;
            $delivery->assigned_to = $_SESSION['user_id'];
            $delivery->updateRemarks();
        }
    }
}

header("Location: delivery_management.php");
exit;
// Delivery role end
