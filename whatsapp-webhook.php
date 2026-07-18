<?php
// Meta WhatsApp Cloud API webhook — receives customer replies to our
// templates so they show up in the CRM (crm-dashboard.php lead detail page)
// instead of vanishing. This number runs on the Cloud API, not the regular
// WhatsApp Business App, so without this endpoint a customer's reply has
// nowhere to go.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';

// Meta's one-time verification handshake when the webhook URL is first
// saved in the App dashboard — echo hub_challenge back only if our verify
// token matches what's configured there.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals(WA_WEBHOOK_VERIFY_TOKEN, $_GET['hub_verify_token'] ?? '')) {
        echo $_GET['hub_challenge'] ?? '';
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input');

// Verify the request genuinely came from Meta before touching the DB —
// without this, anyone who finds this URL could inject fake "messages"
// into the CRM.
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, WA_APP_SECRET);
if ($signatureHeader === '' || !hash_equals($expectedSignature, $signatureHeader)) {
    http_response_code(401);
    exit;
}

// Answer 200 immediately from here on — Meta retries (and can eventually
// disable) the webhook subscription if it sees slow/non-2xx responses, so
// this endpoint only ever does a fast DB insert, nothing else (no outbound
// calls, no auto-replies).
http_response_code(200);
header('Content-Type: application/json');

$payload = json_decode($rawBody, true) ?? [];
$companyId = MISSED_CALL_COMPANY_ID;

foreach (($payload['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        foreach (($change['value']['messages'] ?? []) as $message) {
            $from = $message['from'] ?? '';
            $waMessageId = $message['id'] ?? '';
            if ($from === '' || $waMessageId === '') {
                continue;
            }
            $body = $message['text']['body'] ?? ('[' . ($message['type'] ?? 'unsupported') . ' message]');
            $enquiryId = findLeadIdByPhone($conn, $companyId, $from);
            logWhatsAppInbound($conn, $companyId, $enquiryId, $from, $waMessageId, $body);
        }
        // Status updates (sent/delivered/read/failed) also arrive here via
        // $change['value']['statuses'] — intentionally ignored, only actual
        // customer replies are stored.
    }
}

echo json_encode(['success' => true]);
