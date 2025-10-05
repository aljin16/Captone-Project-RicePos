<?php
// Shared fixed sidebar for RicePOS
// Usage from a page: set $activePage = 'dashboard.php'; then include this file.

// Determine admin capability in a resilient way
$isAdmin = false;
if (function_exists('is_admin')) {
    $isAdmin = is_admin();
} else {
    // Fallback to User class if available
    if (!isset($user)) {
        $userClassPath = __DIR__ . '/../classes/User.php';
        if (file_exists($userClassPath)) {
            require_once $userClassPath;
            try { $user = new User(); } catch (\Throwable $e) { /* ignore */ }
        }
    }
    if (isset($user) && method_exists($user, 'isAdmin')) {
        try { $isAdmin = (bool)$user->isAdmin(); } catch (\Throwable $e) { $isAdmin = false; }
    }
}

// Helper to mark active link
function nav_active($pageName, $activePage)
{
    return isset($activePage) && $activePage === $pageName ? 'active' : '';
}
?>
<style>
    :root { --sidebar-width: 260px; }
    .sidebar{ width:var(--sidebar-width); min-width:var(--sidebar-width); max-width:var(--sidebar-width); flex:0 0 var(--sidebar-width); position:fixed; top:0; left:0; height:100vh; overflow-y:auto; z-index:100; }
    .nav-submenu{ display:none; flex-direction:column; background:rgba(0,0,0,0.08); border-radius:8px; margin:0.25rem 0.5rem; padding:0.25rem 0; }
    .nav-submenu.open{ display:flex; }
    .nav-submenu a{ padding-left:2.5rem; font-size:0.9rem; }
    .nav-parent{ cursor:pointer; position:relative; }
    .nav-parent > a{ white-space: normal; overflow: visible; }
    .nav-parent .nav-arrow{ display:inline-block; margin-left:0; margin-top:2px; width:12px; height:12px; border-right:3px solid rgba(255,255,255,0.95); border-bottom:3px solid rgba(255,255,255,0.95); transform: rotate(-45deg); transition: transform 0.22s ease; flex: 0 0 auto; color: #fff; align-self:flex-start; }
    .nav-parent.open .nav-arrow{ transform: rotate(45deg); }
    .nav-subcount{ display:inline-block; min-width:16px; height:16px; padding:0 6px; border-radius:999px; font-size:11px; font-weight:800; line-height:16px; background: rgba(255,255,255,0.14); color:#fff; margin-left:0.5rem; }
    .nav-textcol{ display:flex; flex-direction:column; gap:4px; flex:1 1 auto; }
    .sidebar-staff .nav-links { justify-content: space-evenly; flex-grow: 1; }
</style>
<aside class="sidebar <?php if (!$isAdmin) { echo 'sidebar-staff'; } ?>">
    <div class="nav-brand">RicePOS</div>
    <div class="nav-links">
        <a href="dashboard.php" class="<?php echo nav_active('dashboard.php', $activePage); ?>"><i class='bx bx-home'></i> <span class="nav-label">Dashboard</span></a>
        <a href="recent_sales.php" class="<?php echo nav_active('recent_sales.php', $activePage); ?>"><i class='bx bx-receipt'></i> <span class="nav-label">Recent Sales</span></a>
        <?php if ($isAdmin): ?>
            <a href="suppliers.php" class="<?php echo nav_active('suppliers.php', $activePage); ?>"><i class='bx bx-user-voice'></i> <span class="nav-label">Suppliers</span></a>
        <?php endif; ?>
        <a href="inventory.php" class="<?php echo nav_active('inventory.php', $activePage); ?>"><i class='bx bx-box'></i> <span class="nav-label">Inventory</span></a>
        <?php if ($isAdmin): ?>
            <?php $invSubPages = ['stock_in.php','stock_out.php','inventory_reports.php']; $invOpen = in_array($activePage, $invSubPages); $invCount = count($invSubPages); ?>
            <div class="nav-parent <?php echo $invOpen ? 'open' : ''; ?>" id="invMgmtParent">
                <a href="javascript:void(0)" onclick="toggleSubmenu('invMgmt')" style="display:flex; align-items:center; gap:1rem;" aria-expanded="<?php echo $invOpen ? 'true' : 'false'; ?>" aria-controls="invMgmt" id="invMgmtToggle">
                    <i class='bx bx-cog'></i>
                    <span class="nav-textcol">
                        <span>
                            <span class="nav-label">Inventory<br>Management</span>
                        </span>
                        <span class="nav-arrow" aria-hidden="true"></span>
                    </span>
                </a>
            </div>
            <div class="nav-submenu <?php echo $invOpen ? 'open' : ''; ?>" id="invMgmt">
                <a href="stock_in.php" class="<?php echo nav_active('stock_in.php', $activePage); ?>"><i class='bx bx-download'></i> <span class="nav-label">Stock-In</span></a>
                <a href="stock_out.php" class="<?php echo nav_active('stock_out.php', $activePage); ?>"><i class='bx bx-upload'></i> <span class="nav-label">Stock-Out</span></a>
                <a href="inventory_reports.php" class="<?php echo nav_active('inventory_reports.php', $activePage); ?>"><i class='bx bx-bar-chart-square'></i> <span class="nav-label">Inventory<br>Reports</span></a>
            </div>
            <a href="inventory_logs.php" class="<?php echo nav_active('inventory_logs.php', $activePage); ?>"><i class='bx bx-history'></i> <span class="nav-label">Activity Logs</span></a>
        <?php endif; ?>
        <?php if (!$isAdmin): ?>
            <a href="pos.php" class="<?php echo nav_active('pos.php', $activePage); ?>"><i class='bx bx-cart'></i> <span class="nav-label">POS</span></a>
            <a href="delivery.php" class="<?php echo nav_active('delivery.php', $activePage); ?>"><i class='bx bx-package'></i> <span class="nav-label">Delivery</span></a>
        <?php endif; ?>
        <a href="delivery_management.php" class="<?php echo nav_active('delivery_management.php', $activePage); ?>">
            <i class='bx bx-notepad'></i>
            <span class="nav-label">Delivery<br>Management</span>
        </a>
        <?php if ($isAdmin): ?>
            <a href="users.php" class="<?php echo nav_active('users.php', $activePage); ?>"><i class='bx bx-group'></i> <span class="nav-label">Users</span></a>
        <?php endif; ?>
    </div>
</aside>
<script>
function toggleSubmenu(id) {
    const submenu = document.getElementById(id);
    const parent = document.getElementById(id + 'Parent');
    const toggle = document.getElementById(id + 'Toggle');
    if (submenu && parent) {
        submenu.classList.toggle('open');
        parent.classList.toggle('open');
        if (toggle) {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        }
    }
}
</script>
