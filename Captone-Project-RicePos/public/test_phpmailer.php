<?php
require_once __DIR__ . '/../includes/functions.php';

echo "<h2>PHPMailer Test</h2>";

// Check if PHPMailer is loaded
if (class_exists('PHPMailer')) {
    echo "✅ PHPMailer class is available<br>";
    
    // Test creating a PHPMailer instance
    try {
        $mail = new PHPMailer(true);
        echo "✅ PHPMailer instance created successfully<br>";
        echo "PHPMailer version: " . $mail->Version . "<br>";
    } catch (Exception $e) {
        echo "❌ Failed to create PHPMailer instance: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ PHPMailer class is NOT available<br>";
    
    // Debug: Check which files exist
    echo "<h3>Debug - Checking PHPMailer paths:</h3>";
    $paths = [
        __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../includes/PHPMailer/src/Exception.php',
        __DIR__ . '/../PHPMailer-master/src/Exception.php',
        __DIR__ . '/../includes/PHPMailer/src/SMTP.php',
        __DIR__ . '/../PHPMailer-master/src/SMTP.php',
    ];
    
    foreach ($paths as $path) {
        $exists = file_exists($path);
        echo ($exists ? "✅" : "❌") . " " . $path . "<br>";
    }
}

echo "<br><a href='debug_email.php'>Go to Email Debug</a>";
?>
