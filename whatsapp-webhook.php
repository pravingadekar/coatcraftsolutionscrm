<?php
// Meta WhatsApp Cloud API webhook — receives customer replies to our
// templates so they show up in the CRM (crm-dashboard.php lead detail page)
// instead of vanishing. This number runs on the Cloud API, not the regular
// WhatsApp Business App, so without this endpoint a customer's reply has
// nowhere to go.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/mailer.php';
// NOTE: deliberately NOT requiring sendmail.php/reminder.php here even though
// both define sendPushNotification() — both are full request-handling
// endpoints with top-level code that reads $_POST/$_GET and can exit/redirect
// immediately (e.g. sendmail.php exits with an error if $_POST['form_token']
// is missing, which it always will be for a WhatsApp webhook POST). Including
// either would kill this script before it processes the inbound message.
// notifyStaffOfBotInterest() (leads.php) reimplements the same push-send
// logic using just the safe includes below.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/push-config.php';
require_once __DIR__ . '/whatsapp-bot-content.php';
require_once __DIR__ . '/whatsapp-bot-router.php';

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
// aside from the rule-based bot reply below (a single, fast, synchronous
// curl call), this endpoint only does DB work.
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
            // Interactive replies (list/button taps) carry a structured id in
            // addition to a human-readable title — capture both, since the
            // guided-menu bot routes on the id while $body stays the
            // human-readable text stored in whatsapp_inbound_messages.
            $interactiveId = null;
            if (isset($message['interactive']['list_reply']['id'])) {
                $interactiveId = $message['interactive']['list_reply']['id'];
                $body = $message['interactive']['list_reply']['title'] ?? $interactiveId;
            } elseif (isset($message['interactive']['button_reply']['id'])) {
                $interactiveId = $message['interactive']['button_reply']['id'];
                $body = $message['interactive']['button_reply']['title'] ?? $interactiveId;
            } else {
                $body = $message['text']['body'] ?? ('[' . ($message['type'] ?? 'unsupported') . ' message]');
            }
            $enquiryId = findLeadIdByPhone($conn, $companyId, $from);
            $isNewInboundMessage = logWhatsAppInbound($conn, $companyId, $enquiryId, $from, $waMessageId, $body);

            // Guided-menu bot (whatsapp-bot-router.php): replies to every
            // inbound message (known leads included, per explicit user
            // request) unless staff have paused it for this phone (either by
            // sending a manual reply via whatsapp-chats.php, or the customer
            // asking for a human/AI expert). $isNewInboundMessage guards
            // against Meta retrying webhook delivery of the same message
            // re-firing this. A paused phone gets zero bot activity — not
            // even a session row is touched — until staff resume it.
            if ($isNewInboundMessage && !isWhatsAppBotPaused($conn, $companyId, $from)) {
                $session = getOrCreateWhatsAppBotSession($conn, $companyId, $from);
                routeWhatsAppBotMessage($conn, $companyId, $from, $session, $interactiveId, $body, $enquiryId);
            }
        }
        // Status updates (sent/delivered/read/failed) also arrive here via
        // $change['value']['statuses'] — intentionally ignored, only actual
        // customer replies are stored.
    }
}

echo json_encode(['success' => true]);
