<?php
// Copy this file to config.php and fill in real values. config.php is gitignored.

// Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'coatcraft_enquiry');
define('DB_USER', 'root');
define('DB_PASSWORD', '');

// SMTP (PHPMailer)
define('SMTP_HOST', 'smtpout.secureserver.net');
define('SMTP_USERNAME', 'info@workmanager.in');
define('SMTP_PASSWORD', 'CHANGE_ME');
define('SMTP_SECURE', 'ssl');
define('SMTP_PORT', 465);
define('SMTP_FROM_EMAIL', 'info@workmanager.in');
define('SMTP_FROM_NAME', 'CoatCraft Solutions');

// Web Push VAPID keys
define('VAPID_PUBLIC_KEY', 'CHANGE_ME');
define('VAPID_PRIVATE_KEY', 'CHANGE_ME');
define('VAPID_SUBJECT', 'mailto:info@workmanager.in');

// Admin login (generate a hash with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);")
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'CHANGE_ME');

// Secret key for cron-triggered scripts (reminder.php) that run across all
// tenants without a logged-in session. Generate with: php -r "echo bin2hex(random_bytes(24));"
define('CRON_SECRET', 'CHANGE_ME');

// Razorpay (billing.php / billing-create-order.php / billing-verify.php)
// Get test-mode keys from https://dashboard.razorpay.com/app/keys
define('RAZORPAY_KEY_ID', 'CHANGE_ME');
define('RAZORPAY_KEY_SECRET', 'CHANGE_ME');

// Client-facing email (enquiry thank-you, site visit confirmations) — sent via
// a separate mailbox from the tenant-notification SMTP_* config above.
define('SALES_SMTP_HOST', 'smtpout.secureserver.net');
define('SALES_SMTP_USERNAME', 'sales@coatcraftsolutions.com');
define('SALES_SMTP_PASSWORD', 'CHANGE_ME');
define('SALES_SMTP_SECURE', 'ssl');
define('SALES_SMTP_PORT', 465);
define('SALES_SMTP_FROM_EMAIL', 'sales@coatcraftsolutions.com');
define('SALES_SMTP_FROM_NAME', 'CoatCraft Solutions');

// Brevo (api.brevo.com) transactional email API — used instead of SMTP for
// all outgoing mail (tenant notification, thank-you, site visit, password
// reset) because several hosts block outbound SMTP ports but allow HTTPS.
// Get an API key from https://app.brevo.com/settings/keys/api and verify
// SMTP_FROM_EMAIL/SALES_SMTP_FROM_EMAIL as senders there.
define('BREVO_API_KEY', 'CHANGE_ME');

// Brevo transactional SMS sender — alphanumeric, max 11 chars. Must be
// DLT-registered on Brevo's dashboard for delivery to Indian numbers
// (TRAI requirement, separate from this API key).
define('BREVO_SMS_SENDER', 'CoatCraft');

// Secret key for the missed-call SMS webhook (missed-call-sms.php).
// MacroDroid sends this in every request so random IPs can't trigger SMS.
// Generate a new value with: php -r "echo bin2hex(random_bytes(16));"
define('MISSED_CALL_SECRET', 'CHANGE_ME');
define('MISSED_CALL_COMPANY_ID', 1); // your company's ID in the companies table

// Public base URL the app is served from — used to build a public image
// URL for the tenant logo in emails (linking instead of base64-embedding
// avoids Gmail's ~102KB "message clipped" truncation on branded emails).
define('SITE_URL', 'https://coatcraftcrm.workmanager.in');
