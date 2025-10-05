<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

echo "<h2>Email Debug Information</h2>";

// Check if PHPMailer is loaded
echo "<h3>PHPMailer Status:</h3>";
if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    echo "✅ PHPMailer class is available (namespaced)<br>";
} else {
    echo "❌ PHPMailer\\PHPMailer\\PHPMailer class is NOT available<br>";
}

// Check SMTP configuration
echo "<h3>SMTP Configuration:</h3>";
echo "Host: " . SMTP_HOST . "<br>";
echo "Port: " . SMTP_PORT . "<br>";
echo "Secure: " . SMTP_SECURE . "<br>";
echo "Username: " . SMTP_USERNAME . "<br>";
echo "Password: " . (SMTP_PASSWORD ? "***SET***" : "NOT SET") . "<br>";
echo "From Email: " . SMTP_FROM_EMAIL . "<br>";
echo "From Name: " . SMTP_FROM_NAME . "<br>";

// Show APP_BASE_URL and auto-detected base
echo "<h3>Base URL:</h3>";
$autoBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
echo "APP_BASE_URL: " . (defined('APP_BASE_URL') ? APP_BASE_URL : '(not defined)') . "<br>";
echo "Auto-detected: " . $autoBase . "<br>";

// Test email sending
echo "<h3>Email Test:</h3>";
$testEmail = "test@example.com";
$html = '<strong>Test Email</strong><br>Date: ' . date('Y-m-d H:i:s');

try {
    $result = send_receipt_email($testEmail, 'Test Subject', $html);
    if ($result) {
        echo "✅ Email function returned true<br>";
    } else {
        echo "❌ Email function returned false<br>";
    }
} catch (Exception $e) {
    echo "❌ Email function threw exception: " . $e->getMessage() . "<br>";
}

// Check for debug messages
if (isset($_SESSION['email_debug'])) {
    echo "<h3>Debug Messages:</h3>";
    echo "<pre>" . htmlspecialchars($_SESSION['email_debug']) . "</pre>";
    unset($_SESSION['email_debug']);
}

echo "<br><a href='email_test.php'>Go to Email Test Page</a>";
?>
