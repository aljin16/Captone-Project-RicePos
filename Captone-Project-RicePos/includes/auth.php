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

function logout() {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
} 