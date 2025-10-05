<?php
session_start();
require_once __DIR__ . '/db.php';

function login($username, $password) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        require_once __DIR__ . '/functions.php';
        update_last_login($user['id']);
        return true;
    }
    return false;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_admin() {
    if (!is_admin()) {
        header('Location: dashboard.php');
        exit;
    }
}

// Role helpers for staff accounts
function is_sales_staff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'sales_staff';
}

function is_delivery_staff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'delivery_staff';
}

function require_role($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        header('Location: dashboard.php');
        exit;
    }
}

function require_sales_staff() {
    require_role('sales_staff');
}

function require_delivery_staff() {
    require_role('delivery_staff');
}

function logout() {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
} 