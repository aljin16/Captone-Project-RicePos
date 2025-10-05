<?php
// Delivery role start
include '../includes/auth.php';
include_once '../classes/Database.php';
include_once '../classes/Delivery.php';

// Access control
if ($_SESSION['role'] !== 'delivery') {
    header('Location: /ricepos/public/index.php');
    exit;
}

include '../includes/header.php';

$database = Database::getInstance();
$db = $database->getConnection();
$delivery = new Delivery($db);
$delivery->delivery_person_id = $_SESSION['user_id'];
$stmt = $delivery->getDeliveryHistory();
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container">
    <h2>Delivery History</h2>
    <table>
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$deliveries): ?>
                <tr>
                    <td colspan="3">No completed deliveries yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($deliveries as $d): ?>
                <tr>
                    <td><?php echo $d['sale_id']; ?></td>
                    <td><?php echo htmlspecialchars($d['status']); ?></td>
                    <td><?php echo htmlspecialchars($d['notes'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
