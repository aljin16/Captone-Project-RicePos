<?php
// Password reset utilities for RicePOS
// Ensures schema, creates reset tokens, validates tokens, and updates passwords

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

/**
 * Ensure users table has columns needed for password reset.
 * Adds: password_reset_token_hash VARCHAR(64) NULL, password_reset_expires DATETIME NULL
 */
function ensure_password_reset_schema(): void {
    global $pdo;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll();
        $haveToken = false; $haveExpires = false;
        foreach ($cols as $c) {
            if (isset($c['Field']) && $c['Field'] === 'password_reset_token_hash') { $haveToken = true; }
            if (isset($c['Field']) && $c['Field'] === 'password_reset_expires') { $haveExpires = true; }
        }
        if (!$haveToken) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN password_reset_token_hash VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ }
        }
        if (!$haveExpires) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN password_reset_expires DATETIME NULL"); } catch (Throwable $e) { /* ignore */ }
        }
    } catch (Throwable $e) {
        // ignore schema checks on failure
    }
}

/**
 * Ensure throttle table exists for rate limiting password reset requests.
 */
function ensure_password_reset_throttle_schema(): void {
    global $pdo;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_throttle (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NULL,
                ip VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_created_at (email, created_at),
                INDEX idx_ip_created_at (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Record a reset request attempt for throttle tracking.
 */
function record_reset_request_attempt(?string $email, ?string $ip): void {
    global $pdo;
    ensure_password_reset_throttle_schema();
    $stmt = $pdo->prepare('INSERT INTO password_reset_throttle (email, ip) VALUES (?, ?)');
    $stmt->execute([$email ?: null, $ip ?: null]);
}

/**
 * Check whether too many requests occurred for the email or IP in the past window.
 * Returns true if over limit.
 */
function too_many_reset_requests(?string $email, ?string $ip, int $windowMinutes = 60, int $maxPerEmail = 3, int $maxPerIp = 10): bool {
    global $pdo;
    ensure_password_reset_throttle_schema();
    // Allow disabling throttle via config during testing
    if (defined('PASSWORD_RESET_THROTTLE_DISABLED') && PASSWORD_RESET_THROTTLE_DISABLED) { return false; }
    $windowMinutes = max(1, $windowMinutes);
    $since = (new DateTimeImmutable('now'))->sub(new DateInterval('PT' . $windowMinutes . 'M'))->format('Y-m-d H:i:s');
    $overEmail = false; $overIp = false;
    if ($email) {
        $q1 = $pdo->prepare('SELECT COUNT(*) FROM password_reset_throttle WHERE email = ? AND created_at >= ?');
        $q1->execute([$email, $since]);
        $overEmail = ((int)$q1->fetchColumn()) >= $maxPerEmail;
    }
    if ($ip) {
        $q2 = $pdo->prepare('SELECT COUNT(*) FROM password_reset_throttle WHERE ip = ? AND created_at >= ?');
        $q2->execute([$ip, $since]);
        $overIp = ((int)$q2->fetchColumn()) >= $maxPerIp;
    }
    return $overEmail || $overIp;
}

/**
 * Mask an email for logs to avoid exposing full PII.
 */
function mask_email_for_log(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) { return '[invalid]'; }
    [$local, $domain] = explode('@', $email, 2);
    $localMasked = strlen($local) <= 2 ? str_repeat('*', strlen($local)) : substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));
    $domainParts = explode('.', $domain);
    $domainMasked = $domain;
    if (count($domainParts) >= 2) {
        $domainParts[0] = substr($domainParts[0], 0, 1) . str_repeat('*', max(0, strlen($domainParts[0]) - 1));
        $domainMasked = implode('.', $domainParts);
    }
    return $localMasked . '@' . $domainMasked;
}

/**
 * Create a password reset token for a user identified by email.
 * Returns raw token string if user exists and token stored, otherwise null.
 */
/**
 * Returns [token, userId] on success, or null if user not found/inactive.
 */
function create_password_reset_for_email(string $email, int $ttlSeconds = 3600): ?array {
    global $pdo;
    ensure_password_reset_schema();
    ensure_password_reset_throttle_schema();
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return null; }

    $stmt = $pdo->prepare('SELECT id, status FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) { return null; }
    // Allow case-insensitive variants of active; block only explicit disabled states
    $status = strtolower((string)($user['status'] ?? 'active'));
    $blockedStatuses = ['blocked','disabled','suspended','inactive'];
    if (in_array($status, $blockedStatuses, true)) { return null; }

    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . max(300, (int)$ttlSeconds) . 'S'));

    $upd = $pdo->prepare('UPDATE users SET password_reset_token_hash = ?, password_reset_expires = ? WHERE id = ?');
    $upd->execute([$hash, $expires->format('Y-m-d H:i:s'), $user['id']]);

    // Verify it persisted to avoid sending broken links
    $check = $pdo->prepare('SELECT password_reset_token_hash FROM users WHERE id = ?');
    $check->execute([$user['id']]);
    $row = $check->fetch();
    if (!$row || $row['password_reset_token_hash'] !== $hash) { return null; }

    return [$token, (int)$user['id']];
}

/**
 * Find a user by reset token (raw), returns array user or null. Ensures not expired.
 */
function find_user_by_reset_token(string $token, ?int $userId = null): ?array {
    global $pdo;
    ensure_password_reset_schema();
    $token = trim($token);
    if ($token === '') { return null; }
    $hash = hash('sha256', $token);
    // Fetch without DB time comparison; validate expiry in PHP to avoid timezone mismatch
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND password_reset_token_hash = ? AND password_reset_expires IS NOT NULL LIMIT 1');
        $stmt->execute([$userId, $hash]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE password_reset_token_hash = ? AND password_reset_expires IS NOT NULL LIMIT 1');
        $stmt->execute([$hash]);
    }
    $user = $stmt->fetch();
    if (!$user) { return null; }
    try {
        $expires = new DateTimeImmutable($user['password_reset_expires']);
        $now = new DateTimeImmutable('now');
        if ($expires <= $now) { return null; }
    } catch (Throwable $e) {
        return null;
    }
    return $user;
}

/**
 * Clear reset token fields for a user id.
 */
function clear_password_reset(int $userId): void {
    global $pdo;
    ensure_password_reset_schema();
    $pdo->prepare('UPDATE users SET password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = ?')->execute([$userId]);
}

/**
 * Update user password and clear reset token in a transaction.
 */
function update_user_password_with_reset(int $userId, string $newPassword): bool {
    global $pdo;
    ensure_password_reset_schema();
    if (strlen($newPassword) < 8) { return false; }
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        $pdo->prepare('UPDATE users SET password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = ?')->execute([$userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return false;
    }
}

?>


