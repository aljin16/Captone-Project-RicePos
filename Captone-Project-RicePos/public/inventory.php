<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Product.php';
require_login();
$productObj = new Product();
$message = '';
$editProduct = null;
$is_admin = is_admin();

// Handle add/edit/delete (admin only)
        if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        $name = $_POST['name'] ?? '';
        // KG options removed: force KG values to 0 and require sack price
        $price_kg = 0;
        $price_sack = $_POST['price_per_sack'] !== '' ? intval($_POST['price_per_sack']) : null;
        $stock_kg = 0;
        $stock_sack = intval($_POST['stock_sack'] ?? 0);
        $category = $_POST['category'] ?? '';
        $allowedCategories = ['25kg', '50kg'];
        $low_stock = intval($_POST['low_stock_threshold'] ?? 10);
        $profit_per_sack = isset($_POST['profit_per_sack']) && $_POST['profit_per_sack'] !== '' ? intval($_POST['profit_per_sack']) : null;
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image = 'assets/img/' . uniqid('prod_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $image);
        }
        if ($name && $price_sack !== null && $price_sack > 0 && in_array($category, $allowedCategories, true)) {
            $productObj->add($name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock, $image, $profit_per_sack);
            // Auto-hide if stock is zero
            if ($stock_sack <= 0) { $productObj->setActive($this->pdo->lastInsertId() ?? 0, 0); }
            $message = 'Product added!';
            header('Location: inventory.php');
            exit;
        } else {
            $message = 'Name, price per sack, and a valid category (25kg or 50kg) are required.';
        }
    } elseif (isset($_POST['edit_product'])) {
        $id = intval($_POST['id']);
        $name = $_POST['name'] ?? '';
        // KG options removed: keep KG fields at 0 and edit only sack values
        $price_kg = 0;
        $price_sack = $_POST['price_per_sack'] !== '' ? intval($_POST['price_per_sack']) : null;
        $stock_kg = 0;
        $stock_sack = intval($_POST['stock_sack'] ?? 0);
        $category = $_POST['category'] ?? '';
        $allowedCategories = ['25kg', '50kg'];
        $low_stock = intval($_POST['low_stock_threshold'] ?? 10);
        $profit_per_sack = isset($_POST['profit_per_sack']) && $_POST['profit_per_sack'] !== '' ? intval($_POST['profit_per_sack']) : null;
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image = 'assets/img/' . uniqid('prod_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $image);
        }
        if ($id && $name && $price_sack !== null && $price_sack > 0 && in_array($category, $allowedCategories, true)) {
            $productObj->update($id, $name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock, $image, $profit_per_sack);
            if ($stock_sack <= 0) { $productObj->setActive($id, 0); }
            $message = 'Product updated!';
            header('Location: inventory.php');
            exit;
        } else {
            $message = 'Name, price per sack, and a valid category (25kg or 50kg) are required.';
        }
    } elseif (isset($_POST['delete_product'])) {
        $id = intval($_POST['id']);
        $productObj->delete($id);
        $message = 'Product deleted!';
        header('Location: inventory.php');
        exit;
    }
}
if ($is_admin && isset($_GET['edit'])) {
    $editProduct = $productObj->getById(intval($_GET['edit']));
}
$products = $productObj->getAll();

// Handle toggle visibility action
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $pid = (int)($_POST['id'] ?? 0);
    $p = $productObj->getById($pid);
    if ($p) {
        // If stock is zero, keep hidden; otherwise toggle
        if ((int)$p['stock_sack'] <= 0) {
            $productObj->setActive($pid, 0);
            $message = 'Product is out of stock and has been hidden.';
        } else {
            $productObj->setActive($pid, (int)!$p['is_active']);
            $message = $p['is_active'] ? 'Product hidden.' : 'Product set to active.';
        }
        header('Location: inventory.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root{ --brand:#2d6cdf; --brand-600:#1e4fa3; --ink:#111827; --muted:#6b7280; --card:#ffffff; --bg:#f7f9fc; --line:#e5e7eb; }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; }
    body { display: block; min-height: 100vh; margin: 0; background: var(--bg); color: var(--ink); overflow-x: hidden; }
    /* Rely on global .main-content styles in assets/css/style.css for consistent layout */
    .main-content { background: var(--bg); min-height: 100vh; overflow-x: hidden; }
    .main-content h2{ margin:0 0 1rem; font-weight:800; letter-spacing:0.2px; color: var(--ink); }
    @media (max-width: 700px) { .main-content { padding: 1.2rem 0.5rem 1.2rem 0.5rem; } }
    .product-card { box-shadow: 0 8px 24px rgba(45,108,223,0.12), 0 1px 0 rgba(255,255,255,0.5) !important; border-radius: 16px !important; overflow: hidden !important; background: var(--card) !important; border:1px solid var(--line) !important; transition: box-shadow 0.25s cubic-bezier(.4,2,.6,1), transform 0.18s cubic-bezier(.4,2,.6,1) !important; }
    .product-card:hover { box-shadow: 0 16px 40px rgba(44,108,223,0.22), 0 6px 20px rgba(44,108,223,0.13) !important; transform: translateY(-6px) scale(1.025); }
    .product-img { width: 120px !important; height: 120px !important; object-fit: cover !important; background: #f8fafc !important; border-radius: 12px !important; display: block !important; margin-left: auto !important; margin-right: auto !important; box-shadow: 0 4px 16px rgba(44,108,223,0.12) !important; }
    .stock-indicator { font-size: 0.98em; }
    .stock-indicator.green { color: #22c55e; }
    .stock-indicator.red { color: #ef4444; }
    .stock-indicator.orange { color: #f59e42; }
    .card-actions { display: flex; gap: 0.5em; }
    .form-control{ border:1px solid #dbeafe; border-radius:10px; }
    .btn{ border-radius:10px; font-weight:600; letter-spacing:0.2px; }
    .btn-primary, .btn-success{ background: linear-gradient(135deg,var(--brand),var(--brand-600)); border-color: var(--brand-600); }
    .btn-primary:hover, .btn-success:hover{ background: linear-gradient(135deg,var(--brand-600),#1e40af); }
    .btn-secondary{ background:#eef2ff; color:#1e40af; border-color:#c7d2fe; }
    /* Visibility UI */
    .status-pill { padding: 0.25rem 0.6rem; border-radius: 999px; font-weight: 600; font-size: 0.8rem; border:1px solid transparent; }
    .status-pill.active { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .status-pill.hidden { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
    .btn-toggle { padding: 0.35rem 0.7rem; background: linear-gradient(135deg,#2d6cdf,#1e4fa3); color:#fff; border:1px solid #1e40af; border-radius:8px; font-weight:600; }
    .btn-toggle:hover { background: linear-gradient(135deg,#1e4fa3,#1e40af); color:#fff; }
    </style>
</head>
<body>
    <?php $activePage = 'inventory.php'; $pageTitle = 'Inventory Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        
        <?php if ($message): ?>
            <script>
                Swal.fire({
                    title: '<?php echo strpos($message, '!') !== false ? 'Success!' : 'Notice'; ?>',
                    text: '<?php echo htmlspecialchars($message); ?>',
                    icon: '<?php echo strpos($message, '!') !== false ? 'success' : 'info'; ?>',
                    confirmButtonColor: '#3085d6'
                });
            </script>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3"><?php echo $editProduct ? 'Edit Product' : 'Add Product'; ?></h5>
                <form method="post" class="row g-3" enctype="multipart/form-data" autocomplete="off" id="productForm">
                    <?php if ($editProduct): ?>
                        <input type="hidden" name="id" value="<?php echo $editProduct['id']; ?>">
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label for="name" class="form-label">Product Name</label>
                        <input id="name" type="text" name="name" class="form-control" placeholder="e.g. Special Rice" required value="<?php echo htmlspecialchars($editProduct['name'] ?? '', ENT_QUOTES); ?>">
                    </div>
                    <!-- Removed KG pricing -->
                    <div class="col-md-2">
                        <label for="price_per_sack" class="form-label">Price per Sack</label>
                        <input id="price_per_sack" type="number" step="1" min="0" name="price_per_sack" class="form-control" placeholder="e.g. 1800" value="<?php echo isset($editProduct['price_per_sack']) ? (int)$editProduct['price_per_sack'] : ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="profit_per_sack" class="form-label">Profit per Sack</label>
                        <input id="profit_per_sack" type="number" step="1" min="0" name="profit_per_sack" class="form-control" placeholder="e.g. 120" value="<?php echo isset($editProduct['profit_per_sack']) ? (int)$editProduct['profit_per_sack'] : ''; ?>">
                    </div>
                    <!-- Removed KG stock -->
                    <div class="col-md-1">
                        <label for="stock_sack" class="form-label">Stock (Sacks)</label>
                        <input id="stock_sack" type="number" step="1" min="0" name="stock_sack" class="form-control" placeholder="e.g. 50" value="<?php echo isset($editProduct['stock_sack']) ? (int)$editProduct['stock_sack'] : ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="category" class="form-label">Category</label>
                        <select id="category" name="category" class="form-control" required>
                            <option value="" disabled <?php echo empty($editProduct['category'] ?? '') ? 'selected' : ''; ?>>Select Category</option>
                            <option value="25kg" <?php echo (($editProduct['category'] ?? '')==='25kg') ? 'selected' : ''; ?>>25kg</option>
                            <option value="50kg" <?php echo (($editProduct['category'] ?? '')==='50kg') ? 'selected' : ''; ?>>50kg</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label for="low_stock_threshold" class="form-label">Low Stock</label>
                        <input id="low_stock_threshold" type="number" step="1" min="0" name="low_stock_threshold" class="form-control" placeholder="e.g. 10" aria-label="Low Stock" value="<?php echo isset($editProduct['low_stock_threshold']) ? (int)$editProduct['low_stock_threshold'] : 10; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="image" class="form-label">Image</label>
                        <input id="image" type="file" name="image" class="form-control" accept="image/*">
                    </div>
                    <div class="col-12">
                        <?php if ($editProduct): ?>
                            <button type="button" onclick="confirmEdit()" class="btn btn-primary"><i class='bx bx-save'></i> Update</button>
                            <a href="inventory.php" class="btn btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="button" onclick="confirmAdd()" class="btn btn-success"><i class='bx bx-plus'></i> Add Product</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="row g-4">
            <?php foreach ($products as $p):
                $img = !empty($p['image']) ? $p['image'] : 'assets/img/sack-placeholder.png';
                $stock = $p['stock_sack'];
                $stock_class = $stock > $p['low_stock_threshold'] ? 'green' : ($stock > 0 ? 'orange' : 'red');
            ?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="product-card p-3 h-100 d-flex flex-column justify-content-between">
                    <img src="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>" class="product-img mb-2" alt="Rice Sack">
                    <div class="fw-bold mb-1"><?php echo htmlspecialchars($p['name']); ?></div>
                    <div class="mb-1 text-muted">Sack Size: <?php echo htmlspecialchars($p['category']); ?></div>
                     <div class="mb-1">Price per Sack: <span class="fw-semibold"> ₱<?php echo number_format($p['price_per_sack'] ?? 0,0); ?></span></div>
                     <?php if ($is_admin): ?>
                     <div class="mb-1 text-muted">Profit per Sack: <span class="fw-semibold">₱<?php echo number_format($p['profit_per_sack'] ?? 0,0); ?></span></div>
                     <?php endif; ?>
                    <div class="stock-indicator <?php echo $stock_class; ?>">
                        <?php if ($stock > $p['low_stock_threshold']): ?>
                            <span style="color: #22c55e;">🟢 In stock: <?php echo $stock; ?> sacks</span>
                        <?php elseif ($stock > 0): ?>
                            <span style="color: #f59e42;">🟠 Low stock: <?php echo $stock; ?> sacks</span>
                        <?php else: ?>
                            <span style="color: #ef4444;">🔴 Out of stock</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 d-flex gap-2 align-items-center">
                        <?php $active = isset($p['is_active']) ? (int)$p['is_active'] : 1; ?>
                        <span class="status-pill <?php echo $active ? 'active' : 'hidden'; ?>"><?php echo $active ? 'Active' : 'Hidden'; ?></span>
                        <form method="post" class="toggle-form" data-active="<?php echo $active; ?>" data-stock="<?php echo (int)$p['stock_sack']; ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <input type="hidden" name="toggle_active" value="1">
                            <button type="submit" class="btn btn-toggle btn-sm"><?php echo $active ? 'Hide in POS' : 'Activate'; ?></button>
                        </form>
                        <?php if ($is_admin): ?>
                        <a href="inventory.php?edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary"><i class='bx bx-edit'></i> Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
    // Toggle Active/Hidden with SweetAlert flows
    document.querySelectorAll('.toggle-form').forEach(function(f){
        f.addEventListener('submit', function(e){
            e.preventDefault();
            const stock = parseInt(f.getAttribute('data-stock'),10) || 0;
            const active = parseInt(f.getAttribute('data-active'),10) || 0;
            if (stock <= 0 && active === 0) {
                Swal.fire({ icon:'info', title:'Out of stock', text:'This product is hidden because stock is zero. Update stock to activate.', confirmButtonColor:'#2d6cdf' });
                return;
            }
            Swal.fire({
                icon:'question',
                title: active? 'Hide product in POS?':'Activate product in POS?',
                text: active? 'Customers will not see this product in POS and Delivery until reactivated.' : 'This product will be visible in POS and Delivery.',
                showCancelButton:true,
                confirmButtonColor:'#2d6cdf',
                confirmButtonText: active? 'Hide':'Activate',
                cancelButtonText:'Cancel'
            }).then((res)=>{ if(res.isConfirmed){ f.submit(); }});
        });
    });

    function confirmEdit() {
        Swal.fire({
            title: 'Update Product?',
            text: "Are you sure you want to update this product's information?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="bx bx-save"></i> Yes, Update',
            cancelButtonText: '<i class="bx bx-x"></i> Cancel',
            reverseButtons: true,
            customClass: {
                popup: 'swal-logout-popup',
                confirmButton: 'swal-confirm-btn',
                cancelButton: 'swal-cancel-btn'
            },
            showClass: {
                popup: 'animate__animated animate__fadeInDown'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutUp'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Add the edit_product field to the form
                const form = document.getElementById('productForm');
                const editField = document.createElement('input');
                editField.type = 'hidden';
                editField.name = 'edit_product';
                editField.value = '1';
                form.appendChild(editField);
                
                // Submit the form
                form.submit();
            }
        });
    }

    function confirmAdd() {
        Swal.fire({
            title: 'Add Product?',
            text: "Are you sure you want to add this new product to inventory?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="bx bx-plus"></i> Yes, Add',
            cancelButtonText: '<i class="bx bx-x"></i> Cancel',
            reverseButtons: true,
            customClass: {
                popup: 'swal-logout-popup',
                confirmButton: 'swal-confirm-btn',
                cancelButton: 'swal-cancel-btn'
            },
            showClass: {
                popup: 'animate__animated animate__fadeInDown'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutUp'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Add the add_product field to the form
                const form = document.getElementById('productForm');
                const addField = document.createElement('input');
                addField.type = 'hidden';
                addField.name = 'add_product';
                addField.value = '1';
                form.appendChild(addField);
                
                // Submit the form
                form.submit();
            }
        });
    }
    </script>
</body>
</html> 