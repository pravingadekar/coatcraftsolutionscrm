<?php
// Simple per-key throttle for public, unauthenticated endpoints
// (sendmail.php, subscribe.php). Not meant to stop a determined attacker —
// just to stop one tenant's form from being spammed or hammering the
// shared SMTP relay. Backed by MySQL, no Redis needed at this scale.

function isRateLimited(mysqli $conn, string $key, int $maxAttempts, int $windowSeconds): bool {
    $hashedKey = hash('sha256', $key);

    $stmt = $conn->prepare("SELECT attempts, window_start FROM rate_limits WHERE rate_key = ?");
    $stmt->bind_param('s', $hashedKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = time();

    if (!$row) {
        $stmt = $conn->prepare("INSERT INTO rate_limits (rate_key, attempts, window_start) VALUES (?, 1, NOW())");
        $stmt->bind_param('s', $hashedKey);
        $stmt->execute();
        $stmt->close();
        return false;
    }

    $windowStart = strtotime($row['window_start']);
    if ($now - $windowStart > $windowSeconds) {
        // Window expired, reset.
        $stmt = $conn->prepare("UPDATE rate_limits SET attempts = 1, window_start = NOW() WHERE rate_key = ?");
        $stmt->bind_param('s', $hashedKey);
        $stmt->execute();
        $stmt->close();
        return false;
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        return true;
    }

    $stmt = $conn->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE rate_key = ?");
    $stmt->bind_param('s', $hashedKey);
    $stmt->execute();
    $stmt->close();
    return false;
}

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
