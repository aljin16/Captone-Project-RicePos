<?php
// Shared fixed top header with centered page title
// Usage: set $pageTitle (optional) and $activePage (already used by sidebar) before including this file

$computedTitle = 'RicePOS';
if (isset($pageTitle) && trim((string)$pageTitle) !== '') {
    $computedTitle = (string)$pageTitle;
} else if (isset($activePage) && is_string($activePage)) {
    $map = [
        'dashboard.php' => 'Dashboard',
        'recent_sales.php' => 'Recent Sales',
        'suppliers.php' => 'Supplier Management',
        'inventory.php' => 'Inventory Management',
        'inventory_logs.php' => 'Inventory Activity Logs',
        'pos.php' => 'Point of Sale',
        'delivery.php' => 'Delivery',
        'delivery_management.php' => 'Delivery Management',
        'users.php' => 'User Management',
        'products.php' => 'Product Management',
    ];
    if (isset($map[$activePage])) {
        $computedTitle = $map[$activePage];
    } else {
        $t = preg_replace('/\.php$/i', '', $activePage);
        $t = str_replace(['_', '-'], ' ', (string)$t);
        $computedTitle = ucwords($t);
    }
}
?>
<header class="app-header" role="banner" aria-label="Page header">
    <!-- left: mobile menu button -->
    <div class="app-header-left">
        <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
            <i class='bx bx-menu'></i>
        </button>
    </div>
    <!-- center: page title -->
    <div class="app-header-title" aria-live="polite"><?php echo htmlspecialchars($computedTitle, ENT_QUOTES); ?></div>
    <!-- right: user profile + notifications (all pages) -->
    <div class="app-header-right">
        <?php $__isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; $__isDelivery = isset($_SESSION['role']) && $_SESSION['role'] === 'delivery_staff'; if ($__isAdmin || $__isDelivery): ?>
        <div class="notif-wrap" aria-live="polite">
            <button type="button" id="notifBell" class="notif-bell" aria-expanded="false" aria-controls="notifDropdown" title="Notifications">
                <i class='bx bx-bell'></i>
                <span id="notifBadge" class="notif-badge" hidden>0</span>
            </button>
            <div id="notifDropdown" class="notif-dropdown" hidden>
                <div class="notif-head">
                    <strong>Recent Activity</strong>
                    <div class="notif-controls">
                        <!-- Volume controls removed; sound fixed at 100% -->
                        <button type="button" id="notifSoundToggle" class="notif-action" title="Toggle notification sound">Sound On</button>
                        <button type="button" id="notifMarkRead" class="notif-action">Mark all read</button>
                    </div>
                </div>
                <div id="notifList" class="notif-list" role="listbox" aria-label="Recent activity items"></div>
                <?php if ($__isAdmin): ?>
                <div class="notif-foot"><a href="inventory_logs.php">View all logs</a></div>
                <?php else: ?>
                <div class="notif-foot"><a href="delivery_staff.php">View my deliveries</a></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['username'])): 
            $u = $_SESSION['username'];
            $r = $_SESSION['role'] ?? 'staff';
            $initials = strtoupper(substr($u,0,1));
        ?>
        <div class="user-chip" title="Signed in as <?php echo htmlspecialchars($u.' ('.$r.')'); ?>">
            <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></div>
            <div class="meta">
                <div class="name"><?php echo htmlspecialchars($u); ?></div>
                <div class="sub">Role: <?php echo htmlspecialchars($r); ?> · Status: <span class="status-badge">Active</span></div>
            </div>
            <button type="button" class="logout-btn" onclick="confirmLogout()"><i class='bx bx-log-out'></i> Logout</button>
        </div>
        <?php endif; ?>
    </div>
    <style>
        /* Minimal safety if CSS file fails to load */
        :root{ --sidebar-width:260px; --header-height:72px; }
        .app-header { position: fixed; top: 0; left: var(--sidebar-width, 260px); width: calc(100% - var(--sidebar-width, 260px)); height: var(--header-height, 72px); background: rgba(255,255,255,0.95); backdrop-filter: none; -webkit-backdrop-filter: none; border-bottom:1px solid rgba(0,0,0,0.08); z-index: 120; display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; padding: 0 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.06); box-sizing: border-box; }
        .main-content { margin-left: var(--sidebar-width, 260px); padding-top: var(--header-height, 72px); min-height: 100vh; width: auto; max-width: 100%; }
        .app-header-title { font-weight: 900; letter-spacing: 0.4px; color: #0f172a; text-align:center; font-size: 1.5rem; }
        .app-header-right { display:flex; justify-content:flex-end; align-items:center; padding-right: 0; }
        .app-header-left { display:flex; align-items:center; }
        .notif-wrap { position: relative; margin-right: 14px; }
        .notif-bell { position: relative; border:1px solid #e2e8f0; background:rgba(255,255,255,0.9); border-radius:12px; width:44px; height:44px; display:grid; place-items:center; cursor:pointer; transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.1s ease; }
        .notif-bell i { font-size: 24px; color:#0f172a; }
        .notif-bell:hover { background:#f8fafc; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .notif-bell:active { transform: translateY(1px); }
        .notif-bell.has-unseen::after { content: ""; position:absolute; inset:-4px; border-radius:16px; border:2px solid rgba(239,68,68,0.35); animation: ring 1.2s ease-out infinite; pointer-events:none; }
        @keyframes ring { 0% { transform: scale(0.9); opacity: 1; } 100% { transform: scale(1.1); opacity: 0; } }
        .notif-badge { position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; border-radius:999px; font-weight:800; font-size: 12px; line-height:1; padding:5px 7px; border:2px solid #fff; }
        .notif-dropdown { position:absolute; top:50px; right:0; width:360px; background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 16px 48px rgba(0,0,0,0.10); overflow:hidden; z-index: 300; }
        .notif-head { display:flex; align-items:center; justify-content:space-between; padding:10px 12px; background:linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); border-bottom:1px solid #e6eaf7; }
        .notif-action { border:1px solid #c7d2fe; background:#eef2ff; color:#1e40af; font-weight:700; cursor:pointer; padding:6px 10px; border-radius:8px; font-size: 12px; }
        .notif-controls { display:flex; align-items:center; gap:10px; }
        .notif-vol { display:flex; align-items:center; gap:8px; font-size: 12px; color:#475569; padding:4px 6px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; }
        .notif-vol input[type="range"] { width: 130px; accent-color: #2563eb; }
        .notif-list { max-height: 360px; overflow:auto; }
        .notif-item { display:flex; gap:12px; padding:12px; border-bottom:1px dashed #eef2f7; cursor: pointer; transition: background .15s ease, border-left-color .2s ease; border-left: 4px solid transparent; }
        .notif-item:hover { background:#f8fafc; }
        .notif-item.read { background:#f3f4f6; }
        .notif-item:last-child { border-bottom:none; }
        .notif-emoji { font-size:20px; line-height:1; }
        .notif-meta { display:flex; flex-direction:column; }
        .notif-title { font-weight:700; color:#111827; font-size: 0.96rem; letter-spacing: 0.2px; }
        .notif-sub { font-size: 0.8rem; color:#6b7280; }
        .notif-foot { padding:10px 12px; text-align:center; background:#f8fafc; border-top:1px solid #eef2f7; }
        .notif-item.a-product_add { border-left-color:#16a34a; }
        .notif-item.a-product_update { border-left-color:#f59e0b; }
        .notif-item.a-product_delete { border-left-color:#ef4444; }
        .notif-item.a-stock_update { border-left-color:#06b6d4; }
        .notif-item.a-stock_sale { border-left-color:#3b82f6; }
        .notif-item.a-product_activate { border-left-color:#22c55e; }
        .notif-item.a-product_hide { border-left-color:#64748b; }
        .user-chip { display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid #e2e8f0; border-radius: 12px; background:rgba(255,255,255,0.9); backdrop-filter: none; -webkit-backdrop-filter: none; transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.1s ease; }
        .user-chip .avatar { width:32px; height:32px; border-radius: 50%; background:#2563eb; color:#fff; display:grid; place-items:center; font-weight:800; font-size: 0.95rem; }
        .user-chip .meta { display:flex; flex-direction:column; line-height:1.15; }
        .user-chip .name { font-weight:800; color:#0f172a; font-size: 0.9rem; }
        .user-chip .sub { font-size: 0.72rem; color:#64748b; }
        .user-chip .status-badge { color:#16a34a; font-weight:800; }
        .logout-btn { border:1px solid #ef4444; color:#ef4444; background:rgba(255,255,255,0.9); border-radius:10px; padding:8px 12px; font-weight:600; display:inline-flex; align-items:center; gap:6px; font-size: 0.85rem; transition: all 0.2s ease; }
        .logout-btn:hover { background:#fef2f2; }
        
        /* Mobile Responsive Header Styles */
        @media (max-width: 768px) {
            .app-header-right { gap: 0.5rem; }
            .notif-wrap { margin-right: 8px; }
            .notif-bell { width: 40px; height: 40px; }
            .notif-bell i { font-size: 20px; }
            .notif-dropdown { width: 320px; right: -10px; }
            .user-chip { padding: 6px 8px; gap: 8px; }
            .user-chip .avatar { width: 28px; height: 28px; font-size: 0.85rem; }
            .user-chip .name { font-size: 0.85rem; }
            .user-chip .sub { font-size: 0.68rem; }
            .user-chip .meta { display: none; } /* Hide on very small screens */
            .logout-btn { padding: 6px 10px; font-size: 0.8rem; }
        }
        
        @media (max-width: 640px) {
            .notif-wrap { margin-right: 6px; }
            .notif-bell { width: 38px; height: 38px; }
            .notif-bell i { font-size: 18px; }
            .notif-dropdown { width: 290px; max-width: calc(100vw - 20px); }
            .notif-head { padding: 8px 10px; flex-wrap: wrap; }
            .notif-controls { width: 100%; margin-top: 6px; justify-content: space-between; }
            .notif-action { padding: 5px 8px; font-size: 11px; }
            .user-chip { padding: 5px 7px; }
            .user-chip .avatar { width: 26px; height: 26px; font-size: 0.8rem; }
            .logout-btn { padding: 5px 8px; font-size: 0.75rem; }
            .logout-btn span { display: none; } /* Hide text, show icon only */
        }
        
        @media (max-width: 480px) {
            .app-header-right { gap: 0.4rem; }
            .notif-wrap { margin-right: 4px; }
            .notif-bell { width: 36px; height: 36px; }
            .notif-bell i { font-size: 17px; }
            .notif-badge { font-size: 11px; padding: 4px 6px; }
            .notif-dropdown { width: 280px; }
            .user-chip { padding: 4px 6px; }
            .user-chip .avatar { width: 24px; height: 24px; font-size: 0.75rem; }
            .logout-btn { padding: 4px 7px; min-width: 36px; justify-content: center; }
        }
    </style>
    <?php /* Mobile responsive overrides removed - using global mobile styles from style.css */ ?>
    <script>
    (function(){
        <?php if (!((($__isAdmin ?? false)) || (($__isDelivery ?? false)))) { echo 'return;'; } ?>
        if (window.__riceposNotifInit) return; // avoid duplicate init
        window.__riceposNotifInit = true;
        const bell = document.getElementById('notifBell');
        const dd = document.getElementById('notifDropdown');
        const listEl = document.getElementById('notifList');
        const badge = document.getElementById('notifBadge');
        const markBtn = document.getElementById('notifMarkRead');
        
        const soundBtn = document.getElementById('notifSoundToggle');
        const wrap = document.querySelector('.notif-wrap');
        if (!bell || !dd || !listEl || !badge || !wrap) return;
        let open = false; let ignoreNextDocClick = false;
        let lastSeenId = 0; // highest ID we've fetched so far
        function setBadge(n){ if(n>0){ badge.textContent=String(n); badge.hidden=false; bell.classList.add('has-unseen'); } else { badge.hidden=true; bell.classList.remove('has-unseen'); } }
        // Update view-more UI based on unread
        // Keep all notifications; allow scrolling
        function trimToFive(){}
        // Sound controls
        let soundEnabled = (localStorage.getItem('notif_sound') || 'on') !== 'off';
        let audioCtx = null;
        let volume = 1.0; // fixed 100%
        function ensureAudio(){
            if (!audioCtx) {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (Ctx) { audioCtx = new Ctx(); }
            }
            if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume().catch(()=>{}); }
        }
        function playChime(delayMs = 0){
            if (!soundEnabled) return;
            ensureAudio(); if (!audioCtx) return;
            const t0 = audioCtx.currentTime + (delayMs/1000);
            // Loud, clear shop "ding" (max volume), bright bandpass
            const master = audioCtx.createGain(); master.gain.setValueAtTime(0.0001, t0);
            const bp = audioCtx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.setValueAtTime(3200, t0); bp.Q.setValueAtTime(1.0, t0);
            master.connect(bp).connect(audioCtx.destination);
            // Stronger attack
            const noiseBuf = audioCtx.createBuffer(1, Math.floor(audioCtx.sampleRate*0.025), audioCtx.sampleRate);
            const data = noiseBuf.getChannelData(0); for (let i=0;i<data.length;i++){ data[i] = (Math.random()*2-1); }
            const noise = audioCtx.createBufferSource(); noise.buffer = noiseBuf;
            const ng = audioCtx.createGain(); ng.gain.setValueAtTime(0.25, t0); ng.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.05);
            noise.connect(ng).connect(master);
            function tone(freq, det = 0, start = t0, dur = 0.5){
                const osc = audioCtx.createOscillator(); osc.type = 'square'; osc.frequency.setValueAtTime(freq, start); osc.detune.setValueAtTime(det, start);
                const g = audioCtx.createGain(); g.gain.setValueAtTime(0.0001, start);
                g.gain.exponentialRampToValueAtTime(0.55, start + 0.012);
                g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
                osc.connect(g).connect(master); osc.start(start); osc.stop(start + dur + 0.05);
            }
            // Higher pitched and loud
            tone(1760, 0, t0, 0.45); // A6
            const t1 = t0 + 0.12; tone(1976, 0, t1, 0.35); // B6
            master.gain.exponentialRampToValueAtTime(1.0, t0 + 0.015);
            master.gain.exponentialRampToValueAtTime(0.0002, t0 + 0.55);
            noise.start(t0); noise.stop(t0 + 0.05);
        }
        function updateSoundBtn(){ if (!soundBtn) return; soundBtn.textContent = soundEnabled ? 'Sound On' : 'Sound Off'; soundBtn.style.color = soundEnabled ? '#16a34a' : '#64748b'; }
        updateSoundBtn();
        soundBtn && soundBtn.addEventListener('click', ()=>{ soundEnabled = !soundEnabled; localStorage.setItem('notif_sound', soundEnabled ? 'on' : 'off'); updateSoundBtn(); });
        // Unlock audio context silently on first user gesture
        const unlock = ()=>{ try { ensureAudio(); } catch{} window.removeEventListener('click', unlock); window.removeEventListener('keydown', unlock); };
        window.addEventListener('click', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });
        function openDropdown(){ if(open) return; open=true; dd.hidden=false; bell.setAttribute('aria-expanded','true'); }
        function closeDropdown(){ if(!open) return; open=false; dd.hidden=true; bell.setAttribute('aria-expanded','false'); }
        function toggle(){ if(open){ closeDropdown(); } else { openDropdown(); ignoreNextDocClick = true; setTimeout(()=>{ ignoreNextDocClick=false; }, 0); } }
        bell.addEventListener('click', (e)=>{ e.stopPropagation(); toggle(); });
        dd.addEventListener('click', (e)=>{ e.stopPropagation(); });
        document.addEventListener('click', (e)=>{
            if (!open || ignoreNextDocClick) return;
            if (!wrap.contains(e.target)) { closeDropdown(); }
        });
        document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closeDropdown(); });
        // Track previous unread count for delivery staff to avoid repeated chimes
        let prevUnreadCount = 0;

        // Server-backed: mark all read
        markBtn && markBtn.addEventListener('click', async ()=>{ 
            try{
                const isAdmin = <?php echo $__isAdmin ? 'true' : 'false'; ?>;
                const url = isAdmin ? 'notifications.php?action=mark_all_read' : 'delivery_staff_notifications.php?action=mark_all_read';
                const res = await fetch(url, { method:'POST' });
                const d = await res.json();
                setBadge(0);
                Array.from(listEl.children).forEach(ch=>{ ch.classList.add('read'); });
                // Reset unread tracker so no stray chimes
                prevUnreadCount = 0;
            }catch{}
        });
        
        const actionEmoji = { product_add:'🆕', product_update:'✏️', product_delete:'🗑️', stock_update:'📦', stock_sale:'🧾', product_activate:'✅', product_hide:'🙈', delivery_status:'🚚' };
        function addItems(items){
            const frag = document.createDocumentFragment();
            items.forEach(it=>{
                const div = document.createElement('div'); div.className='notif-item a-'+(it.action || ''); div.dataset.id = String(it.id);
                const emoji = actionEmoji[it.action] || 'ℹ️';
                div.innerHTML = `<div class="notif-emoji">${emoji}</div>
                    <div class="notif-meta">
                        <div class="notif-title">${it.title}</div>
                        <div class="notif-sub">${it.when} • ${it.user}</div>
                    </div>`;
                if (it.read) { div.classList.add('read'); }
                frag.appendChild(div);
            });
            // Prepend and cap to latest 5 entries
            listEl.prepend(frag);
            // keep all; allow scrolling
        }
        // Click to mark as read (highlighted gray), but keep item in list
        listEl.addEventListener('click', async (e)=>{
            const item = e.target.closest('.notif-item'); if (!item) return;
            const id = parseInt(item.dataset.id||'0',10); if(!id) return;
            if (!item.classList.contains('read')) {
                try{
                    const res = await fetch('notifications.php?action=mark_read', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:`id=${encodeURIComponent(String(id))}` });
                    const d = await res.json();
                    item.classList.add('read');
                    if (d && typeof d.unread_count === 'number') setBadge(d.unread_count);
                }catch{}
            }
        });
        async function poll(){
            try{
                const isAdmin = <?php echo $__isAdmin ? 'true' : 'false'; ?>;
                if (isAdmin) {
                    const res = await fetch('notifications.php?action=list&since_id='+lastSeenId+'&limit=20', { cache:'no-store' });
                    const data = await res.json();
                    const rows = Array.isArray(data.rows)? data.rows: [];
                    if (typeof data.unread_count === 'number') setBadge(data.unread_count);
                    if (typeof data.max_id === 'number' && data.max_id < lastSeenId) { lastSeenId = data.max_id; }
                    if(rows.length){
                        lastSeenId = Math.max(...rows.map(r=>r.id).concat(lastSeenId));
                        const items = rows.map(r=>({ id:r.id, action:r.action, title: buildTitle(r), when:r.created_at, user:r.username||('User#'+(r.user_id||'')), read: !!r.read }));
                        addItems(items);
                        const maxChimes = Math.min(rows.length, 3);
                        for (let i=0;i<maxChimes;i++){ playChime(i*180); }
                    }
                } else {
                    const res = await fetch('delivery_staff_notifications.php?action=list&limit=20', { cache:'no-store' });
                    const data = await res.json();
                    const rows = Array.isArray(data.rows)? data.rows: [];
                    if (typeof data.unread_count === 'number') {
                        setBadge(data.unread_count);
                        // Chime only when unread increases since last poll
                        if (data.unread_count > prevUnreadCount) { playChime(0); }
                        prevUnreadCount = data.unread_count;
                    }
                    if(rows.length){
                        const items = rows.map(r=>({ id:r.id, action:'delivery_status', title:`Delivery #${r.id} ${r.status} • ₱${(r.total_amount||0).toLocaleString()}`, when:r.event_time, user:r.customer_name||'', read: !!r.read }));
                        addItems(items);
                    }
                }
            }catch(e){ /* ignore */ }
        }
        function buildTitle(r){
            const name = r.product_name ? `#${r.product_id} ${r.product_name}` : '';
            switch(r.action){
                case 'product_add': return `Added product ${name}`;
                case 'product_update': return `Updated product ${name}`;
                case 'product_delete': return `Deleted product ${name}`;
                case 'stock_update': return `Adjusted stock for ${name}`;
                case 'stock_sale': return `Sale deducted stock for ${name}`;
                case 'product_activate': return `Activated ${name}`;
                case 'product_hide': return `Hidden ${name}`;
                default: return `${r.action} ${name}`;
            }
        }
        // Initial load and visibility-aware polling. Warm bootstrap with latest entries from server.
        (async ()=>{
            try {
                const isAdmin = <?php echo $__isAdmin ? 'true' : 'false'; ?>;
                if (isAdmin) {
                    const res0 = await fetch('notifications.php?action=list&since_id=0&limit=20', { cache:'no-store' });
                    const d0 = await res0.json();
                    const rows0 = Array.isArray(d0.rows) ? d0.rows : [];
                    if (typeof d0.unread_count === 'number') setBadge(d0.unread_count);
                    if (rows0.length) {
                        lastSeenId = Math.max(...rows0.map(r=>r.id));
                        const items0 = rows0.map(r=>({ id:r.id, action:r.action, title:buildTitle(r), when:r.created_at, user:r.username || ('User#'+(r.user_id||'')), read: !!r.read }));
                        addItems(items0);
                    }
                } else {
                    const res0 = await fetch('delivery_staff_notifications.php?action=list&limit=20', { cache:'no-store' });
                    const d0 = await res0.json();
                    const rows0 = Array.isArray(d0.rows) ? d0.rows : [];
                    if (typeof d0.unread_count === 'number') { setBadge(d0.unread_count); prevUnreadCount = d0.unread_count; }
                    if (rows0.length) {
                        const items0 = rows0.map(r=>({ id:r.id, action:'delivery_status', title:`Delivery #${r.id} ${r.status} • ₱${(r.total_amount||0).toLocaleString()}`, when:r.event_time, user:r.customer_name||'', read: !!r.read }));
                        addItems(items0);
                    }
                }
            } catch {}
            // Visibility-aware polling
            let intervalId = null;
            function startPolling(){ if(intervalId) return; intervalId = setInterval(poll, document.hidden ? 15000 : 8000); }
            function stopPolling(){ if(intervalId){ clearInterval(intervalId); intervalId = null; } }
            document.addEventListener('visibilitychange', ()=>{ stopPolling(); startPolling(); });
            poll(); startPolling();
        })();
    })();
    </script>
</header>

<!-- Mobile Overlay for Sidebar -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<script>
(function() {
    // Mobile menu toggle functionality
    const menuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('mobileOverlay');
    
    if (!menuBtn || !sidebar || !overlay) return;
    
    function openMobileMenu() {
        sidebar.classList.add('mobile-open');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
    
    function closeMobileMenu() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Toggle menu on button click
    menuBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (sidebar.classList.contains('mobile-open')) {
            closeMobileMenu();
        } else {
            openMobileMenu();
        }
    });
    
    // Close menu when overlay is clicked
    overlay.addEventListener('click', function() {
        closeMobileMenu();
    });
    
    // Close menu when a nav link is clicked
    const navLinks = sidebar.querySelectorAll('.nav-links a');
    navLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            // Small delay to allow navigation
            setTimeout(closeMobileMenu, 100);
        });
    });
    
    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
            closeMobileMenu();
        }
    });
    
    // Close menu when window is resized above mobile breakpoint
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('mobile-open')) {
                closeMobileMenu();
            }
        }, 250);
    });
})();
</script>


