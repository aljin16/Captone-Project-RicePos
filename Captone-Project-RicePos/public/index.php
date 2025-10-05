<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
$user = new User();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($user->login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Cedric's Grain Center | RicePOS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="manifest" href="manifest.webmanifest?v=10">
    <link rel="icon" type="image/png" href="assets/img/1logo.png?v=10">
    <link rel="apple-touch-icon" href="assets/img/1logo.png?v=10">
    <meta name="theme-color" content="#2d6cdf">
    <style>
      :root{ --brand:#2d6cdf; --brand-600:#1e4fa3; --bg:#f3f6ff; --ink:#0f172a; }
      *,*::before,*::after{ box-sizing:border-box; }
      html,body{ height:100%; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
      body{
        margin:0; display:flex; align-items:center; justify-content:center; min-height:100vh;
        background-image: linear-gradient(to top, #e6e9f0 0%, #eef1f5 100%);
        color:var(--ink);
      }
      .brand-card{
        position:relative; width:100%; max-width:400px; padding:2.2rem 1.9rem; border-radius:18px;
        background: rgba(255,255,255,0.9);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(15,23,42,0.06);
        box-shadow: 0 24px 60px rgba(17,24,39,0.12), 0 6px 18px rgba(17,24,39,0.06);
      }
      .brand-card::after{
        content:''; position:absolute; inset:auto -30px -30px auto; width:140px; height:140px; border-radius:50%;
        background: radial-gradient(closest-side, rgba(45,108,223,0.18), rgba(45,108,223,0)); filter: blur(6px);
      }
      .brand-badge{
        width:68px; height:68px; border-radius:50%; display:flex; align-items:center; justify-content:center;
        background: linear-gradient(135deg, var(--brand), var(--brand-600)); color:#fff; box-shadow: 0 8px 20px rgba(45,108,223,0.35);
        border: 2px solid #fff; margin:-56px auto 12px; position:relative;
      }
      .brand-title{ font-weight:800; text-align:center; margin:0.25rem 0 0.2rem; letter-spacing:0.4px; font-size:1.35rem; color:#0f172a; }
      .brand-sub{ text-align:center; color:#475569; font-weight:600; margin-bottom:1rem; letter-spacing:0.3px; }
      .shop-icons{ display:flex; justify-content:center; align-items:center; gap:12px; margin-bottom:1rem; color:#1e40af; }
      .shop-icons .icon{ width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:#e0e7ff; border:1px solid #c7d2fe; font-size:22px; }
      .form-label{ font-weight:600; color:#334155; }
      /* Align label, icon, and input perfectly using CSS grid */
      .input-wrap{ display:grid; grid-template-columns: 44px 1fr; grid-template-rows: auto 46px; column-gap:10px; align-items:center; }
      .input-wrap .form-label{ grid-column: 1 / -1; margin-bottom:4px; }
      .input-icon{ grid-column: 1; grid-row: 2; justify-self:center; color:#94a3b8; font-size:20px; }
      .form-control{ grid-column: 2; grid-row: 2; border:1px solid #dbeafe; border-radius:12px; background:#f8fafc; padding-left:12px; height:46px; }
      .toggle-pass{ grid-column: 2; grid-row: 2; justify-self:end; margin-right:8px; background:transparent; border:none; height:46px; display:flex; align-items:center; color:#94a3b8; cursor:pointer; }
      .toggle-pass:hover{ color:#64748b; }
      .form-control:focus{ border-color:#93c5fd; box-shadow: 0 0 0 0.18rem rgba(147,197,253,0.25); }
      .login-btn{ width:100%; border-radius:10px; font-weight:700; letter-spacing:0.3px; border:1px solid var(--brand-600); }
      /* Gradient button per request */
      .btn-grad { background-image: linear-gradient(to right, #348F50 0%, #56B4D3 51%, #348F50 100%); }
      .btn-grad {
        margin: 12px 0 0 0; /* equal width alignment with inputs */
        padding: 15px 45px;
        text-align: center;
        text-transform: uppercase;
        transition: 0.5s;
        background-size: 200% auto;
        color: white;
        box-shadow: 0 0 20px #eee;
        border-radius: 10px;
        display: block;
        width: 100%;
        border: none;
      }
      .btn-grad:hover {
        background-position: right center;
        color: #fff;
        text-decoration: none;
      }
      .footer-note{ margin-top:0.75rem; text-align:center; color:#64748b; font-size:0.9rem; }
      /* Center inputs and icons as a compact column */
      .form-inner{ width:100%; max-width:360px; margin:0 auto; }
      /* Submit animation + spinner */
      .brand-card{ transition: transform .28s ease, box-shadow .28s ease, opacity .2s ease; }
      .brand-card.submitting{ transform: scale(0.985); box-shadow: 0 18px 50px rgba(17,24,39,0.10); }
      .login-btn:disabled{ cursor:not-allowed; opacity:.9; }
      .spinner{ width:16px; height:16px; border-radius:50%; border:2px solid rgba(255,255,255,0.45); border-top-color:#fff; display:inline-block; vertical-align:-3px; margin-right:8px; animation:spin .8s linear infinite; }
      @keyframes spin{ to{ transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="brand-card">
        <div class="brand-badge"><i class='bx bx-store-alt' style="font-size:34px;"></i></div>
        <h3 class="brand-title">Cedric's Grain Center</h3>
        <div class="brand-sub">RicePOS Sign In</div>
        <div class="shop-icons">
            <div class="icon" title="Quality Grains"><i class='bx bx-leaf' ></i></div>
            <div class="icon" title="Trusted Store"><i class='bx bx-badge-check' ></i></div>
            <div class="icon" title="Fast Service"><i class='bx bx-run' ></i></div>
        </div>
        <form method="post" autocomplete="off" class="form-inner">
            <div class="mb-3 input-wrap">
                <label class="form-label" for="username">Username</label>
                <i class='bx bx-user input-icon' aria-hidden="true"></i>
                <input id="username" type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
            </div>
            <div class="mb-3 input-wrap">
                <label class="form-label" for="password">Password</label>
                <i class='bx bx-lock input-icon' aria-hidden="true"></i>
                <input id="password" type="password" name="password" class="form-control" placeholder="Enter your password" required>
                <button type="button" id="togglePass" class="toggle-pass" aria-label="Show password"><i class='bx bx-show'></i></button>
            </div>
            <button type="submit" class="btn login-btn btn-grad">Sign In</button>
            <div style="text-align:center; margin-top:10px;">
              <a href="forgot_password.php" style="color:#1e40af; font-weight:600;">Forgot password?</a>
            </div>
        </form>
        <div class="footer-note">Welcome to Cedric's Grain Center</div>
    </div>
    <?php if ($error): ?>
    <script>
        Swal.fire({ icon: 'error', title: 'Login Failed', text: '<?php echo $error; ?>' });
    </script>
    <?php endif; ?>
    <script src="assets/js/main.js"></script>
</body>
</html> 
<script>
  (function(){
    var btn = document.getElementById('togglePass');
    var input = document.getElementById('password');
    if (!btn || !input) return;
    btn.addEventListener('click', function(){
      var isPwd = input.type === 'password';
      input.type = isPwd ? 'text' : 'password';
      var icon = btn.querySelector('i');
      if (icon) {
        icon.classList.remove(isPwd ? 'bx-show' : 'bx-hide');
        icon.classList.add(isPwd ? 'bx-hide' : 'bx-show');
      }
      btn.setAttribute('aria-label', isPwd ? 'Hide password' : 'Show password');
    });
  })();
  (function(){
    var form = document.querySelector('form.form-inner');
    if (!form) return;
    var submitBtn = form.querySelector('.login-btn');
    var card = document.querySelector('.brand-card');
    form.addEventListener('submit', function(){
      if (!submitBtn || submitBtn.disabled) return;
      submitBtn.disabled = true;
      submitBtn.innerHTML = "<span class='spinner'></span>Signing in…";
      if (card) card.classList.add('submitting');
      var toggle = document.getElementById('togglePass');
      if (toggle) toggle.setAttribute('disabled','disabled');
      var inputs = form.querySelectorAll('input');
      inputs.forEach(function(el){ el.readOnly = true; });
    }, { once: false });
  })();
  </script>