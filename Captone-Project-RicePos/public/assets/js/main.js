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
    
    // Floating label auto-init
    console.log('Starting floating label initialization...');
    try {
        const isInsideLoginWrap = (el) => !!el.closest('.input-wrap');
        const hasAssociatedLabel = (el) => {
            if (!el.id) return false;
            return !!document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
        };
        const humanize = (str) => {
            if (!str) return '';
            return String(str)
                .replace(/[_-]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/\b([a-z])/g, (m, c) => c.toUpperCase());
        };
        const computeBg = (el) => {
            const bg = getComputedStyle(el).backgroundColor;
            // If transparent, peek parent
            if (!bg || bg === 'transparent' || bg === 'rgba(0, 0, 0, 0)') {
                const p = el.parentElement ? getComputedStyle(el.parentElement).backgroundColor : null;
                return p && p !== 'transparent' ? p : '#fff';
            }
            return bg;
        };
        const setHasValue = (wrap, ctrl) => {
            const val = (ctrl.tagName === 'SELECT')
                ? (ctrl.value != null && String(ctrl.value).trim() !== '')
                : (ctrl.value != null && String(ctrl.value).trim() !== '');
            wrap.classList.toggle('has-value', !!val);
        };
        const wrapControl = (ctrl) => {
            if (ctrl.closest('.float-field')) return; // already wrapped
            const wrap = document.createElement('div');
            wrap.className = 'float-field';
            // Insert wrapper before control
            const parent = ctrl.parentNode;
            parent.insertBefore(wrap, ctrl);
            wrap.appendChild(ctrl);
            // Determine label text
            const lblText = ctrl.getAttribute('data-label')
                || (ctrl.placeholder && ctrl.placeholder.trim())
                || ctrl.getAttribute('aria-label')
                || humanize(ctrl.getAttribute('name'))
                || 'Field';
            // Minimize placeholder to a single space to preserve :placeholder-shown
            if (ctrl.tagName !== 'SELECT') {
                ctrl.setAttribute('placeholder', ' ');
            }
            const lbl = document.createElement('label');
            lbl.textContent = lblText;
            if (ctrl.id) lbl.setAttribute('for', ctrl.id);
            wrap.appendChild(lbl);
            // Background behind label to avoid border overlap
            wrap.style.setProperty('--float-bg', computeBg(ctrl));
            // Initial state
            setHasValue(wrap, ctrl);
            // Listen for changes
            const onEvt = () => setHasValue(wrap, ctrl);
            ctrl.addEventListener('input', onEvt);
            ctrl.addEventListener('change', onEvt);
            // Handle form reset
            const form = ctrl.form;
            if (form) {
                form.addEventListener('reset', () => {
                    // allow native reset to run
                    setTimeout(() => setHasValue(wrap, ctrl), 0);
                });
            }
        };

        const selector = [
            'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"])',
            'textarea',
            'select'
        ].join(',');

        const controls = document.querySelectorAll(selector);
        console.log(`Found ${controls.length} controls to potentially wrap`);
        
        controls.forEach((ctrl) => {
            if (ctrl.matches('.no-float') || ctrl.hasAttribute('data-no-float')) {
                console.log('Skipping control with no-float:', ctrl);
                return;
            }
            if (isInsideLoginWrap(ctrl)) {
                console.log('Skipping control inside .input-wrap:', ctrl);
                return;
            }
            if (hasAssociatedLabel(ctrl)) {
                console.log('Skipping control with existing label:', ctrl);
                return;
            }
            console.log('Wrapping control:', ctrl);
            wrapControl(ctrl);
        });
    } catch (e) {
        // Silent fail to avoid breaking pages
        console && console.warn && console.warn('Floating label init skipped:', e);
    }
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