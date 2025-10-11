<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Sale.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: index.php');
    exit;
}
// Block admin role from accessing POS
if ($user->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}
$productObj = new Product();
$saleObj = new Sale();

// Handle sale submission
$saleSuccess = false;
$saleError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_sale'])) {
    ensure_receipt_columns();
    $items = [];
    $total = 0;
    foreach ($_POST['product_id'] as $i => $pid) {
        $pid = (int)$pid;
        $qty_kg = floatval($_POST['quantity_kg'][$i]);
        $qty_sack = floatval($_POST['quantity_sack'][$i]);
        $product = $productObj->getById($pid);
        if (!$product) continue;
        // Prevent overselling server-side
        if ($qty_kg > $product['stock_kg'] || $qty_sack > $product['stock_sack']) {
            $saleError = 'Requested quantity exceeds stock for ' . htmlspecialchars($product['name']);
            $items = [];
            break;
        }
        $price = round($qty_kg * $product['price_per_kg'] + $qty_sack * ($product['price_per_sack'] ?? 0));
        $items[] = [
            'product_id' => $pid,
            'quantity_kg' => $qty_kg,
            'quantity_sack' => $qty_sack,
            'price' => $price
        ];
        $total += $price;
    }
    // Strip commas from payment before converting to integer
    $payment = intval(str_replace(',', '', $_POST['payment'] ?? '0'));
    $change = $payment - $total;
    // Validate customer name and email (email optional)
    $buyer_name = trim($_POST['buyer_name'] ?? '');
    $buyer_email = trim($_POST['buyer_email'] ?? '');
    if ($buyer_name === '') {
        $saleError = 'Please provide a customer name.';
    } elseif ($buyer_email !== '' && !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        $saleError = 'Please provide a valid email address or leave it empty.';
    }
    if (!$saleError && $total > 0 && $payment >= $total) {
        $buyer_name = $buyer_name !== '' ? $buyer_name : null;
        $buyer_email = $buyer_email !== '' ? $buyer_email : null;
        $transaction_id = $saleObj->create($_SESSION['user_id'], $total, $payment, $change, $items, $buyer_name, $buyer_email);
        // Send e-receipt if email provided
        if ($buyer_email) {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare('SELECT id, transaction_id, datetime, total_amount, payment, change_due, buyer_name FROM sales WHERE transaction_id = ?');
            $stmt->execute([$transaction_id]);
            $sale = $stmt->fetch();
            $itemsStmt = $pdo->prepare('SELECT si.quantity_kg, si.quantity_sack, si.price, p.name FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?');
            $itemsStmt->execute([$sale['id']]);
            $itemsRows = $itemsStmt->fetchAll();
            $html = build_receipt_html($sale, $itemsRows, $buyer_name, false);
            $sent = send_receipt_email($buyer_email, 'Your RicePOS Receipt '.$transaction_id, $html, $buyer_name);
            if (!isset($_SESSION)) { session_start(); }
            $_SESSION['email_notice'] = $sent
                ? ('E-receipt sent to ' . $buyer_email)
                : ('E-receipt could not be sent to ' . $buyer_email);
        }
        $saleSuccess = $transaction_id;
        $_POST = [];
    } else {
        if (!$saleError) {
            $saleError = 'Payment is less than total or no items selected.';
        }
    }
}
// Always fetch fresh products after handling POST so stocks reflect latest values
$products = $productObj->getAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root{ --brand:#2d6cdf; --brand-600:#1e4fa3; --ink:#111827; --muted:#6b7280; --card:#ffffff; --bg:#f7f9fc; --line:#e5e7eb; }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; }
    body { display: block; min-height: 100vh; margin: 0; background: var(--bg); color: var(--ink); overflow-x: hidden; }
    /* Use shared .main-content sizing from assets/css/style.css to align with dashboard layout */
    .main-content { background: var(--bg); min-height: 100vh; overflow-x: hidden; }
    /* POS Grid */
    .pos-layout { 
        display: grid; 
        grid-template-areas: 'products cart'; 
        grid-template-columns: 1fr 520px; 
        gap: 1rem; 
        align-items: start;
        min-height: calc(100dvh - var(--header-height) - 2.4rem);
    }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; grid-area: products; }
    .product-card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 0.8rem; display: flex; flex-direction: column; align-items: center; box-shadow: 0 8px 24px rgba(17,24,39,0.06); }
    .product-card .prod-img { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .product-card .prod-name { margin-top: 0.5rem; font-weight: 600; text-align: center; }
    .product-card .prod-meta { font-size: 0.9rem; color: #6b7280; }
    .product-card .prod-price { font-size: 0.95rem; margin-top: 0.2rem; }
    .product-card .prod-stock { font-size: 0.85rem; color: #6b7280; margin-top: 0.1rem; }
    /* Quick quantity controls */
    .qty-controls {
        display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;
        justify-content: center;
    }
    .qty-btn {
        width: 32px; height: 32px; border-radius: 8px;
        background: #f1f5f9; border: 1px solid #cbd5e1;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; font-weight: 600; font-size: 1.1rem;
        transition: all 0.15s ease;
    }
    .qty-btn:hover { background: #e2e8f0; border-color: #94a3b8; }
    .qty-btn:active { transform: scale(0.95); }
    .qty-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .qty-display {
        min-width: 40px; text-align: center; font-weight: 700;
        font-size: 1rem; color: #1e293b;
    }
    /* .cart-badge styles removed per request */
    .product-card { position: relative; }
    /* Readable primary Add button for POS (consistent with Delivery) */
    .product-card .btn-add {
        background: #3b82f6; color: #ffffff;
        border: 1px solid #2563eb;
        padding: 0.5rem 0.85rem;
        margin-top: 0.5rem;
        display: inline-flex; align-items: center; gap: 0.4rem;
        font-size: 0.98rem; font-weight: 700; letter-spacing: 0.2px;
        border-radius: 10px; cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: background .15s ease, border-color .15s ease, transform .06s ease;
    }
    .product-card .btn-add:hover { background: #2563eb; border-color: #1d4ed8; }
    .product-card .btn-add:active { transform: translateY(1px); }
    .product-card .btn-add:focus-visible { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .product-card .btn-add i { font-size: 1.1rem; }
    /* Removed gradient/hover styling for Add button per request */
    .cart-panel { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 0.8rem; height: fit-content; position: sticky; top: 1.2rem; box-shadow: 0 8px 24px rgba(17,24,39,0.06); }
    .cart-header { display:flex; align-items:center; justify-content: space-between; gap: 0.5rem; font-weight: 700; margin-bottom: 0.6rem; }
    .cart-header .muted { font-weight: 500; color: #6b7280; }
    .cart-items { display: flex; flex-direction: column; gap: 0.6rem; max-height: 40vh; overflow: auto; border-bottom: 1px dashed #e5e7eb; padding-bottom: 0.5rem; }
    .cart-row { display: grid; grid-template-columns: 44px 1fr 180px 110px 36px; gap: 0.6rem; align-items: center; }
    .cart-thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: #f8fafc; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    .cart-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cart-qty { text-align: center; }
    .cart-qty label { display:block; font-size: 0.72rem; color: #6b7280; margin-bottom: 2px; }
    .cart-qty input { width: 100%; padding: 0.45rem; height: 38px; border:1px solid #dbeafe; border-radius: 0; text-align: center; font-weight: 600; }
    .cart-qty-input { width: 70px !important; height: 48px !important; border: 2px solid #cbd5e1 !important; border-radius: 0 !important; text-align: center; font-weight: 700; font-size: 1.1rem; padding: 0.5rem 0.3rem !important; }
    .cart-sub { text-align: right; font-weight: 700; }
    .totals { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.6rem; }
    .tot-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; align-items: center; }
    .tot-row input { width: 100%; padding: 0.6rem; height: 40px; border:1px solid #dbeafe; border-radius:0; background:#f8fafc; text-align: center; font-weight: 700; }
    .cart-footer { margin-top: 0.75rem; border-top: 1px dashed #e5e7eb; padding-top: 0.75rem; display:flex; flex-direction: column; gap: 0.5rem; }
    .cta-btn { width: 100%; padding: 0.75rem 1rem; font-weight: 700; font-size: 1rem; border-radius: 10px; }
    .process-cta { background: linear-gradient(135deg,var(--brand),var(--brand-600)); color: #fff; border: 1px solid var(--brand-600); transition: background 0.18s, border-color 0.18s, transform 0.08s; }
    .process-cta:hover { background: linear-gradient(135deg,var(--brand-600),#1e40af); border-color: #1e40af; color: #fff; }
    .process-cta:active { transform: translateY(1px); }
    .process-cta:focus { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; }
    .form-grid .field-email { grid-column: span 2; }
    .form-grid textarea { grid-column: span 2; }
    input[type="text"], input[type="tel"], textarea { padding: 0.55rem; border:1px solid #dbeafe; border-radius:6px; width:100%; }
    /* Hide spinners for number input (payment) */
    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    .cart-header { display:flex; align-items:center; justify-content: space-between; gap: 0.5rem; font-weight: 700; margin-bottom: 0.6rem; }
    .cart-header .muted { font-weight: 500; color: #6b7280; }
    .cart-items { display: flex; flex-direction: column; gap: 0.6rem; flex: 0 1 auto; overflow: auto; border-bottom: 1px dashed #e5e7eb; padding-bottom: 0.5rem; max-height: calc(100dvh - var(--header-height) - 320px); }
    /* Use global Delivery cart stepper styles from assets/css/style.css */
    .payment-shortcuts { display: flex; gap: 0.4rem; margin-top: 0.4rem; flex-wrap: wrap; }
    .payment-shortcut { padding: 0.4rem 0.6rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease; }
    .payment-shortcut:hover { background: #e2e8f0; border-color: #94a3b8; }
    .cart-footer { margin-top: 0.75rem; border-top: 1px dashed #e5e7eb; padding-top: 0.75rem; display:flex; flex-direction: column; gap: 0.5rem; }
    .cta-btn { width: 100%; padding: 0.75rem 1rem; font-weight: 700; font-size: 1rem; border-radius: 10px; }
    .cta-btn[disabled] { opacity: 0.6; cursor: not-allowed; }
    /* Process Sale CTA: minimal, brand-forward */
    .process-cta { background: linear-gradient(135deg,var(--brand),var(--brand-600)); color: #fff; border: 1px solid var(--brand-600); transition: background 0.18s, border-color 0.18s, transform 0.08s; }
    .process-cta:hover { background: linear-gradient(135deg,var(--brand-600),#1e40af); border-color: #1e40af; color: #fff; }
    .process-cta:active { transform: translateY(1px); }
    .process-cta:focus { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .field { display:flex; flex-direction: column; gap: 6px; }
    .lbl { font-size: 0.85rem; color:#6b7280; }
    .inpt { width: 100%; padding: 0.55rem; height: 40px; border:1px solid #dbeafe; border-radius:8px; background:#f8fafc; }
    .inpt:focus { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .swal2-actions .swal2-confirm.swal-ok { background:#16a34a; color:#fff; border-color:#15803d; }
    .swal2-actions .swal2-confirm.swal-ok:hover { background:#15803d; }
    .swal2-actions .swal2-cancel.swal-cancel { background:#eef2ff; color:#111827; border-color:#c7d2fe; }
    .swal2-actions .swal2-cancel.swal-cancel:hover { background:#e0e7ff; }
    @media (max-width: 920px) { .pos-layout { grid-template-areas: 'products' 'cart'; grid-template-columns: 1fr; min-height: auto; } .cart-panel { position: static; height: auto; } }

    /* Fullscreen processing overlay */
    .processing-overlay {
        position: fixed; inset: 0; z-index: 9999;
        display: none; align-items: center; justify-content: center;
        backdrop-filter: blur(6px);
        background: radial-gradient(1200px 800px at 50% -10%, rgba(29,78,216,0.20), rgba(255,255,255,0.0)),
                    linear-gradient(180deg, rgba(248,250,252,0.85), rgba(248,250,252,0.95));
    }
    .processing-overlay.show { display: flex; }
    .overlay-card {
        width: 92%; max-width: 420px; padding: 20px 22px; border-radius: 14px;
        background: #ffffff; border: 1px solid #e5e7eb; box-shadow: 0 18px 60px rgba(17,24,39,0.2);
        display: flex; flex-direction: column; align-items: center; gap: 12px;
    }
    .overlay-title { font-weight: 800; color:#111827; }
    .overlay-sub { color:#6b7280; font-size: 0.95rem; text-align:center; }
    .loader {
        width: 56px; height: 56px; border-radius: 50%;
        border: 6px solid rgba(37,99,235,0.2); border-top-color: #2563eb;
        animation: spin 0.9s linear infinite; margin: 6px 0 2px 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php $activePage = 'pos.php'; $pageTitle = 'Point of Sale'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <div class="container">
            
            <form method="post" id="saleForm" autocomplete="off">
                <div class="pos-layout">
                    <section class="product-grid">
                        <?php foreach ($products as $p): 
                            if (isset($p['is_active']) && (int)$p['is_active'] === 0) { continue; }
                            $img = !empty($p['image']) ? $p['image'] : 'assets/img/sack-placeholder.png';
                        ?>
                        <div class="product-card" 
                             data-id="<?php echo (int)$p['id']; ?>"
                             data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                             data-category="<?php echo htmlspecialchars($p['category'], ENT_QUOTES); ?>"
                              data-pricekg="0"
                             data-pricesack="<?php echo $p['price_per_sack'] !== null ? (float)$p['price_per_sack'] : ''; ?>"
                              data-stockkg="0"
                             data-stocksack="<?php echo (float)$p['stock_sack']; ?>"
                             data-img="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>">
                            <img src="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>" class="prod-img">
                            <div class="prod-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="prod-meta">Sack: <?php echo htmlspecialchars($p['category']); ?></div>
                             <div class="prod-price"><?php if ($p['price_per_sack'] !== null): ?>₱<?php echo number_format((float)$p['price_per_sack'], 0); ?> / sack<?php else: ?>—<?php endif; ?></div>
                             <div class="prod-stock">Sacks: <?php echo (float)$p['stock_sack']; ?></div>
                            <button type="button" class="btn-add" data-id="<?php echo (int)$p['id']; ?>"><i class='bx bx-plus'></i> Add to Cart</button>
                        </div>
                        <?php endforeach; ?>
                    </section>
                    <aside class="cart-panel">
                        <div class="cart-header">
                            <div>Cart <span class="muted" id="cartCount">(0)</span></div>
                            <button type="button" class="btn" id="clearCart"><i class='bx bx-trash'></i> Clear</button>
                        </div>
                        <div id="cartItems" class="cart-items"></div>
                        <div id="cartInputs" style="display:none;"></div>
                        <div class="form-grid" style="margin-top:0.5rem;">
                            <div class="field">
                                <label class="lbl" for="buyer_name">Customer Name</label>
                                <input class="inpt" type="text" name="buyer_name" id="buyer_name" placeholder="e.g. Juan Dela Cruz" required>
                            </div>
                            <div class="field field-email">
                                <label class="lbl" for="buyer_email">Customer Email (E‑Receipt) <span style="color: #6b7280; font-weight: 400;">- Optional</span></label>
                                <input class="inpt" type="email" name="buyer_email" id="buyer_email" autocomplete="email" inputmode="email">
                            </div>
                        </div>
                        <div class="totals">
                            <div class="tot-row"><span>Total</span><input type="text" id="total" name="total" readonly value="0"></div>
                            <div class="tot-row"><span>Payment</span><input type="text" inputmode="numeric" name="payment" id="payment" placeholder="0"></div>
                            <div class="tot-row"><span style="color: #dc2626;">Change</span><input type="text" id="change" name="change" readonly value="0"></div>
                        </div>
                        <div class="cart-footer">
                            <button type="submit" id="processSaleBtn" class="btn cta-btn process-cta"><i class='bx bx-receipt'></i> Process Sale</button>
                        </div>
                    </aside>
                </div>
            </form>
        </div>
    </main>
    <!-- Processing overlay -->
    <div id="processingOverlay" class="processing-overlay" aria-hidden="true">
        <div class="overlay-card">
            <div class="loader" role="status" aria-label="Loading"></div>
            <div class="overlay-title">Processing sale…</div>
            <div class="overlay-sub">Sending e‑receipt and preparing your receipt</div>
        </div>
    </div>
    <script>
    // POS grid/cart behaviors
     const cart = new Map(); // key: productId, value: {id, name, img, priceSack, stockSack, qtySack}

    function showProcessingOverlay() {
        const ov = document.getElementById('processingOverlay');
        if (ov) { ov.classList.add('show'); }
        const btn = document.getElementById('processSaleBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Processing…";
        }
    }

    function renderCart() {
        const cartItemsEl = document.getElementById('cartItems');
        const inputsEl = document.getElementById('cartInputs');
        cartItemsEl.innerHTML = '';
        inputsEl.innerHTML = '';

        let total = 0;
        let count = 0;
        cart.forEach((item) => {
            const subtotal = Math.round(item.qtySack * (item.priceSack || 0));
            total += subtotal;

            const row = document.createElement('div');
            row.className = 'cart-row';
            row.innerHTML = `
                <img class="cart-thumb" src="${item.img}" alt="${item.name}">
                <div class="cart-name">${item.name}</div>
                <div class="cart-qty">
                    <label>Sack</label>
                    <div class="cart-stepper">
                        <button type="button" class="cart-minus" data-id="${item.id}" ${item.qtySack <= 1 ? 'disabled' : ''}>−</button>
                        <input type="number" class="cart-qty-input" value="${item.qtySack}" min="1" max="${item.stockSack}" data-id="${item.id}">
                        <button type="button" class="cart-plus" data-id="${item.id}" ${item.qtySack >= item.stockSack ? 'disabled' : ''}>+</button>
                    </div>
                </div>
                <div class="cart-sub">₱${subtotal.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                <button type="button" class="btn btn-delete cart-remove" data-id="${item.id}"><i class='bx bx-trash'></i></button>
            `;
            cartItemsEl.appendChild(row);

            // hidden inputs for form submission arrays
            const hidPid = document.createElement('input');
            hidPid.type = 'hidden';
            hidPid.name = 'product_id[]';
            hidPid.value = String(item.id);
            inputsEl.appendChild(hidPid);

            const hidKg = document.createElement('input');
            hidKg.type = 'hidden';
            hidKg.name = 'quantity_kg[]';
            hidKg.value = '0';
            inputsEl.appendChild(hidKg);

            const hidSack = document.createElement('input');
            hidSack.type = 'hidden';
            hidSack.name = 'quantity_sack[]';
            hidSack.value = String(item.qtySack);
            inputsEl.appendChild(hidSack);
            count++;
        });

        document.getElementById('total').value = total.toLocaleString('en-PH', { maximumFractionDigits: 0 });
        const payment = parseFloat((document.getElementById('payment').value || '0').toString().replace(/,/g, '')) || 0;
        const change = Math.max(0, payment - total);
        document.getElementById('change').value = change.toLocaleString('en-PH', { maximumFractionDigits: 0 });
        document.getElementById('cartCount').textContent = `(${count})`;

        // Bind row events after render
        cartItemsEl.querySelectorAll('.cart-plus').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const pid = parseInt(e.target.getAttribute('data-id'));
                const item = cart.get(pid);
                if (!item) return;
                const newQty = Math.min(item.stockSack, item.qtySack + 1);
                item.qtySack = newQty;
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('.cart-minus').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const pid = parseInt(e.target.getAttribute('data-id'));
                const item = cart.get(pid);
                if (!item) return;
                const newQty = Math.max(0, item.qtySack - 1);
                if (newQty === 0) {
                    cart.delete(pid);
                } else {
                    item.qtySack = newQty;
                }
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('.cart-remove').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const pid = parseInt(e.currentTarget.getAttribute('data-id'));
                cart.delete(pid);
                renderCart();
            });
        });
        
        // Add event listeners for manual quantity input
        cartItemsEl.querySelectorAll('.cart-qty-input').forEach((input) => {
            input.addEventListener('change', (e) => {
                const pid = parseInt(e.target.getAttribute('data-id'));
                const item = cart.get(pid);
                if (!item) return;
                
                let newQty = parseInt(e.target.value) || 1;
                newQty = Math.max(1, Math.min(item.stockSack, newQty));
                
                item.qtySack = newQty;
                renderCart();
            });
        });
    }

    function addToCartFromCard(cardEl) {
        const id = parseInt(cardEl.getAttribute('data-id'));
        const name = cardEl.getAttribute('data-name');
        const img = cardEl.getAttribute('data-img');
        const priceSack = cardEl.getAttribute('data-pricesack') !== '' ? parseFloat(cardEl.getAttribute('data-pricesack')) : null;
        const stockSack = parseFloat(cardEl.getAttribute('data-stocksack')) || 0;
        if (!cart.has(id)) {
            cart.set(id, { id, name, img, priceSack, stockSack, qtySack: 1 });
        } else {
            const item = cart.get(id);
            if (item.qtySack < stockSack) {
                item.qtySack += 1;
            }
        }
        renderCart();
    }


    document.addEventListener('click', function (e) {
        const addBtn = e.target.closest('.btn-add');
        if (addBtn) {
            const card = addBtn.closest('.product-card');
            addToCartFromCard(card);
        }
    });

    // Enforce whole numbers in payment input and add comma formatting
    const payEl = document.getElementById('payment');
    payEl.addEventListener('input', () => {
        const cleaned = (payEl.value || '').replace(/\D+/g, '');
        const formatted = cleaned ? parseInt(cleaned).toLocaleString('en-PH') : '';
        payEl.value = formatted;
        renderCart();
    });
    document.getElementById('clearCart').addEventListener('click', function() {
        cart.clear();
        renderCart();
    });

    // Modern submit handler with SweetAlert2 confirmation and action injection
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('saleForm');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const total = parseFloat((document.getElementById('total').value || '0').toString().replace(/,/g, '')) || 0;
            const payment = parseFloat((document.getElementById('payment').value || '0').toString().replace(/,/g, '')) || 0;

            if (total === 0) {
                Swal.fire({ icon: 'warning', title: 'No Items Selected', text: 'Please select at least one item before processing the sale.' });
                return;
            }
            if (payment < total) {
                Swal.fire({ icon: 'error', title: 'Insufficient Payment', text: 'Payment amount is less than the total amount.' });
                return;
            }

            const nameEl = document.getElementById('buyer_name');
            const emailEl = document.getElementById('buyer_email');
            const name = (nameEl?.value || '').trim();
            const email = (emailEl?.value || '').trim();
            if (!name) {
                Swal.fire({ icon: 'warning', title: 'Customer Name Required', text: 'Please enter a customer name.' });
                return;
            }
            if (email && !/^\S+@\S+\.\S+$/.test(email)) {
                Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address or leave it empty.' });
                return;
            }

            Swal.fire({
                title: 'Review and Process Sale',
                html: `
                    <div style="text-align:left;line-height:1.6">
                        <div><strong>Customer:</strong> ${name}${email ? `<br><strong>Email:</strong> ${email}` : '<br><em>No email provided</em>'}</div>
                        <div style=\"margin-top:6px\"><strong>Total:</strong> Php ${total.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                        <div><strong>Payment:</strong> Php ${payment.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                        <div><strong>Change:</strong> Php ${(payment - total).toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'OK, Process',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                reverseButtons: true,
                customClass: { confirmButton: 'btn btn-add swal-ok', cancelButton: 'btn swal-cancel' },
                showClass: { popup: 'animate__animated animate__fadeInDown' },
                hideClass: { popup: 'animate__animated animate__fadeOutUp' }
            }).then(function (res) {
                if (res.isConfirmed) {
                    if (!form.querySelector('input[type="hidden"][name="process_sale"]')) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'process_sale';
                        hidden.value = '1';
                        form.appendChild(hidden);
                    }
                    // Show overlay and submit with slight delay to ensure paint
                    showProcessingOverlay();
                    setTimeout(() => { form.submit(); }, 30);
                }
            });
        });
        renderCart();
    });

    // Quick quantity controls
    function updateProductQuantity(productId, change) {
        const card = document.querySelector(`[data-id="${productId}"]`);
        if (!card) return;
        
        const stockSack = parseFloat(card.getAttribute('data-stocksack')) || 0;
        let currentQty = 0;
        
        if (cart.has(productId)) {
            currentQty = cart.get(productId).qtySack;
        }
        
        const newQty = Math.max(0, Math.min(stockSack, currentQty + change));
        
        if (newQty === 0 && cart.has(productId)) {
            cart.delete(productId);
        } else if (newQty > 0) {
            if (!cart.has(productId)) {
                const name = card.getAttribute('data-name');
                const img = card.getAttribute('data-img');
                const priceSack = card.getAttribute('data-pricesack') !== '' ? parseFloat(card.getAttribute('data-pricesack')) : null;
                cart.set(productId, { id: productId, name, img, priceSack, stockSack, qtySack: 0 });
            }
            cart.get(productId).qtySack = newQty;
        }
        
        updateProductDisplay(productId);
        renderCart();
    }
    
    function updateProductDisplay(productId) {
        const qtyDisplay = document.querySelector(`.qty-display[data-id="${productId}"]`);
        const minusBtn = document.querySelector(`.qty-minus[data-id="${productId}"]`);
        const plusBtn = document.querySelector(`.qty-plus[data-id="${productId}"]`);
        const card = document.querySelector(`[data-id="${productId}"]`);
        
        if (!qtyDisplay || !card) return;
        
        const currentQty = cart.has(productId) ? cart.get(productId).qtySack : 0;
        const stockSack = parseFloat(card.getAttribute('data-stocksack')) || 0;
        
        qtyDisplay.textContent = currentQty;
        
        if (minusBtn) minusBtn.disabled = currentQty <= 0;
        if (plusBtn) plusBtn.disabled = currentQty >= stockSack;
        
    }
    
    // Payment shortcuts removed per user request
    function updatePaymentShortcuts() {
        const shortcutsEl = document.getElementById('paymentShortcuts');
        shortcutsEl.innerHTML = ''; // No shortcuts
    }
    
    // Event listeners
    document.addEventListener('click', function(e) {
        // Quantity controls
        if (e.target.classList.contains('qty-plus')) {
            const productId = parseInt(e.target.getAttribute('data-id'));
            updateProductQuantity(productId, 1);
        } else if (e.target.classList.contains('qty-minus')) {
            const productId = parseInt(e.target.getAttribute('data-id'));
            updateProductQuantity(productId, -1);
        }
        
        // Payment shortcuts
        if (e.target.classList.contains('payment-shortcut')) {
            const amount = parseFloat(e.target.getAttribute('data-amount'));
            document.getElementById('payment').value = amount;
            renderCart();
        }
    });
    
    // Override renderCart to update shortcuts and displays
    const originalRenderCart = renderCart;
    renderCart = function() {
        originalRenderCart();
        updatePaymentShortcuts();
        
        // Update all product displays
        document.querySelectorAll('.product-card').forEach(card => {
            const productId = parseInt(card.getAttribute('data-id'));
            updateProductDisplay(productId);
        });
    };

    <?php if ($saleSuccess): ?>
        // Show overlay briefly then redirect to receipt
        showProcessingOverlay();
        setTimeout(() => { window.location.href = 'receipt.php?txn=<?php echo urlencode($saleSuccess); ?>'; }, 50);
    <?php elseif ($saleError): ?>
        Swal.fire({ icon: 'error', title: 'Sale Failed', text: '<?php echo $saleError; ?>' });
    <?php endif; ?>
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html> 