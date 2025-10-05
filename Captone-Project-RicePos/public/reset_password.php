<?php
session_start();
require_once __DIR__ . '/../includes/password_reset.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_POST['uid']) ? (int)$_POST['uid'] : 0);
$user = null; $error = '';$ok = false;
if ($token) {
    $user = find_user_by_reset_token($token, $uid ?: null);
    if (!$user) { $error = 'Invalid or expired reset link.'; }
} else {
    $error = 'Missing token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $pwd = $_POST['password'] ?? '';
    $pwd2 = $_POST['password2'] ?? '';
    if ($pwd !== $pwd2) { $error = 'Passwords do not match.'; }
    elseif (strlen($pwd) < 8) { $error = 'Password must be at least 8 characters.'; }
    else {
        if (update_user_password_with_reset((int)$user['id'], $pwd)) {
            $ok = true;
            // Log successful reset
            try {
                $logger = new ActivityLog();
                $logger->log([
                    'action' => 'password_reset_completed',
                    'details' => 'Completed for user ID ' . (int)$user['id'],
                ]);
            } catch (Throwable $e) { /* ignore */ }
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - RicePOS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f1f5f9;margin:0}
    .card{width:100%;max-width:420px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 16px 40px rgba(17,24,39,.12);padding:22px}
    .form-control{height:46px;border-radius:12px;background:#f8fafc;border:1px solid #dbeafe}
    .btn-primary{width:100%;border-radius:10px}
  </style>
</head>
<body>
  <div class="card">
    <h3>Reset your password</h3>
    <?php if ($user && !$ok): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="uid" value="<?php echo (int)$uid; ?>">
        <div class="mb-3">
          <label for="password" class="form-label">New password</label>
          <input id="password" type="password" name="password" class="form-control" minlength="8" required>
        </div>
        <div class="mb-3">
          <label for="password2" class="form-label">Confirm new password</label>
          <input id="password2" type="password" name="password2" class="form-control" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary">Update password</button>
        <div style="margin-top:12px"><a href="index.php">Back to sign in</a></div>
      </form>
    <?php elseif ($ok): ?>
      <div class="alert alert-success">Your password has been updated. You can now <a href="index.php">sign in</a>.</div>
    <?php else: ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
      <div><a href="forgot_password.php">Request a new reset link</a></div>
    <?php endif; ?>
  </div>

  <?php if ($error && $user && !$ok): ?>
  <script>
    Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo htmlspecialchars($error, ENT_QUOTES); ?>' });
  </script>
  <?php endif; ?>
</body>
</html>


