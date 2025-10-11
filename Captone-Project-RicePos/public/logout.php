<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - RicePOS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to top, #09203f 0%, #537895 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-container {
            text-align: center;
            color: white;
            padding: 2rem;
        }
        .logout-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-icon">🔒</div>
        <h1>Logging out...</h1>
        <p>Please wait while we securely sign you out.</p>
    </div>
    
    <script>
    // Show a more elegant logout confirmation
    Swal.fire({
        title: 'Sign Out',
        text: 'Are you sure you want to logout from your account?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#2b5876',
        confirmButtonText: '<i class="bx bx-log-out"></i> Yes, Logout',
        cancelButtonText: '<i class="bx bx-x"></i> Cancel',
        reverseButtons: true,
        customClass: {
            popup: 'swal-logout-popup',
            confirmButton: 'swal-confirm-btn',
            cancelButton: 'swal-cancel-btn'
        },
        showClass: {
            popup: 'animate__animated animate__fadeInDown'
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOutUp'
        },
        allowOutsideClick: false,
        allowEscapeKey: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading state
            Swal.fire({
                title: 'Logging out...',
                text: 'Please wait while we sign you out',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Redirect to logout confirmation
            setTimeout(() => {
                window.location.href = 'logout_confirm.php';
            }, 1000);
        } else if (result.isDismissed) {
            // Go back to previous page
            window.history.back();
        }
    });

    // Add custom styles for SweetAlert
    const style = document.createElement('style');
    style.textContent = `
        .swal-logout-popup {
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .swal-confirm-btn {
            border-radius: 10px !important;
            font-weight: 700 !important;
            padding: 12px 28px !important;
            transition: all 0.3s ease !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem !important;
        }
        
        .swal-confirm-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4) !important;
        }
        
        .swal-cancel-btn {
            border-radius: 10px !important;
            font-weight: 700 !important;
            padding: 12px 28px !important;
            transition: all 0.3s ease !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem !important;
            background: linear-gradient(to right, #2b5876 0%, #4e4376 51%, #2b5876 100%) !important;
            background-size: 200% auto !important;
            border: none !important;
        }
        
        .swal-cancel-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 16px rgba(43, 88, 118, 0.5) !important;
            background-position: right center !important;
        }
        
        .swal-cancel-btn:focus {
            box-shadow: 0 0 0 3px rgba(43, 88, 118, 0.3) !important;
        }
        
        .swal2-popup {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .swal2-icon.swal2-question {
            border-color: #2b5876 !important;
            color: #2b5876 !important;
        }
    `;
    document.head.appendChild(style);
    </script>
</body>
</html> 