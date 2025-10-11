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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Delivery History - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/mobile-delivery.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
    .container{ max-width:1200px; margin:0 auto; padding:1.5rem; }
    .container h2{ margin:0 0 1.5rem 0; color:#111827; font-size:1.5rem; }
    .table-wrapper{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); overflow-x:auto; }
    table{ width:100%; border-collapse:collapse; min-width:600px; }
    table thead th{ background:#f9fafb; color:#374151; font-weight:700; text-align:left; padding:0.9rem 1rem; border-bottom:2px solid #e5e7eb; }
    table tbody td{ padding:0.85rem 1rem; border-bottom:1px solid #f3f4f6; color:#111827; }
    table tbody tr:hover{ background:#f9fafb; }
    table tbody tr:last-child td{ border-bottom:none; }
    .badge{ display:inline-block; padding:0.3rem 0.7rem; border-radius:6px; font-size:0.8rem; font-weight:600; }
    .badge.pending{ background:#fef3c7; color:#92400e; }
    .badge.in_transit{ background:#dbeafe; color:#1e40af; }
    .badge.delivered{ background:#dcfce7; color:#166534; }
    .badge.failed{ background:#fee2e2; color:#991b1b; }
    .empty-state{ text-align:center; padding:3rem 2rem; color:#9ca3af; }
    
    /* Mobile Card View */
    @media(max-width:768px){
        .container{ padding:1rem 0.8rem; }
        .container h2{ font-size:1.3rem; margin-bottom:1rem; }
        .table-wrapper{ padding:0.5rem; border-radius:12px; }
        
        /* Hide table, show cards */
        table{ display:none; }
        .mobile-cards{ display:block; }
        .delivery-card{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem; margin-bottom:0.8rem; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
        .delivery-card:last-child{ margin-bottom:0; }
        .card-row{ display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.6rem; padding-bottom:0.6rem; border-bottom:1px solid #f3f4f6; }
        .card-row:last-child{ margin-bottom:0; padding-bottom:0; border-bottom:none; }
        .card-label{ font-size:0.75rem; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
        .card-value{ font-size:0.95rem; color:#111827; font-weight:600; margin-top:0.2rem; }
        .badge{ font-size:0.75rem; padding:0.25rem 0.6rem; }
    }
    
    @media(min-width:769px){
        .mobile-cards{ display:none; }
    }
    
    @media(max-width:480px){
        .container{ padding:0.8rem 0.6rem; }
        .container h2{ font-size:1.2rem; }
        .delivery-card{ padding:0.8rem; }
        .card-row{ flex-direction:column; gap:0.3rem; }
        .card-value{ font-size:0.9rem; }
    }
    </style>
</head>
<body>
<div class="container">
    <h2>Delivery History</h2>
    <div class="table-wrapper">
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
                        <td colspan="3" class="empty-state">No completed deliveries yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($deliveries as $d): 
                    $badgeClass = $d['status']==='pending'?'pending':($d['status']==='in_transit'?'in_transit':($d['status']==='delivered'?'delivered':'failed'));
                ?>
                    <tr>
                        <td><strong><?php echo $d['sale_id']; ?></strong></td>
                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($d['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($d['notes'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Mobile Card View -->
        <div class="mobile-cards">
            <?php if (!$deliveries): ?>
                <div class="empty-state">
                    <i class='bx bx-package' style="font-size:3rem; margin-bottom:0.5rem;"></i>
                    <p>No completed deliveries yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($deliveries as $d): 
                    $badgeClass = $d['status']==='pending'?'pending':($d['status']==='in_transit'?'in_transit':($d['status']==='delivered'?'delivered':'failed'));
                ?>
                    <div class="delivery-card">
                        <div class="card-row">
                            <div>
                                <div class="card-label">Order ID</div>
                                <div class="card-value">#<?php echo $d['sale_id']; ?></div>
                            </div>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($d['status']); ?></span>
                        </div>
                        <?php if (!empty($d['notes'])): ?>
                        <div class="card-row">
                            <div style="width:100%;">
                                <div class="card-label">Notes</div>
                                <div class="card-value"><?php echo htmlspecialchars($d['notes']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
