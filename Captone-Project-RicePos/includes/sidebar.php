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
    .sidebar{ width:var(--sidebar-width); min-width:var(--sidebar-width); max-width:var(--sidebar-width); flex:0 0 var(--sidebar-width); position:fixed; top:0; left:0; height:100vh; overflow:hidden; z-index:100; }
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
