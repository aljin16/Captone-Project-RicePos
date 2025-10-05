<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_login();
require_delivery_staff();

$pdo = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'] ?? 0;

// Mark as read action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notifId = (int)$_POST['notif_id'];
    // For now, we'll just redirect. In a full implementation, you'd update a notifications table
    header('Location: delivery_notifications.php');
    exit;
}

// Fetch recent activity/notifications for this delivery staff
// For now, we'll show recent deliveries assigned to them as "notifications"
$stmt = $pdo->prepare("SELECT d.id, d.customer_name, d.customer_address, d.status, d.created_at, d.updated_at, s.transaction_id, s.total_amount
                       FROM delivery_orders d
                       JOIN sales s ON s.id = d.sale_id
                       WHERE d.assigned_to = ?
                       ORDER BY d.created_at DESC
                       LIMIT 20");
$stmt->execute([$uid]);
$notifications = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    .notif-list{ display:grid; gap:0.8rem; }
    .notif-item{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem; box-shadow:0 2px 8px rgba(0,0,0,0.04); transition:all 0.2s ease; }
    .notif-item:hover{ box-shadow:0 4px 16px rgba(0,0,0,0.08); }
    .notif-item.unread{ border-left:4px solid #2563eb; background:#f0f9ff; }
    .notif-header{ display:flex; align-items:center; gap:0.8rem; margin-bottom:0.5rem; }
    .notif-icon{ width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; }
    .notif-icon.new{ background:linear-gradient(135deg,#3b82f6,#2563eb); }
    .notif-icon.update{ background:linear-gradient(135deg,#fb923c,#f59e0b); }
    .notif-icon.complete{ background:linear-gradient(135deg,#22c55e,#16a34a); }
    .notif-title{ flex:1; font-weight:700; color:#111827; }
    .notif-time{ font-size:0.85rem; color:#6b7280; }
    .notif-body{ color:#374151; font-size:0.95rem; line-height:1.5; }
    .badge{ display:inline-block; padding:0.2rem 0.5rem; border-radius:6px; font-size:0.75rem; font-weight:600; }
    .badge.pending{ background:#fee2e2; color:#991b1b; }
    .badge.picked{ background:#fef3c7; color:#92400e; }
    .badge.transit{ background:#dbeafe; color:#1e40af; }
    .badge.delivered{ background:#dcfce7; color:#166534; }
    .badge.failed{ background:#fef2f2; color:#991b1b; }
    .empty-state{ text-align:center; padding:3rem; color:#9ca3af; }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_notifications.php'; $pageTitle = 'Notifications'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <div class="notif-list">
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $n): 
                    $badgeClass = $n['status']==='pending'?'pending':($n['status']==='picked_up'?'picked':($n['status']==='in_transit'?'transit':($n['status']==='delivered'?'delivered':'failed')));
                    $iconClass = $n['status']==='delivered'?'complete':($n['status']==='pending'?'new':'update');
                    $isNew = (strtotime($n['created_at']) > (time() - 3600)); // New if created in last hour
                ?>
                <div class="notif-item <?php echo $isNew ? 'unread' : ''; ?>">
                    <div class="notif-header">
                        <div class="notif-icon <?php echo $iconClass; ?>">
                            <i class='bx <?php echo $n['status']==='delivered'?'bx-check-circle':($n['status']==='pending'?'bx-bell':'bx-package'); ?>'></i>
                        </div>
                        <div class="notif-title">Delivery #<?php echo $n['id']; ?> - <?php echo htmlspecialchars($n['customer_name']); ?></div>
                        <div class="notif-time"><?php echo htmlspecialchars($n['created_at']); ?></div>
                    </div>
                    <div class="notif-body">
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($n['customer_address']); ?></p>
                        <p><strong>Transaction:</strong> <?php echo htmlspecialchars($n['transaction_id']); ?> • <strong>Amount:</strong> ₱<?php echo number_format((float)$n['total_amount'], 0); ?></p>
                        <p><strong>Status:</strong> <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($n['status']); ?></span></p>
                        <?php if ($n['updated_at']): ?>
                        <p style="font-size:0.85rem; color:#6b7280;">Last updated: <?php echo htmlspecialchars($n['updated_at']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-bell-off' style="font-size:4rem;"></i>
                    <h3>No Notifications</h3>
                    <p>You don't have any notifications yet</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script src="assets/js/main.js"></script>
</body>
</html>

