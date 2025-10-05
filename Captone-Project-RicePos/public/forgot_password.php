<?php
session_start();
require_once __DIR__ . '/../includes/functions.php'; // for send_receipt_email()
require_once __DIR__ . '/../includes/password_reset.php';
require_once __DIR__ . '/../includes/config.php';

$status = null; $message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    // Throttle tracking
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    record_reset_request_attempt($email, $ip);

    $created = null;
    if (!too_many_reset_requests($email, $ip)) {
        $created = create_password_reset_for_email($email, 3600);
    }
    // Generic response
    $status = 'ok';

    if ($created) {
        [$token, $userId] = $created;
        $autoBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
        $baseUrl = (defined('APP_BASE_URL') && APP_BASE_URL) ? rtrim(APP_BASE_URL, '/\\') : $autoBase;
        $resetUrl = $baseUrl . '/reset_password.php?token=' . urlencode($token) . '&uid=' . urlencode((string)$userId);

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
        // Security: avoid leaking exact existence via bounces; we still send but log masking
        @send_receipt_email($email, $brandName.' Password Reset', $html, null);

        // Log event
        try {
            $logger = new ActivityLog();
            $logger->log([
                'action' => 'password_reset_requested',
                'details' => 'Requested for ' . mask_email_for_log($email),
            ]);
        } catch (Throwable $e) { /* ignore */ }
    } else {
        // Log throttled/no-send
        try {
            $logger = new ActivityLog();
            $logger->log([
                'action' => 'password_reset_request_ignored',
                'details' => 'Ignored or throttled for ' . mask_email_for_log($email),
            ]);
        } catch (Throwable $e) { /* ignore */ }
    }
    $message = 'If an account exists for that email, a reset link has been sent.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - RicePOS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#eef2ff;margin:0}
    .card{width:100%;max-width:420px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 16px 40px rgba(17,24,39,.12);padding:22px}
    .title{font-weight:800;margin:6px 0 2px}
    .sub{color:#64748b;margin-bottom:12px}
    .form-control{height:46px;border-radius:12px;background:#f8fafc;border:1px solid #dbeafe}
    .btn-primary{width:100%;border-radius:10px}
    a{ text-decoration:none }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  </head>
<body>
  <div class="card">
    <h3 class="title">Forgot your password?</h3>
    <div class="sub">Enter your registered email. We'll send a reset link if the account exists.</div>
    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input id="email" type="email" class="form-control" name="email" placeholder="you@example.com" required>
      </div>
      <button type="submit" class="btn btn-primary">Send reset link</button>
    </form>
    <div style="margin-top:12px"><a href="index.php">Back to sign in</a></div>
  </div>

  <?php if ($status === 'ok'): ?>
  <script>
    Swal.fire({ icon: 'success', title: 'Check your email', text: '<?php echo htmlspecialchars($message, ENT_QUOTES); ?>' });
  </script>
  <?php endif; ?>
</body>
</html>


