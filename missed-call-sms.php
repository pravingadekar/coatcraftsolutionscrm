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
require_once __DIR__ . '/leads.php';

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

if (!shouldSendMissedCallMessage($conn, $companyId, $caller)) {
    jsonExit(true, 'Skipped — already sent twice this 30-day cycle for ' . $caller);
}

// SMS temporarily disabled: Brevo requires a registered Sender ID for India,
// which isn't set up yet — sending would just burn credits showing a random
// number instead of "CoatCraft". Re-enable once the Sender ID is approved by
// uncommenting the block below.
// $message = "CoatCraft Solutions\n\n"
//     . "Hello! Thank you for contacting CoatCraft Solutions.\n\n"
//     . "To help us assist you better, please select the appropriate enquiry form:\n\n"
//     . "Industrial Flooring Enquiry: https://coatcraftcrm.workmanager.in/enquiry.html\n\n"
//     . "Residential Flooring Enquiry: https://coatcraftcrm.workmanager.in/residential-enquiry.html\n\n"
//     . "Once we receive your enquiry, our team will review your requirements and get in touch with you as soon as possible.\n\n"
//     . "Need immediate assistance? Call us: +91 77458 89111\n\n"
//     . "Thank you for choosing CoatCraft Solutions.";
// sendViaBrevoSms($caller, $message); // best-effort; counts as multiple SMS credits since it's long

$sent = sendMissedCallWhatsApp($caller);

if ($sent) {
    jsonExit(true, 'WhatsApp sent to ' . $caller);
} else {
    jsonExit(false, 'WhatsApp send failed — check server error log', 500);
}
