<?php
// Copy this file to config.php and fill in real values. config.php is gitignored.

// Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'coatcraft_enquiry');
define('DB_USER', 'root');
define('DB_PASSWORD', '');

// SMTP (PHPMailer)
define('SMTP_HOST', 'smtp.titan.email');
define('SMTP_USERNAME', 'info@workmanager.in');
define('SMTP_PASSWORD', 'CHANGE_ME');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'info@workmanager.in');
define('SMTP_FROM_NAME', 'CoatCraft Solutions');

// Web Push VAPID keys
define('VAPID_PUBLIC_KEY', 'CHANGE_ME');
define('VAPID_PRIVATE_KEY', 'CHANGE_ME');
define('VAPID_SUBJECT', 'mailto:info@workmanager.in');

// Admin login (generate a hash with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);")
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'CHANGE_ME');
