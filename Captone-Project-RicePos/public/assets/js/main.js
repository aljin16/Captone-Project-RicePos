// main.js - for future interactivity

// Modern logout confirmation function
function confirmLogout() {
    Swal.fire({
        title: 'Sign Out',
        text: 'Are you sure you want to logout from your account?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
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
        }
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
        }
    });
}

// Add custom styles for SweetAlert
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        .swal-logout-popup {
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .swal-confirm-btn {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 12px 24px !important;
            transition: all 0.3s ease !important;
        }
        
        .swal-confirm-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3) !important;
        }
        
        .swal-cancel-btn {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 12px 24px !important;
            transition: all 0.3s ease !important;
        }
        
        .swal-cancel-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3) !important;
        }
        
        .swal2-popup {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
    `;
    document.head.appendChild(style);
    // Inject manifest and theme-color once
    try {
        if (!document.querySelector('link[rel="manifest"]')) {
            const link = document.createElement('link');
            link.rel = 'manifest';
            link.href = 'manifest.webmanifest';
            document.head.appendChild(link);
        }
        if (!document.querySelector('meta[name="theme-color"]')) {
            const meta = document.createElement('meta');
            meta.name = 'theme-color';
            meta.content = '#2d6cdf';
            document.head.appendChild(meta);
        }
    } catch {}
    
});

// Register service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('service-worker.js').catch(function(){});
    });
}

// PWA install prompt (custom button for browsers like Brave)
(function(){
    let deferredPrompt = null;
    function ensureInstallButton(){
        if (document.getElementById('pwaInstallBtn')) return document.getElementById('pwaInstallBtn');
        const btn = document.createElement('button');
        btn.id = 'pwaInstallBtn';
        btn.type = 'button';
        btn.textContent = 'Install RicePOS';
        btn.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;background:#2d6cdf;color:#fff;border:none;border-radius:999px;padding:10px 14px;font-weight:800;box-shadow:0 6px 20px rgba(0,0,0,0.18);display:none;cursor:pointer;letter-spacing:.3px;';
        btn.addEventListener('click', async function(){
            if (!deferredPrompt) { btn.style.display = 'none'; return; }
            deferredPrompt.prompt();
            try { await deferredPrompt.userChoice; } catch(e){}
            deferredPrompt = null; btn.style.display = 'none';
        });
        document.body.appendChild(btn);
        return btn;
    }
    window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault();
        deferredPrompt = e;
        const btn = ensureInstallButton();
        btn.style.display = 'inline-block';
    });
    window.addEventListener('appinstalled', function(){
        const btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.style.display = 'none';
        deferredPrompt = null;
    });
})();