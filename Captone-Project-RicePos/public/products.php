<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
$user = new User();
if (!$user->isLoggedIn() || !$user->isAdmin()) {
    header('Location: index.php');
    exit;
}
$productObj = new Product();
$message = '';
$editProduct = null;

// Handle add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        $name = $_POST['name'] ?? '';
        $price_kg = floatval($_POST['price_per_kg'] ?? 0);
        $price_sack = $_POST['price_per_sack'] !== '' ? floatval($_POST['price_per_sack']) : null;
        $profit_per_sack = isset($_POST['profit_per_sack']) && $_POST['profit_per_sack'] !== '' ? intval($_POST['profit_per_sack']) : null;
        $stock_kg = floatval($_POST['stock_kg'] ?? 0);
        $stock_sack = floatval($_POST['stock_sack'] ?? 0);
        $category = $_POST['category'] ?? '';
        $low_stock = floatval($_POST['low_stock_threshold'] ?? 10);
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image = 'assets/img/' . uniqid('prod_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $image);
        }
        if ($name && $price_kg > 0) {
            $productObj->add($name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock, $image, $profit_per_sack);
            $message = 'Product added!';
        } else {
            $message = 'Name and price per kg are required.';
        }
    } elseif (isset($_POST['edit_product'])) {
        $id = intval($_POST['id']);
        $name = $_POST['name'] ?? '';
        $price_kg = floatval($_POST['price_per_kg'] ?? 0);
        $price_sack = $_POST['price_per_sack'] !== '' ? floatval($_POST['price_per_sack']) : null;
        $profit_per_sack = isset($_POST['profit_per_sack']) && $_POST['profit_per_sack'] !== '' ? intval($_POST['profit_per_sack']) : null;
        $stock_kg = floatval($_POST['stock_kg'] ?? 0);
        $stock_sack = floatval($_POST['stock_sack'] ?? 0);
        $category = $_POST['category'] ?? '';
        $low_stock = floatval($_POST['low_stock_threshold'] ?? 10);
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image = 'assets/img/' . uniqid('prod_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $image);
        }
        if ($id && $name && $price_kg > 0) {
            $productObj->update($id, $name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock, $image, $profit_per_sack);
            $message = 'Product updated!';
        } else {
            $message = 'Name and price per kg are required.';
        }
    } elseif (isset($_POST['delete_product'])) {
        $id = intval($_POST['id']);
        $productObj->delete($id);
        $message = 'Product deleted!';
    }
}
if (isset($_GET['edit'])) {
    $editProduct = $productObj->getById(intval($_GET['edit']));
}
$products = $productObj->getAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    /* Use global layout sizing from assets/css/style.css; avoid page-level main-content overrides */
    body { display: block; min-height: 100vh; margin: 0; background: #f4f6fb; }
    .product-img {
        width: 48px !important;
        height: 48px !important;
        object-fit: cover !important;
        background: #f8fafc !important;
        border-radius: 8px !important;
        display: block !important;
        margin-left: auto !important;
        margin-right: auto !important;
        box-shadow: 0 2px 8px rgba(44,108,223,0.08);
    }
    </style>
</head>
<body>
    <?php $activePage = 'products.php'; $pageTitle = 'Product Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <div class="container">
            
            <?php if ($message): ?>
                <script>Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo $message; ?>' });</script>
            <?php endif; ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?php echo $editProduct ? 'Edit Product' : 'Add Product'; ?></h5>
                    <form method="post" class="row g-3" enctype="multipart/form-data">
                        <?php if ($editProduct): ?>
                            <input type="hidden" name="id" value="<?php echo $editProduct['id']; ?>">
                        <?php endif; ?>
                        <div class="col-md-3">
                            <input type="text" name="name" class="form-control" placeholder="Product Name" required value="<?php echo $editProduct['name'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" step="0.01" min="0" name="price_per_kg" class="form-control" placeholder="Price/KG" required value="<?php echo $editProduct['price_per_kg'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" step="0.01" min="0" name="price_per_sack" class="form-control" placeholder="Price/Sack" value="<?php echo $editProduct['price_per_sack'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" step="1" min="0" name="profit_per_sack" class="form-control" placeholder="Profit/Sack" value="<?php echo $editProduct['profit_per_sack'] ?? ''; ?>">
                        </div>
					<!-- Force a new row on md+ so labels align cleanly -->
					<div class="w-100 d-none d-md-block"></div>
                        <div class="col-md-1">
                            <input type="number" step="0.01" min="0" name="stock_kg" class="form-control" placeholder="Stock KG" value="<?php echo $editProduct['stock_kg'] ?? ''; ?>">
                        </div>
					<div class="col-md-1">
						<input type="number" step="1" min="0" name="stock_sack" class="form-control" placeholder="Stock (Sacks)" value="<?php echo $editProduct['stock_sack'] ?? ''; ?>">
					</div>
                        <div class="col-md-2">
                            <input type="text" name="category" class="form-control" placeholder="Category" value="<?php echo $editProduct['category'] ?? ''; ?>">
                        </div>
                        <div class="col-md-1">
                            <input type="number" step="0.01" min="0" name="low_stock_threshold" class="form-control" placeholder="Low Stock" value="<?php echo $editProduct['low_stock_threshold'] ?? '10'; ?>">
                        </div>
                        <div class="col-md-3">
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12">
                            <?php if ($editProduct): ?>
                                <button type="submit" name="edit_product" class="btn btn-primary"><i class='bx bx-save'></i> Update</button>
                                <a href="products.php" class="btn btn-secondary">Cancel</a>
                            <?php else: ?>
                                <button type="submit" name="add_product" class="btn btn-success"><i class='bx bx-plus'></i> Add Product</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Product List</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle bg-white">
                            <thead class="table-light">
                                <tr>
                                    <th>Image</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Price/KG</th>
                                    <th>Price/Sack</th>
                                    <th>Stock (KG)</th>
                                    <th>Stock (Sack)</th>
                                    <th>Low Stock</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $p): ?>
                                    <tr<?php if ($p['stock_kg'] <= $p['low_stock_threshold']) echo ' class="table-warning"'; ?>>
                                        <td><img src="<?php echo !empty($p['image']) ? $p['image'] : 'assets/img/sack-placeholder.png'; ?>" alt="Product Image" class="product-img"></td>
                                        <td><?php echo htmlspecialchars($p['name']); ?></td>
                                        <td><?php echo htmlspecialchars($p['category']); ?></td>
                                        <td>₱<?php echo number_format($p['price_per_kg'],2); ?></td>
                                        <td><?php echo $p['price_per_sack'] !== null ? '₱'.number_format($p['price_per_sack'],2) : '-'; ?></td>
                                        <td><?php echo $p['stock_kg']; ?></td>
                                        <td><?php echo $p['stock_sack']; ?></td>
                                        <td><?php echo $p['low_stock_threshold']; ?></td>
                                        <td>
                                            <a href="products.php?edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary"><i class='bx bx-edit'></i></a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this product?');">
                                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" name="delete_product" class="btn btn-sm btn-danger"><i class='bx bx-trash'></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="assets/js/main.js"></script>
</body>
</html> 