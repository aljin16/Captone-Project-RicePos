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
    <meta name="theme-color" content="#F2F0EF">
    <style>
      :root{ 
        --brand-primary: #2b5876;
        --brand-secondary: #4e4376;
        --brand-accent: #3d5a80;
        --success: #10b981;
        --text-dark: #1e293b;
        --text-light: #64748b;
        --white: #ffffff;
      }
      
      *,*::before,*::after{ 
        box-sizing:border-box; 
        margin: 0;
        padding: 0;
      }
      
      html,body{ 
        height:100%; 
        -webkit-font-smoothing: antialiased; 
        -moz-osx-font-smoothing: grayscale;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      }
      
      body{
        margin:0; 
        display:flex; 
        align-items:center; 
        justify-content:center; 
        min-height:100vh;
        background: #F2F0EF;
        padding: 20px;
        position: relative;
        overflow: hidden;
      }
      
      /* Animated background elements */
      body::before {
        content: '';
        position: absolute;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(54, 209, 220, 0.05) 0%, transparent 70%);
        top: -250px;
        left: -250px;
        animation: float 20s ease-in-out infinite;
      }
      
      body::after {
        content: '';
        position: absolute;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(91, 134, 229, 0.04) 0%, transparent 70%);
        bottom: -200px;
        right: -200px;
        animation: float 15s ease-in-out infinite reverse;
      }
      
      @keyframes float {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(30px, -30px) scale(1.1); }
        66% { transform: translate(-20px, 20px) scale(0.9); }
      }
      
      .brand-card{
        position: relative;
        width: 100%;
        max-width: 380px;
        padding: 2rem 2rem 1.75rem;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        box-shadow: 
          0 20px 60px rgba(0, 0, 0, 0.3),
          0 10px 30px rgba(0, 0, 0, 0.2),
          inset 0 1px 0 rgba(255, 255, 255, 0.4);
        animation: cardEntry 0.6s ease-out;
        z-index: 10;
      }
      
      @keyframes cardEntry {
        from {
          opacity: 0;
          transform: translateY(30px) scale(0.95);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }
      
      /* Logo Badge */
      .brand-badge{
        width: 90px;
        height: 90px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        position: relative;
        animation: badgePulse 2s ease-in-out infinite;
      }
      
      @keyframes badgePulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
      }
      
      .brand-badge img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.15));
      }
      
      /* Typography */
      .brand-title{
        font-weight: 800;
        text-align: center;
        margin: 0 0 0.35rem;
        font-size: 1.4rem;
        background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.5px;
      }
      
      .brand-sub{
        text-align: center;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 1.25rem;
        font-size: 0.9rem;
        letter-spacing: 0.3px;
      }
      
      /* Feature Icons */
      .shop-icons{
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);
      }
      
      .shop-icons .icon{
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #e8eef3 0%, #dde5ed 100%);
        border: 1px solid rgba(43, 88, 118, 0.2);
        font-size: 20px;
        color: #2b5876;
        transition: all 0.3s ease;
        cursor: pointer;
      }
      
      .shop-icons .icon:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 8px 20px rgba(43, 88, 118, 0.3);
        background: linear-gradient(135deg, #d3dde6 0%, #c7d5e0 100%);
        color: #1e3a52;
      }
      
      /* Form Styling */
      .form-inner{
        width: 100%;
        margin: 0 auto;
      }
      
      .input-group {
        position: relative;
        margin-bottom: 1.15rem;
      }
      
      .form-label{
        display: block;
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.4rem;
        font-size: 0.825rem;
        letter-spacing: 0.3px;
      }
      
      .input-wrapper {
        position: relative;
        display: block;
        width: 100%;
      }
      
      .input-icon{
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 19px;
        transition: color 0.3s ease;
        z-index: 3;
        pointer-events: none;
      }
      
      .form-control{
        width: 100%;
        height: 46px;
        padding: 0 44px 0 44px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        font-size: 0.9rem;
        color: #1e293b;
        transition: all 0.3s ease;
        outline: none;
      }
      
      .form-control:focus{
        border-color: #36D1DC;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(54, 209, 220, 0.15);
      }
      
      /* Icon color changes when input is focused */
      .input-wrapper:focus-within .input-icon {
        color: #36D1DC;
      }
      
      .toggle-pass{
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: transparent;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 6px;
        border-radius: 6px;
        transition: all 0.3s ease;
        z-index: 3;
        font-size: 18px;
      }
      
      .toggle-pass:hover{
        color: #36D1DC;
        background: rgba(54, 209, 220, 0.1);
      }
      
      .toggle-pass i {
        display: flex;
        align-items: center;
        justify-content: center;
      }
      
      /* Button Styling */
      .btn-grad {
        width: 100%;
        height: 46px;
        margin: 10px 0;
        padding: 15px 45px;
        border: none;
        border-radius: 10px;
        background-image: linear-gradient(to right, #36D1DC 0%, #5B86E5 51%, #36D1DC 100%);
        background-size: 200% auto;
        color: white;
        font-weight: 700;
        font-size: 0.9rem;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        cursor: pointer;
        transition: 0.5s;
        box-shadow: 0 0 20px #eee;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
      }
      
      .btn-grad:hover {
        background-position: right center;
        color: #fff;
        text-decoration: none;
      }
      
      .btn-grad:active {
        transform: scale(0.98);
      }
      
      .btn-grad span {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      
      .forgot-link {
        display: block;
        text-align: center;
        margin-top: 1rem;
        color: #36D1DC;
        font-weight: 600;
        text-decoration: none;
        font-size: 0.85rem;
        transition: color 0.3s ease;
      }
      
      .forgot-link:hover {
        color: #5B86E5;
        text-decoration: underline;
      }
      
      /* Submit Animation */
      .brand-card.submitting{
        transform: scale(0.99);
        opacity: 0.9;
      }
      
      .btn-grad:disabled{
        cursor: not-allowed;
        opacity: 0.7;
        transform: none !important;
      }
      
      .spinner{
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top-color: #fff;
        display: inline-block;
        vertical-align: middle;
        margin-right: 8px;
        animation: spin 0.7s linear infinite;
      }
      
      @keyframes spin{
        to{ transform: rotate(360deg); }
      }
      
      /* Responsive Design */
      @media (max-width: 480px) {
        .brand-card {
          padding: 1.75rem 1.5rem 1.5rem;
          max-width: 100%;
        }
        
        .brand-title {
          font-size: 1.25rem;
        }
        
        .brand-sub {
          font-size: 0.85rem;
        }
        
        .shop-icons .icon {
          width: 38px;
          height: 38px;
          font-size: 18px;
        }
        
        .form-control {
          height: 44px;
          font-size: 0.875rem;
        }
        
        .btn-grad {
          height: 44px;
          font-size: 0.85rem;
        }
      }
    </style>
</head>
<body>
    <div class="brand-card">
        <div class="brand-badge"><img src="assets/img/logoshop-removebg-preview.png" alt="Cedric's Grain Center Logo"></div>
        <h3 class="brand-title">Cedric's Grain Center</h3>
        <div class="brand-sub">RicePOS Sign In</div>
        <div class="shop-icons">
            <div class="icon" title="Quality Grains"><i class='bx bx-leaf'></i></div>
            <div class="icon" title="Trusted Store"><i class='bx bx-badge-check'></i></div>
            <div class="icon" title="Fast Service"><i class='bx bx-run'></i></div>
        </div>
        <form method="post" autocomplete="off" class="form-inner">
            <div class="input-group">
                <label class="form-label" for="username">Username</label>
                <div class="input-wrapper">
                <i class='bx bx-user input-icon' aria-hidden="true"></i>
                <input id="username" type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
            </div>
            </div>
            <div class="input-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                <i class='bx bx-lock input-icon' aria-hidden="true"></i>
                <input id="password" type="password" name="password" class="form-control" placeholder="Enter your password" required>
                <button type="button" id="togglePass" class="toggle-pass" aria-label="Show password"><i class='bx bx-show'></i></button>
            </div>
            </div>
            <button type="submit" class="btn login-btn btn-grad"><span>Sign In</span></button>
            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
        </form>
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
  // Toggle password visibility
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
  
  // Update icon color when input has value
  (function(){
    var inputs = document.querySelectorAll('.form-control');
    inputs.forEach(function(input){
      input.addEventListener('input', function(){
        var wrapper = input.closest('.input-wrapper');
        var icon = wrapper ? wrapper.querySelector('.input-icon') : null;
        if (icon) {
          if (input.value.length > 0) {
            icon.style.color = '#36D1DC';
          } else {
            icon.style.color = '';
          }
        }
      });
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
      submitBtn.innerHTML = "<span class='spinner'></span><span>Signing in…</span>";
      if (card) card.classList.add('submitting');
      var toggle = document.getElementById('togglePass');
      if (toggle) toggle.setAttribute('disabled','disabled');
      var inputs = form.querySelectorAll('input');
      inputs.forEach(function(el){ el.readOnly = true; });
    }, { once: false });
  })();
  </script>