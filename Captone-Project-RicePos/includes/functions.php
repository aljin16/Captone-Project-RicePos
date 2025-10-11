<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Try load PHPMailer if available via Composer (vendor/autoload.php)
// Try Composer autoload in typical locations
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} else {
    // Fallback: try common manual PHPMailer locations (if downloaded without Composer)
    $phpMailerPaths = [
        __DIR__ . '/PHPMailer/src/Exception.php',
        __DIR__ . '/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/PHPMailer/src/SMTP.php',
        __DIR__ . '/../PHPMailer/src/Exception.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/src/SMTP.php',
        __DIR__ . '/../../PHPMailer/src/Exception.php',
        __DIR__ . '/../../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../../PHPMailer/src/SMTP.php',
        // Support bundled PHPMailer under PHPMailer-master
        __DIR__ . '/../PHPMailer-master/src/Exception.php',
        __DIR__ . '/../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer-master/src/SMTP.php',
        __DIR__ . '/../../PHPMailer-master/src/Exception.php',
        __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../../PHPMailer-master/src/SMTP.php',
    ];
    // Attempt to include without causing warnings
    foreach (array_chunk($phpMailerPaths, 3) as $chunk) {
        if (file_exists($chunk[0]) && file_exists($chunk[1]) && file_exists($chunk[2])) {
            require_once $chunk[0];
            require_once $chunk[1];
            require_once $chunk[2];
            break;
        }
    }
}

function get_user_by_id($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function create_user($username, $email, $password, $role = 'staff', $status = 'active') {
    global $pdo;
    // Uniqueness checks with clear error codes
    $existsU = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    $existsU->execute([$username]);
    if ($existsU->fetchColumn()) { throw new Exception('duplicate_username'); }
    $existsE = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $existsE->execute([$email]);
    if ($existsE->fetchColumn()) { throw new Exception('duplicate_email'); }
    // Normalize role against current ENUM options
    $role = normalize_user_role_for_insert($role);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$username, $email, $hash, $role, $status]);
}

/**
 * Ensure provided role matches users.role ENUM. If 'staff' exists use it; otherwise map to 'sales_staff' when available.
 */
function normalize_user_role_for_insert(string $role): string {
    global $pdo;
    $role = trim($role);
    try {
        $row = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if (!$row || empty($row['Type'])) { return $role; }
        $type = $row['Type']; // e.g., enum('admin','sales_staff','delivery_staff')
        if (preg_match_all("/\'([^\']+)\'/", $type, $m)) {
            $options = $m[1];
            // If provided role is valid, use it
            if (in_array($role, $options, true)) { return $role; }
            // Map legacy 'staff' to 'sales_staff' if present
            if ($role === 'staff' && in_array('sales_staff', $options, true)) { return 'sales_staff'; }
            // Fallback to first option
            return $options[0];
        }
    } catch (Throwable $e) { /* ignore */ }
    return $role;
}

function edit_user($id, $username, $email, $role, $status) {
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, role = ?, status = ? WHERE id = ?');
    return $stmt->execute([$username, $email, $role, $status, $id]);
}

function delete_user($id) {
    global $pdo;
    // Attempt to delete user but handle FK constraint violations gracefully
    try {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        // MySQL error code 1451 indicates a foreign key constraint preventing deletion
        if ($e->getCode() === '23000' || strpos($e->getMessage(), '1451') !== false) {
            // Return a structured error to the caller
            throw new Exception('fk_constraint');
        }
        throw $e;
    }
}

function get_all_users() {
    global $pdo;
    $stmt = $pdo->query('SELECT id, username, email, role, status, last_login, created_at FROM users');
    return $stmt->fetchAll();
}

function get_all_products() {
    global $pdo;
    $stmt = $pdo->query('SELECT * FROM products');
    return $stmt->fetchAll();
}

function get_all_products_with_images() {
    global $pdo;
    $stmt = $pdo->query('SELECT * FROM products');
    return $stmt->fetchAll();
}

function get_product_by_id($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function add_product($name, $description, $image, $price, $quantity_in_stock, $low_stock_threshold, $supplier_id = null) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO products (name, description, image, price, quantity_in_stock, low_stock_threshold, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    return $stmt->execute([$name, $description, $image, $price, $quantity_in_stock, $low_stock_threshold, $supplier_id]);
}

function update_product_stock($product_id, $qty) {
    global $pdo;
    $stmt = $pdo->prepare('UPDATE products SET quantity_in_stock = ? WHERE id = ?');
    return $stmt->execute([$qty, $product_id]);
}

function update_last_login($id) {
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    return $stmt->execute([$id]);
}

function get_all_suppliers() {
    global $pdo;
    $stmt = $pdo->query('SELECT * FROM supplier');
    return $stmt->fetchAll();
}
/**
 * Checks for an existing supplier by name (case-insensitive), optionally matching phone/email.
 * When $excludeId is provided, that record is ignored (useful for edits).
 */
function supplier_exists(string $name, ?string $phone = null, ?string $email = null, ?int $excludeId = null): bool {
    global $pdo;
    $clauses = ['LOWER(TRIM(name)) = LOWER(TRIM(?))'];
    $params = [$name];
    if ($phone !== null && $phone !== '') { $clauses[] = 'TRIM(phone) = TRIM(?)'; $params[] = $phone; }
    if ($email !== null && $email !== '') { $clauses[] = 'LOWER(TRIM(email)) = LOWER(TRIM(?))'; $params[] = $email; }
    $where = implode(' AND ', $clauses);
    if ($excludeId !== null) { $where .= ' AND supplier_id <> ?'; $params[] = $excludeId; }
    $sql = 'SELECT 1 FROM supplier WHERE ' . $where . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}
function get_supplier_by_id($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM supplier WHERE supplier_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}
function ensure_supplier_schema(): void {
    global $pdo;
    try {
        // If landline column doesn't exist but contact exists, rename contact -> landline
        $cols = $pdo->query("SHOW COLUMNS FROM supplier")->fetchAll();
        $hasContact = false; $hasLandline = false;
        foreach ($cols as $c) { if ($c['Field'] === 'contact') { $hasContact = true; } if ($c['Field'] === 'landline') { $hasLandline = true; } }
        if ($hasContact && !$hasLandline) {
            $pdo->exec("ALTER TABLE supplier CHANGE contact landline VARCHAR(100) NULL");
        }
    } catch (Throwable $e) { /* ignore */ }
}
function add_supplier($name, $landline, $phone, $email, $address) {
    global $pdo;
    ensure_supplier_schema();
    $stmt = $pdo->prepare('INSERT INTO supplier (name, landline, phone, email, address) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$name, $landline, $phone, $email, $address]);
}
function edit_supplier($id, $name, $landline, $phone, $email, $address) {
    global $pdo;
    ensure_supplier_schema();
    $stmt = $pdo->prepare('UPDATE supplier SET name = ?, landline = ?, phone = ?, email = ?, address = ? WHERE supplier_id = ?');
    return $stmt->execute([$name, $landline, $phone, $email, $address, $id]);
}
function delete_supplier($id) {
    global $pdo;
    $stmt = $pdo->prepare('DELETE FROM supplier WHERE supplier_id = ?');
    return $stmt->execute([$id]);
} 
// Duplicate check helpers for specific error messaging
function supplier_name_exists(string $name, ?int $excludeId = null): bool {
    global $pdo; $sql = 'SELECT 1 FROM supplier WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))'; $params = [$name];
    if ($excludeId !== null) { $sql .= ' AND supplier_id <> ?'; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (bool)$stmt->fetchColumn();
}
function supplier_phone_exists(string $phone, ?int $excludeId = null): bool {
    global $pdo; $sql = 'SELECT 1 FROM supplier WHERE TRIM(phone) = TRIM(?)'; $params = [$phone];
    if ($excludeId !== null) { $sql .= ' AND supplier_id <> ?'; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (bool)$stmt->fetchColumn();
}
function supplier_email_exists(string $email, ?int $excludeId = null): bool {
    global $pdo; $sql = 'SELECT 1 FROM supplier WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))'; $params = [$email];
    if ($excludeId !== null) { $sql .= ' AND supplier_id <> ?'; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (bool)$stmt->fetchColumn();
}
function supplier_address_exists(string $address, ?int $excludeId = null): bool {
    global $pdo; $sql = 'SELECT 1 FROM supplier WHERE LOWER(TRIM(address)) = LOWER(TRIM(?))'; $params = [$address];
    if ($excludeId !== null) { $sql .= ' AND supplier_id <> ?'; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (bool)$stmt->fetchColumn();
}

/**
 * Ensure optional columns exist for e-receipt on sales and delivery_orders tables.
 */
function ensure_receipt_columns(): void {
    global $pdo;
    // Add buyer_name and buyer_email to sales if missing
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_name VARCHAR(120) NULL");
    } catch (Throwable $e) { /* ignore for MariaDB < 10.3 */
        try { $pdo->exec("ALTER TABLE sales ADD COLUMN buyer_name VARCHAR(120) NULL"); } catch (Throwable $e2) {}
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_email VARCHAR(190) NULL");
    } catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE sales ADD COLUMN buyer_email VARCHAR(190) NULL"); } catch (Throwable $e2) {}
    }
    // Add customer_email to delivery_orders if missing
    try {
        $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN IF NOT EXISTS customer_email VARCHAR(190) NULL");
    } catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN customer_email VARCHAR(190) NULL"); } catch (Throwable $e2) {}
    }
}

/**
 * Build a simple HTML receipt for email body.
 */
function build_receipt_html(array $sale, array $items, ?string $buyerName, bool $isDelivery = false): string {
    $buyerLine = $buyerName ? '<p style="margin:4px 0 0 0;"><strong>Buyer:</strong> '.htmlspecialchars($buyerName).'</p>' : '';
    
    // Add tracking link for delivery orders
    $trackingSection = '';
    if ($isDelivery) {
        // Use APP_BASE_URL from config
        $baseUrl = defined('APP_BASE_URL') && APP_BASE_URL ? rtrim(APP_BASE_URL, '/') : '';
        
        // Fallback to auto-detect if not configured
        if (empty($baseUrl) && isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            if ($scriptDir !== '/' && $scriptDir !== '\\') {
                $baseUrl .= $scriptDir;
            }
        }
        
        $trackingUrl = $baseUrl . '/track_order.php?txn=' . urlencode($sale['transaction_id']);
        
        $trackingSection = '<div style="background:#dbeafe; border:1px solid #93c5fd; color:#1e40af; padding:12px; border-radius:8px; margin:12px 0; text-align:center;">'
            .'<p style="margin:0 0 6px 0;"><strong>Delivery Order</strong></p>'
            .'<p style="margin:0 0 8px 0; font-size:14px;">Track your order with this number:</p>'
            .'<p style="margin:0 0 10px 0; font-size:18px; font-weight:700; letter-spacing:1px;">'.htmlspecialchars($sale['transaction_id']).'</p>'
            .'<a href="'.htmlspecialchars($trackingUrl).'" style="display:inline-block; background:#2563eb; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none; font-weight:600;">Track Your Delivery</a>'
            .'</div>';
    }
    
    $rows = '';
    foreach ($items as $it) {
        $qtyParts = [];
        if ((float)$it['quantity_kg'] > 0) { $qtyParts[] = number_format((float)$it['quantity_kg'], 0) . ' kg'; }
        if ((float)$it['quantity_sack'] > 0) { $qtyParts[] = number_format((float)$it['quantity_sack'], 0) . ' sack(s)'; }
        $rows .= '<tr>'
            .'<td>'.htmlspecialchars($it['name']).'</td>'
            .'<td style="text-align:right">'.htmlspecialchars(implode(' • ', $qtyParts)).'</td>'
            .'<td style="text-align:right">Php '.number_format((float)$it['price'], 0).'</td>'
            .'</tr>';
    }
    return '<div style="font-family:Segoe UI,Arial,sans-serif; color:#111;">'
        .'<h2 style="margin:0 0 8px 0;">RicePOS E-Receipt</h2>'
        .'<p style="margin:0 0 4px 0;">Transaction: <strong>'.htmlspecialchars($sale['transaction_id']).'</strong></p>'
        .$buyerLine
        .'<p style="margin:4px 0 8px 0;">Date/Time: '.htmlspecialchars($sale['datetime']).'</p>'
        .$trackingSection
        .'<table style="width:100%; border-collapse:collapse; border-top:1px solid #ddd; border-bottom:1px solid #ddd;">'
        .'<thead><tr><th style="text-align:left; padding:6px 0;">Item</th><th style="text-align:right; padding:6px 0;">Qty</th><th style="text-align:right; padding:6px 0;">Price</th></tr></thead>'
        .'<tbody>'.$rows.'</tbody>'
        .'</table>'
        .'<p style="margin:8px 0 0 0;"><strong>Total:</strong> Php '.number_format((float)$sale['total_amount'], 0).'</p>'
        .'<p style="margin:0;"><strong>Payment:</strong> Php '.number_format((float)$sale['payment'], 0).'</p>'
        .'<p style="margin:0 0 8px 0;"><strong>Change:</strong> Php '.number_format((float)$sale['change_due'], 0).'</p>'
        .'<p style="color:#6b7280; font-size:12px;">This is for reference only, not an official receipt.</p>'
        .'</div>';
}

/**
 * Send e-receipt via SMTP (PHPMailer if available) or mail().
 */
function send_receipt_email(string $toEmail, string $subject, string $htmlBody, ?string $toName = null): bool {
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) { return false; }

    // If PHPMailer is present
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            if (strtolower(SMTP_SECURE) === 'ssl') { $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; }
            else { $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            // Optional: relax SSL on some Windows/XAMPP environments
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($toEmail, $toName ?: $toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'],"\n", $htmlBody));
            $mail->send();
            return true;
        } catch (Throwable $e) {
            // Record detailed error and fall through to mail()
            if (!isset($_SESSION)) { @session_start(); }
            $_SESSION['email_debug'] = 'SMTP send failed: ' . $e->getMessage();
            if (isset($mail) && property_exists($mail, 'ErrorInfo') && $mail->ErrorInfo) {
                $_SESSION['email_debug'] .= ' | ' . $mail->ErrorInfo;
            }
        }
    } else {
        if (!isset($_SESSION)) { @session_start(); }
        $_SESSION['email_debug'] = 'PHPMailer not found. Attempted Composer autoload and common manual paths.';
    }
    // Fallback: PHP mail()
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: '.(SMTP_FROM_NAME.' <'.SMTP_FROM_EMAIL.'>');
    $ok = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
    if (!$ok) {
        if (!isset($_SESSION)) { @session_start(); }
        $_SESSION['email_debug'] = ($_SESSION['email_debug'] ?? '') . ' | mail() fallback failed.';
    }
    return $ok;
}
