<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

$to = isset($_GET['to']) ? trim($_GET['to']) : '';
$result = null; $msg = '';
if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $html = '<strong>RicePOS SMTP Test</strong><br>Date: '.date('Y-m-d H:i:s');
    $ok = send_receipt_email($to, 'RicePOS SMTP Test', $html, $to);
    $result = $ok ? 'success' : 'failed';
    $msg = isset($_SESSION['email_debug']) ? $_SESSION['email_debug'] : '';
    unset($_SESSION['email_debug']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Email Test - RicePOS</title>
  <style>
    body { font-family: Segoe UI, Arial, sans-serif; padding: 20px; }
    .card { max-width: 520px; border:1px solid #e5e7eb; border-radius: 10px; padding: 16px; }
    .row { margin-bottom: 8px; }
    input[type="email"] { padding:8px; border:1px solid #d1d5db; border-radius:8px; width:100%; }
    button { padding: 8px 12px; border:1px solid #d1d5db; border-radius:8px; background:#fff; cursor:pointer; }
    .ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:8px 10px; border-radius:8px; }
    .err { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:8px 10px; border-radius:8px; }
  </style>
  </head>
<body>
  <div class="card">
    <h3>RicePOS Email Test</h3>
    <form method="get">
      <div class="row"><input type="email" name="to" placeholder="Your email address" value="<?php echo htmlspecialchars($to); ?>" required></div>
      <button type="submit">Send Test Email</button>
    </form>
    <?php if ($result): ?>
      <div class="row" style="margin-top:10px;">
        <div class="<?php echo $result==='success' ? 'ok' : 'err'; ?>">
          Result: <?php echo htmlspecialchars($result); ?>
        </div>
      </div>
      <?php if ($msg): ?>
      <div class="row">
        <div class="err">Debug: <?php echo htmlspecialchars($msg); ?></div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="row" style="margin-top:10px; font-size: 12px; color:#6b7280;">
      Uses current SMTP settings in <code>includes/config.php</code>. For Gmail, use an App Password.
    </div>
  </div>
</body>
</html>


