<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/password_reset.php';
require_once __DIR__ . '/../includes/config.php';

echo "<h2>Password Reset Debug</h2>";

// Test email
$testEmail = "test@example.com";

echo "<h3>Testing Password Reset Process:</h3>";

// Check if user exists
$stmt = $pdo->prepare('SELECT id, email, status FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$testEmail]);
$user = $stmt->fetch();

if ($user) {
    echo "✅ User found: ID=" . $user['id'] . ", Status=" . $user['status'] . "<br>";
} else {
    echo "❌ User not found for email: " . $testEmail . "<br>";
    echo "Creating test user...<br>";
    try {
        create_user('testuser', $testEmail, 'password123', 'staff', 'active');
        echo "✅ Test user created<br>";
    } catch (Exception $e) {
        echo "❌ Failed to create test user: " . $e->getMessage() . "<br>";
    }
}

// Test throttling
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
echo "IP: " . $ip . "<br>";

$isThrottled = too_many_reset_requests($testEmail, $ip);
if ($isThrottled) {
    echo "❌ Request is throttled<br>";
} else {
    echo "✅ Request is not throttled<br>";
}

// Test token creation
echo "<h3>Testing Token Creation:</h3>";
$created = create_password_reset_for_email($testEmail, 3600);
if ($created) {
    [$token, $userId] = $created;
    echo "✅ Token created successfully<br>";
    echo "User ID: " . $userId . "<br>";
    echo "Token (first 10 chars): " . substr($token, 0, 10) . "...<br>";
    
    // Test URL generation
    $autoBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    $baseUrl = (defined('APP_BASE_URL') && APP_BASE_URL) ? rtrim(APP_BASE_URL, '/\\') : $autoBase;
    $resetUrl = $baseUrl . '/reset_password.php?token=' . urlencode($token) . '&uid=' . urlencode((string)$userId);
    
    echo "Generated URL: " . $resetUrl . "<br>";
    
    // Test email sending
    echo "<h3>Testing Email Sending:</h3>";
    $brandName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'RicePOS';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0f172a">'
      .'<div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">'
      .'<div style="background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:14px 16px;font-weight:700">'.htmlspecialchars($brandName).' - Password Reset</div>'
      .'<div style="padding:16px">'
      .'<p>Hello,</p>'
      .'<p>We received a request to reset your password for your '.htmlspecialchars($brandName).' account.</p>'
      .'<p style="margin:16px 0;"><a href="'.htmlspecialchars($resetUrl).'" style="background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px;display:inline-block">Reset Password</a></p>'
      .'<p>If the button does not work, copy and paste this link:</p>'
      .'<p style="word-break:break-all;color:#111">'.htmlspecialchars($resetUrl).'</p>'
      .'<p style="color:#6b7280;font-size:12px;margin-top:16px">This link expires in 60 minutes. If you did not request a password reset, you can ignore this message.</p>'
      .'</div>'
      .'</div>'
      .'<div style="color:#6b7280;font-size:12px;margin-top:8px">Sent from '.htmlspecialchars($brandName).'</div>'
      .'</div>';
    
    $emailResult = send_receipt_email($testEmail, $brandName.' Password Reset', $html, null);
    if ($emailResult) {
        echo "✅ Email sent successfully<br>";
    } else {
        echo "❌ Email failed to send<br>";
        if (isset($_SESSION['email_debug'])) {
            echo "Debug info: " . $_SESSION['email_debug'] . "<br>";
            unset($_SESSION['email_debug']);
        }
    }
    
} else {
    echo "❌ Failed to create token<br>";
}

echo "<br><a href='forgot_password.php'>Go to Forgot Password Page</a> | <a href='debug_email.php'>Email Debug</a>";
?>
