<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/StockMovement.php';

$productObj = new Product();
$stockMove = new StockMovement();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_stock_out'])) {
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $qty = isset($_POST['quantity_sack']) ? (float)$_POST['quantity_sack'] : 0;
$date = trim($_POST['movement_date'] ?? '');
    // Normalize datetime-local to DATETIME
    $dbDate = null;
    if ($date !== '') {
        $dt = date_create(str_replace('T', ' ', $date));
        $dbDate = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    try {
        if ($productId <= 0) { throw new RuntimeException('Please select a product.'); }
        if ($qty <= 0) { throw new RuntimeException('Quantity must be greater than zero.'); }
        $stockMove->recordStockOut($productId, $qty, $dbDate ?: null, $reason !== '' ? $reason : null, $notes !== '' ? $notes : null);
        $message = 'Stock-Out recorded successfully!';
        header('Location: stock_out.php?success=1');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$products = $productObj->getAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock-Out - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-label{ font-weight:700; color:#0f172a; margin-bottom:.35rem; display:block; position:static; }
        .card .form-control, .card .form-select{ height: 44px; line-height:1.25; }
        @media (max-width: 768px){ .card .row.g-3 > [class^='col-']{ flex: 1 1 100%; max-width: 100%; } }
    </style>
</head>
<body>
<?php $activePage = 'stock_out.php'; $pageTitle = 'Stock-Out'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
<main class="main-content">
    <div class="container">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Record Stock-Out</h5>
                <form method="post" autocomplete="off" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="product_id">Product</label>
                        <select class="form-select" id="product_id" name="product_id" required>
                            <option value="">Select product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo '#'.(int)$p['id'].' - '.htmlspecialchars($p['name']); ?> (Stock: <?php echo (float)$p['stock_sack']; ?> sacks)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="quantity_sack">Quantity (Sacks)</label>
                        <input type="number" step="1" min="1" inputmode="numeric" class="form-control" id="quantity_sack" name="quantity_sack" placeholder="0" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="movement_date">Date</label>
                        <input type="datetime-local" class="form-control" id="movement_date" name="movement_date" value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="reason">Reason</label>
                        <select class="form-select" id="reason" name="reason" required>
                            <option value="" disabled selected>Select reason</option>
                            <option value="spoilage">Spoilage</option>
                            <option value="damaged">Damaged bag</option>
                            <option value="promo">Promo/Sample</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="notes">Notes</label>
                        <input type="text" class="form-control" id="notes" name="notes" placeholder="Additional details">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-danger" name="record_stock_out" value="1"><i class='bx bx-upload'></i> Record Stock-Out</button>
                        <a class="btn btn-secondary" href="inventory.php">Back to Inventory</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>
<?php if (isset($_GET['success'])): ?>
<script>Swal.fire({icon:'success',title:'Success',text:'Stock-Out recorded successfully!'});</script>
<?php endif; ?>
<?php if ($error): ?>
<script>Swal.fire({icon:'error',title:'Error',text:<?php echo json_encode($error); ?>});</script>
<?php endif; ?>
<script src="assets/js/main.js"></script>
</body>
</html>


