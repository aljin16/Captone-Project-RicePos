<?php
require_once __DIR__ . '/Database.php';
class User {
    private $pdo;
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    public function login($username, $password) {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            return true;
        }
        return false;
    }
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    public function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    public function logout() {
        session_unset();
        session_destroy();
    }
} 