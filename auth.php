<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login.php');
        exit;
    }
}
