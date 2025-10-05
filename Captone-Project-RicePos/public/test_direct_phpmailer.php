<?php
// Direct PHPMailer test - manually include files
echo "<h2>Direct PHPMailer Test</h2>";

// Try to include PHPMailer files directly
$phpMailerPath = __DIR__ . '/../PHPMailer-master/src/';

if (file_exists($phpMailerPath . 'Exception.php') && 
    file_exists($phpMailerPath . 'PHPMailer.php') && 
    file_exists($phpMailerPath . 'SMTP.php')) {
    
    echo "✅ PHPMailer files found at: " . $phpMailerPath . "<br>";
    
    try {
        require_once $phpMailerPath . 'Exception.php';
        require_once $phpMailerPath . 'PHPMailer.php';
        require_once $phpMailerPath . 'SMTP.php';
        
        echo "✅ PHPMailer files included successfully<br>";
        
        if (class_exists('PHPMailer')) {
            echo "✅ PHPMailer class is now available<br>";
            
            // Test creating instance
            $mail = new PHPMailer(true);
            echo "✅ PHPMailer instance created successfully<br>";
            echo "PHPMailer version: " . $mail->Version . "<br>";
            
            // Test SMTP configuration
            echo "<h3>Testing SMTP Configuration:</h3>";
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->Port = 587;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            $mail->Username = 'aljeansinohin05@gmail.com';
            $mail->Password = 'vdxnekbnqyxpaweq';
            
            echo "✅ SMTP configuration set successfully<br>";
            
        } else {
            echo "❌ PHPMailer class still not available after including files<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Error including PHPMailer files: " . $e->getMessage() . "<br>";
    }
    
} else {
    echo "❌ PHPMailer files not found at: " . $phpMailerPath . "<br>";
    
    // List what files exist
    echo "<h3>Files in PHPMailer directory:</h3>";
    if (is_dir($phpMailerPath)) {
        $files = scandir($phpMailerPath);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "📄 " . $file . "<br>";
            }
        }
    } else {
        echo "Directory does not exist: " . $phpMailerPath . "<br>";
    }
}

echo "<br><a href='debug_email.php'>Go to Email Debug</a>";
?>
