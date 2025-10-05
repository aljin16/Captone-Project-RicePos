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
    .nav-parent .nav-arrow{ position:absolute; right:1rem; top:50%; transform:translateY(-50%); transition:transform 0.2s ease; font-size:1.2rem; }
    .nav-parent.open .nav-arrow{ transform:translateY(-50%) rotate(90deg); }
</style>
<aside class="sidebar">
    <div class="nav-brand">RicePOS</div>
    <div class="nav-links">
        <a href="dashboard.php" class="<?php echo nav_active('dashboard.php', $activePage); ?>"><i class='bx bx-home'></i> <span class="nav-label">Dashboard</span></a>
        <a href="recent_sales.php" class="<?php echo nav_active('recent_sales.php', $activePage); ?>"><i class='bx bx-receipt'></i> <span class="nav-label">Recent Sales</span></a>
        <?php if ($isAdmin): ?>
            <a href="suppliers.php" class="<?php echo nav_active('suppliers.php', $activePage); ?>"><i class='bx bx-user-voice'></i> <span class="nav-label">Suppliers</span></a>
        <?php endif; ?>
        <a href="inventory.php" class="<?php echo nav_active('inventory.php', $activePage); ?>"><i class='bx bx-box'></i> <span class="nav-label">Inventory</span></a>
        <?php if ($isAdmin): ?>
            <div class="nav-parent <?php echo in_array($activePage, ['stock_in.php','stock_out.php','inventory_reports.php']) ? 'open' : ''; ?>" id="invMgmtParent">
                <a href="javascript:void(0)" onclick="toggleSubmenu('invMgmt')">
                    <i class='bx bx-cog'></i>
                    <span class="nav-label">Inventory<br>Management</span>
                    <i class='bx bx-chevron-right nav-arrow'></i>
                </a>
            </div>
            <div class="nav-submenu <?php echo in_array($activePage, ['stock_in.php','stock_out.php','inventory_reports.php']) ? 'open' : ''; ?>" id="invMgmt">
                <a href="stock_in.php" class="<?php echo nav_active('stock_in.php', $activePage); ?>"><i class='bx bx-download'></i> <span class="nav-label">Stock-In</span></a>
                <a href="stock_out.php" class="<?php echo nav_active('stock_out.php', $activePage); ?>"><i class='bx bx-upload'></i> <span class="nav-label">Stock-Out</span></a>
                <a href="inventory_reports.php" class="<?php echo nav_active('inventory_reports.php', $activePage); ?>"><i class='bx bx-bar-chart-square'></i> <span class="nav-label">Inventory Reports</span></a>
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
    if (submenu && parent) {
        submenu.classList.toggle('open');
        parent.classList.toggle('open');
    }
}
</script>
