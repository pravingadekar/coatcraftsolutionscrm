<?php
// Webhook called by MacroDroid when a call is missed on the owner's phone.
// Sends an auto-SMS to the caller with the company's enquiry form link.
//
// POST params:
//   secret       — must match MISSED_CALL_SECRET in config.php
//   caller       — caller's phone number (any format, normalized here)
//   company_id   — which tenant's form link to send (default: 3 for CoatCraft)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

function jsonExit(bool $ok, string $msg, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(false, 'POST required', 405);
}

$secret    = trim($_POST['secret'] ?? '');
$callerRaw = trim($_POST['caller'] ?? '');
$companyId = intval($_POST['company_id'] ?? MISSED_CALL_COMPANY_ID);

if (!hash_equals(MISSED_CALL_SECRET, $secret)) {
    jsonExit(false, 'Unauthorized', 401);
}

if ($callerRaw === '') {
    jsonExit(false, 'caller is required', 400);
}

$caller = normalizeIndianPhoneForSms($callerRaw);
if ($caller === null) {
    jsonExit(false, 'Could not normalize caller number: ' . $callerRaw, 400);
}

global $conn;
$stmt = $conn->prepare("SELECT form_token, company_display_name FROM tenant_settings WHERE company_id = ?");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenant || empty($tenant['form_token'])) {
    jsonExit(false, 'Tenant not found or no form token', 404);
}

$formUrl   = SITE_URL . '/lead-form.php?t=' . urlencode($tenant['form_token']);
$company   = $tenant['company_display_name'] ?: 'CoatCraft Solutions';

$message = "Hi! We missed your call from $company. Share your requirements here: $formUrl  Callback: +917745889111";

$sent = sendViaBrevoSms($caller, $message);

if ($sent) {
    jsonExit(true, 'SMS sent to ' . $caller);
} else {
    jsonExit(false, 'Brevo SMS failed — check server error log', 500);
}
