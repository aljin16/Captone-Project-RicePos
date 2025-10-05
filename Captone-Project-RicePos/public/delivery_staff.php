<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_login();
require_delivery_staff();

$pdo = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'] ?? 0;

// Ensure required workflow columns exist (compatible with MySQL/MariaDB without IF NOT EXISTS)
try { $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN picked_up_at DATETIME NULL"); } catch (Throwable $e) { /* ignore if exists */ }
try { $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN delivered_at DATETIME NULL"); } catch (Throwable $e) { /* ignore if exists */ }
try { $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN failed_reason VARCHAR(255) NULL"); } catch (Throwable $e) { /* ignore if exists */ }
try { $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN updated_at DATETIME NULL"); } catch (Throwable $e) { /* ignore if exists */ }

$message = '';
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1');
$ajaxResponse = ['success'=>false, 'message'=>'No action'];

// Status workflow validation
function canTransition($currentStatus, $newStatus, $isAssignedToMe) {
    if (!$isAssignedToMe && !in_array($newStatus, ['pending'])) {
        return ['allowed' => false, 'reason' => 'Not assigned to you'];
    }
    
    $validTransitions = [
        'pending' => ['picked_up'], // Can skip assign if already assigned
        'picked_up' => ['in_transit'],
        'in_transit' => ['delivered', 'failed'],
        'delivered' => [], // Final state
        'failed' => [], // Final state
        'cancelled' => [] // Final state
    ];
    
    if (!isset($validTransitions[$currentStatus])) {
        return ['allowed' => false, 'reason' => 'Invalid current status'];
    }
    
    if (!in_array($newStatus, $validTransitions[$currentStatus])) {
        return ['allowed' => false, 'reason' => 'Invalid status transition'];
    }
    
    return ['allowed' => true];
}

// Handle status updates with workflow validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = (int)$_POST['id'];
    $newStatus = $_POST['status'];
    $failedReason = isset($_POST['failed_reason']) ? trim($_POST['failed_reason']) : null;
    
    // Fetch current order
    $stmt = $pdo->prepare("SELECT status, assigned_to FROM delivery_orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        $message = 'Order not found.';
        $ajaxResponse = ['success'=>false, 'message'=>$message];
    } else {
        $isMine = ((int)$order['assigned_to'] === (int)$uid);
        $validation = canTransition($order['status'], $newStatus, $isMine);
        
        if (!$validation['allowed']) {
            $message = $validation['reason'];
            $ajaxResponse = ['success'=>false, 'message'=>$message];
        } else {
            // Validate failed status requires reason
            if ($newStatus === 'failed' && (!$failedReason || strlen($failedReason) < 5)) {
                $message = 'Failed status requires a reason (min 5 characters).';
                $ajaxResponse = ['success'=>false, 'message'=>$message];
            } else {
                // Build update query with timestamps
                $updateFields = ['status = ?', 'updated_at = NOW()'];
                $params = [$newStatus];
                
                if ($newStatus === 'picked_up') {
                    // Record pickup time if not already set
                    $updateFields[] = 'picked_up_at = CASE WHEN picked_up_at IS NULL THEN NOW() ELSE picked_up_at END';
                } elseif ($newStatus === 'delivered') {
                    $updateFields[] = 'delivered_at = NOW()';
                } elseif ($newStatus === 'failed' && $failedReason) {
                    $updateFields[] = 'failed_reason = ?';
                    $params[] = $failedReason;
                }
                
                $params[] = $id;
                $sql = "UPDATE delivery_orders SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $upd = $pdo->prepare($sql);
                
                if ($upd->execute($params)) {
                    $message = 'Status updated successfully.';
                    $ajaxResponse = ['success'=>true, 'message'=>$message, 'id'=>$id, 'status'=>$newStatus];
                } else {
                    $message = 'Update failed.';
                    $ajaxResponse = ['success'=>false, 'message'=>$message];
                }
            }
        }
    }
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode($ajaxResponse); exit; }
}

// Claim delivery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_id'])) {
    $id = (int)$_POST['claim_id'];
    $chk = $pdo->prepare('SELECT assigned_to FROM delivery_orders WHERE id = ?');
    $chk->execute([$id]);
    $cur = $chk->fetchColumn();
    if (!$cur) {
        $upd = $pdo->prepare('UPDATE delivery_orders SET assigned_to = ?, updated_at = NOW() WHERE id = ?');
        if ($upd->execute([$uid, $id])) {
            $message = 'Delivery claimed successfully.';
            $ajaxResponse = ['success'=>true, 'message'=>$message, 'id'=>$id];
        } else {
            $message = 'Claim failed.';
            $ajaxResponse = ['success'=>false, 'message'=>$message];
        }
    } else {
        $message = 'Already assigned.';
        $ajaxResponse = ['success'=>false, 'message'=>$message];
    }
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode($ajaxResponse); exit; }
}

// Filters
$view = isset($_GET['view']) ? (($_GET['view'] === 'mine') ? 'mine' : 'all') : 'mine';
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['','pending','picked_up','in_transit','delivered','failed','cancelled'], true)
    ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');

// Build query
$where = [];
$params = [];
if ($view === 'mine') { $where[] = 'd.assigned_to = ?'; $params[] = $uid; }
if ($statusFilter !== '') { $where[] = 'd.status = ?'; $params[] = $statusFilter; }
if ($q !== '') { $where[] = '(d.customer_name LIKE ? OR d.customer_address LIKE ? OR s.transaction_id LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "SELECT d.id, d.customer_name, d.customer_phone, d.customer_address, d.notes, d.assigned_to, d.status, d.created_at, d.updated_at, d.picked_up_at, d.delivered_at, d.failed_reason, s.transaction_id, s.total_amount
        FROM delivery_orders d
        JOIN sales s ON s.id = d.sale_id
        $whereSql
        ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Deliveries - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root{ --brand:#2563eb; --success:#16a34a; --warn:#f59e0b; --danger:#dc2626; --gray:#6b7280; }
    body{ background:#f8fafc; }
    .toolbar{ display:flex; gap:0.6rem; align-items:center; margin:1rem 0; flex-wrap:wrap; background:#fff; padding:1rem; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
    .toolbar select, .toolbar input{ padding:0.5rem 0.7rem; border:1px solid #e5e7eb; border-radius:8px; font-size:0.95rem; }
    .toolbar input{ flex:1; min-width:200px; }
    .toolbar .btn{ padding:0.5rem 1.2rem; }
    .view-toggle{ display:flex; gap:0.3rem; background:#f3f4f6; border-radius:10px; padding:0.25rem; }
    .view-toggle button{ padding:0.4rem 1rem; border:none; background:transparent; border-radius:8px; font-weight:600; cursor:pointer; transition:all 0.2s ease; }
    .view-toggle button.active{ background:#fff; color:var(--brand); box-shadow:0 2px 4px rgba(0,0,0,0.1); }
    .delivery-grid{ display:grid; gap:1rem; }
    .delivery-card{ background:#fff; border-radius:16px; padding:1.2rem; box-shadow:0 4px 12px rgba(0,0,0,0.06); transition:all 0.3s ease; border-left:4px solid #e5e7eb; position:relative; overflow:hidden; }
    .delivery-card:hover{ box-shadow:0 8px 24px rgba(0,0,0,0.12); transform:translateY(-2px); }
    .delivery-card.mine{ border-left-color:var(--brand); background:linear-gradient(135deg, #f0f9ff 0%, #fff 100%); }
    .delivery-card.pending{ border-left-color:#f59e0b; }
    .delivery-card.picked_up{ border-left-color:#8b5cf6; }
    .delivery-card.in_transit{ border-left-color:#3b82f6; }
    .delivery-card.delivered{ border-left-color:#16a34a; }
    .delivery-card.failed{ border-left-color:#dc2626; }
    .card-header{ display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; }
    .card-id{ font-size:1.5rem; font-weight:800; color:#111827; }
    .card-badge{ padding:0.3rem 0.8rem; border-radius:999px; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
    .badge-pending{ background:#fef3c7; color:#92400e; }
    .badge-picked_up{ background:#f3e8ff; color:#6b21a8; }
    .badge-in_transit{ background:#dbeafe; color:#1e40af; }
    .badge-delivered{ background:#dcfce7; color:#166534; }
    .badge-failed{ background:#fee2e2; color:#991b1b; }
    .card-body{ display:grid; gap:0.8rem; }
    .info-row{ display:flex; align-items:flex-start; gap:0.8rem; }
    .info-icon{ width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
    .info-icon.customer{ background:linear-gradient(135deg,#fef3c7,#fde68a); color:#92400e; }
    .info-icon.phone{ background:linear-gradient(135deg,#dbeafe,#bfdbfe); color:#1e40af; }
    .info-icon.location{ background:linear-gradient(135deg,#fce7f3,#fbcfe8); color:#be185d; }
    .info-icon.money{ background:linear-gradient(135deg,#dcfce7,#bbf7d0); color:#166534; }
    .info-content{ flex:1; }
    .info-label{ font-size:0.8rem; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
    .info-value{ font-size:1rem; color:#111827; font-weight:600; margin-top:0.2rem; }
    .workflow-timeline{ display:flex; align-items:center; gap:0.5rem; margin:1rem 0; padding:1rem; background:#f9fafb; border-radius:12px; }
    .workflow-step{ flex:1; display:flex; flex-direction:column; align-items:center; gap:0.3rem; position:relative; }
    .workflow-step::after{ content:''; position:absolute; top:18px; left:50%; width:100%; height:2px; background:#e5e7eb; z-index:0; }
    .workflow-step:last-child::after{ display:none; }
    .workflow-dot{ width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; background:#e5e7eb; color:#9ca3af; position:relative; z-index:1; }
    .workflow-step.active .workflow-dot{ background:var(--brand); color:#fff; }
    .workflow-step.completed .workflow-dot{ background:var(--success); color:#fff; }
    .workflow-label{ font-size:0.7rem; color:#6b7280; font-weight:600; text-align:center; }
    .workflow-step.active .workflow-label{ color:var(--brand); }
    .card-actions{ display:flex; gap:0.6rem; margin-top:1rem; flex-wrap:wrap; }
    .btn{ padding:0.6rem 1.2rem; border-radius:10px; font-weight:600; cursor:pointer; transition:all 0.2s ease; border:none; font-size:0.9rem; display:inline-flex; align-items:center; gap:0.4rem; }
    .btn:hover{ transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.15); }
    .btn-primary{ background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; }
    .btn-success{ background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; }
    .btn-warning{ background:linear-gradient(135deg,#fb923c,#f59e0b); color:#fff; }
    .btn-danger{ background:linear-gradient(135deg,#f87171,#dc2626); color:#fff; }
    .btn-secondary{ background:#f3f4f6; color:#374151; }
    .btn:disabled{ opacity:0.5; cursor:not-allowed; }
    .me-badge{ display:inline-flex; align-items:center; gap:0.4rem; padding:0.3rem 0.8rem; background:linear-gradient(135deg,#dbeafe,#bfdbfe); color:#1e40af; border-radius:999px; font-size:0.8rem; font-weight:700; }
    .empty-state{ text-align:center; padding:4rem 2rem; color:#9ca3af; }
    .empty-state i{ font-size:4rem; margin-bottom:1rem; }
    @media(max-width:768px){ .card-actions{ flex-direction:column; } .toolbar{ flex-direction:column; align-items:stretch; } }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_staff.php'; $pageTitle = 'My Deliveries'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <?php if ($message && !$isAjax): ?><div class="alert" style="margin-bottom:1rem; padding:1rem; background:#fee2e2; color:#991b1b; border-radius:10px;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        
        <div class="toolbar">
            <div class="view-toggle">
                <button class="<?php echo $view==='mine'?'active':''; ?>" onclick="changeView('mine')">My Deliveries</button>
                <button class="<?php echo $view==='all'?'active':''; ?>" onclick="changeView('all')">All Available</button>
            </div>
            <form method="get" style="display:contents;">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                    <option value="picked_up" <?php echo $statusFilter==='picked_up'?'selected':''; ?>>Picked Up</option>
                    <option value="in_transit" <?php echo $statusFilter==='in_transit'?'selected':''; ?>>In Transit</option>
                    <option value="delivered" <?php echo $statusFilter==='delivered'?'selected':''; ?>>Delivered</option>
                    <option value="failed" <?php echo $statusFilter==='failed'?'selected':''; ?>>Failed</option>
                </select>
                <input type="text" name="q" placeholder="Search customer, address, or transaction..." value="<?php echo htmlspecialchars($q); ?>">
                <button class="btn btn-primary" type="submit"><i class='bx bx-search'></i> Search</button>
            </form>
        </div>

        <div class="delivery-grid">
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $d): 
                    $isMine = (int)($d['assigned_to'] ?? 0) === (int)$uid;
                    $statusClass = str_replace(['_', '-'], '', $d['status']);
                    $badgeClass = 'badge-' . $statusClass;
                    
                    // Workflow steps
                    $steps = [
                        'pending' => 'Pending',
                        'picked_up' => 'Picked Up',
                        'in_transit' => 'In Transit',
                        'delivered' => 'Delivered'
                    ];
                    $currentStepIndex = array_search($d['status'], array_keys($steps));
                    if ($currentStepIndex === false) $currentStepIndex = -1;
                ?>
                <div class="delivery-card <?php echo $isMine?'mine':''; ?> <?php echo $statusClass; ?>">
                    <div class="card-header">
                        <div>
                            <div class="card-id">#<?php echo $d['id']; ?></div>
                            <div style="font-size:0.85rem; color:#6b7280; margin-top:0.2rem;">TXN: <?php echo htmlspecialchars($d['transaction_id']); ?></div>
                        </div>
                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:0.5rem;">
                            <span class="card-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($d['status']); ?></span>
                            <?php if ($isMine): ?>
                            <span class="me-badge"><i class='bx bx-user'></i> Assigned to me</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($d['status'] !== 'failed'): ?>
                    <div class="workflow-timeline">
                        <?php foreach ($steps as $key => $label): 
                            $stepIndex = array_search($key, array_keys($steps));
                            $isActive = ($stepIndex === $currentStepIndex);
                            $isCompleted = ($stepIndex < $currentStepIndex);
                            $icon = $key==='pending'?'bx-time':($key==='picked_up'?'bx-package':($key==='in_transit'?'bx-car':'bx-check'));
                        ?>
                        <div class="workflow-step <?php echo $isActive?'active':($isCompleted?'completed':''); ?>">
                            <div class="workflow-dot"><i class='bx <?php echo $icon; ?>'></i></div>
                            <div class="workflow-label"><?php echo htmlspecialchars($label); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-icon customer"><i class='bx bx-user'></i></div>
                            <div class="info-content">
                                <div class="info-label">Customer</div>
                                <div class="info-value"><?php echo htmlspecialchars($d['customer_name']); ?></div>
                            </div>
                        </div>

                        <?php if ($d['customer_phone']): ?>
                        <div class="info-row">
                            <div class="info-icon phone"><i class='bx bx-phone'></i></div>
                            <div class="info-content">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><a href="tel:<?php echo htmlspecialchars($d['customer_phone']); ?>" style="color:var(--brand);"><?php echo htmlspecialchars($d['customer_phone']); ?></a></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <div class="info-icon location"><i class='bx bx-map'></i></div>
                            <div class="info-content">
                                <div class="info-label">Delivery Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($d['customer_address']); ?></div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon money"><i class='bx bx-money'></i></div>
                            <div class="info-content">
                                <div class="info-label">Amount • <?php echo htmlspecialchars($d['payment_method']??'Cash'); ?></div>
                                <div class="info-value">₱<?php echo number_format((float)$d['total_amount'], 2); ?></div>
                            </div>
                        </div>

                        <?php if ($d['notes']): ?>
                        <div class="info-row">
                            <div class="info-icon" style="background:linear-gradient(135deg,#e0e7ff,#c7d2fe); color:#4338ca;"><i class='bx bx-note'></i></div>
                            <div class="info-content">
                                <div class="info-label">Notes</div>
                                <div class="info-value"><?php echo htmlspecialchars($d['notes']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($d['picked_up_at']): ?>
                        <div style="font-size:0.85rem; color:#6b7280; margin-top:0.5rem;">
                            <i class='bx bx-time'></i> Picked up: <?php echo htmlspecialchars($d['picked_up_at']); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($d['delivered_at']): ?>
                        <div style="font-size:0.85rem; color:#16a34a; margin-top:0.5rem;">
                            <i class='bx bx-check-circle'></i> Delivered: <?php echo htmlspecialchars($d['delivered_at']); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($d['status'] === 'failed' && $d['failed_reason']): ?>
                        <div style="padding:0.8rem; background:#fee2e2; border-radius:10px; margin-top:0.5rem;">
                            <div style="font-size:0.85rem; color:#991b1b; font-weight:600;">Failed Reason:</div>
                            <div style="color:#991b1b; margin-top:0.3rem;"><?php echo htmlspecialchars($d['failed_reason']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-actions">
                        <?php if (!$isMine && !$d['assigned_to']): ?>
                            <button class="btn btn-primary" onclick="claimDelivery(<?php echo $d['id']; ?>)">
                                <i class='bx bx-hand'></i> Claim This Delivery
                            </button>
                        <?php elseif ($isMine): ?>
                            <?php if ($d['status'] === 'pending'): ?>
                                <button class="btn btn-warning" onclick="updateStatus(<?php echo $d['id']; ?>, 'picked_up', 'Pick Up')">
                                    <i class='bx bx-package'></i> Mark as Picked Up
                                </button>
                            <?php elseif ($d['status'] === 'picked_up'): ?>
                                <button class="btn btn-primary" onclick="updateStatus(<?php echo $d['id']; ?>, 'in_transit', 'In Transit')">
                                    <i class='bx bx-car'></i> Start Delivery
                                </button>
                            <?php elseif ($d['status'] === 'in_transit'): ?>
                                <button class="btn btn-success" onclick="updateStatus(<?php echo $d['id']; ?>, 'delivered', 'Delivered')">
                                    <i class='bx bx-check-circle'></i> Mark as Delivered
                                </button>
                                <button class="btn btn-danger" onclick="markAsFailed(<?php echo $d['id']; ?>)">
                                    <i class='bx bx-x-circle'></i> Report Failed
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="receipt.php?txn=<?php echo urlencode($d['transaction_id']); ?>" target="_blank" class="btn btn-secondary">
                            <i class='bx bx-receipt'></i> View Receipt
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-package'></i>
                    <h3>No Deliveries Found</h3>
                    <p><?php echo $view==='mine' ? 'You don\'t have any deliveries assigned yet' : 'No deliveries available to claim'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script src="assets/js/main.js"></script>
    <script>
    function changeView(view) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', view);
        window.location.href = url.toString();
    }

    function claimDelivery(id) {
        Swal.fire({
            title: 'Claim this delivery?',
            text: 'This delivery will be assigned to you',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, claim it',
            confirmButtonColor: '#2563eb'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                const fd = new FormData();
                fd.append('claim_id', id);
                fd.append('ajax', '1');
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(j => {
                        if (j && j.success) {
                            Swal.fire({ icon: 'success', title: 'Claimed!', timer: 1000, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1100);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Failed', text: (j && j.message) || 'Claim failed' });
                        }
                    })
                    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error' }));
            }
        });
    }

    function updateStatus(id, status, label) {
        Swal.fire({
            title: 'Update status to ' + label + '?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, update',
            confirmButtonColor: '#16a34a'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.showLoading();
                const fd = new FormData();
                fd.append('id', id);
                fd.append('status', status);
                fd.append('ajax', '1');
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(j => {
                        if (j && j.success) {
                            Swal.fire({ icon: 'success', title: 'Updated!', text: j.message, timer: 1000, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1100);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Failed', text: (j && j.message) || 'Update failed' });
                        }
                    })
                    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error' }));
            }
        });
    }

    function markAsFailed(id) {
        Swal.fire({
            title: 'Report Failed Delivery',
            html: '<textarea id="failReason" class="swal2-input" placeholder="Enter reason for failure (min 5 characters)" rows="3" style="height:auto;"></textarea>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Submit',
            confirmButtonColor: '#dc2626',
            preConfirm: () => {
                const reason = document.getElementById('failReason').value.trim();
                if (!reason || reason.length < 5) {
                    Swal.showValidationMessage('Please enter a reason (min 5 characters)');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                Swal.showLoading();
                const fd = new FormData();
                fd.append('id', id);
                fd.append('status', 'failed');
                fd.append('failed_reason', result.value);
                fd.append('ajax', '1');
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(j => {
                        if (j && j.success) {
                            Swal.fire({ icon: 'success', title: 'Reported', text: 'Delivery marked as failed', timer: 1000, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1100);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Failed', text: (j && j.message) || 'Update failed' });
                        }
                    })
                    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error' }));
            }
        });
    }
    </script>
</body>
</html>
