<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['company_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function current_company_id(): int {
    return (int)($_SESSION['company_id'] ?? 0);
}

function current_user(): array {
    return [
        'id' => (int)($_SESSION['user_id'] ?? 0),
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'company_id' => current_company_id(),
    ];
}
