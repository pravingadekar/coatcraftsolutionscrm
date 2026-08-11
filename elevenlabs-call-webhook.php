<?php
// ElevenLabs Conversational AI post-call webhook — receives a payload after
// the AI voice agent finishes handling a phone call (forwarded from the
// business line when unanswered/busy) and logs it into the CRM as a lead.
// The live call audio itself never touches this app — it's bridged directly
// between Twilio and ElevenLabs via their dashboard integration. Configure
// this URL as the agent's post-call webhook in ElevenLabs dashboard >
// Conversational AI > agent > Webhooks, and paste the signing secret shown
// there into ELEVENLABS_WEBHOOK_SECRET in config.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/mailer.php';
// NOTE: deliberately NOT requiring sendmail.php/reminder.php — see the same
// note in whatsapp-webhook.php. notifyStaffOfBotInterest() (leads.php)
// reimplements the push-send logic using just the safe includes below.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/push-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input');

// ElevenLabs signs webhooks as "ElevenLabs-Signature: t=<unix_ts>,v0=<hex_hmac>"
// where the hmac is HMAC-SHA256 over "{timestamp}.{rawBody}" — verify before
// touching the DB, same reasoning as the X-Hub-Signature-256 check in
// whatsapp-webhook.php. Also reject stale timestamps (>30 min) to block replay.
$signatureHeader = $_SERVER['HTTP_ELEVENLABS_SIGNATURE'] ?? '';
$parts = [];
foreach (explode(',', $signatureHeader) as $part) {
    $kv = explode('=', $part, 2);
    if (count($kv) === 2) {
        $parts[$kv[0]] = $kv[1];
    }
}
$timestamp = $parts['t'] ?? '';
$signature = $parts['v0'] ?? '';

if ($timestamp === '' || $signature === '' || abs(time() - (int)$timestamp) > 1800) {
    http_response_code(401);
    exit;
}

$expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, ELEVENLABS_WEBHOOK_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit;
}

// Answer 200 immediately — ElevenLabs retries (and can disable) the webhook
// on slow/non-2xx responses, so everything after this is fast DB work only.
http_response_code(200);
header('Content-Type: application/json');

$payload = json_decode($rawBody, true) ?? [];
$data = $payload['data'] ?? [];

if (($payload['type'] ?? '') === 'post_call_transcription' && !empty($data['conversation_id'])) {
    $companyId = ELEVENLABS_CALL_COMPANY_ID;
    $conversationId = $data['conversation_id'];
    $callStatus = $data['status'] ?? 'unknown';
    $durationSecs = isset($data['metadata']['call_duration_secs']) ? (int)$data['metadata']['call_duration_secs'] : null;
    $summary = $data['analysis']['transcript_summary'] ?? '';

    // Caller phone number location isn't fixed across call types — check the
    // known candidate paths (dynamic variable, Twilio-native metadata, raw
    // Twilio StatusCallback body) and fall back to empty if none are present
    // rather than failing the whole webhook over a missing phone number.
    $rawCallerPhone = $data['conversation_initiation_client_data']['dynamic_variables']['system__caller_id']
        ?? $data['metadata']['phone_call']['external_number']
        ?? $data['metadata']['body']['From']
        ?? '';
    $callerPhone = $rawCallerPhone !== '' ? (normalizeIndianPhoneForSms($rawCallerPhone) ?? $rawCallerPhone) : '';

    $enquiryId = $callerPhone !== '' ? findLeadIdByPhone($conn, $companyId, $callerPhone) : null;

    $inserted = logVoiceCall($conn, $companyId, $callerPhone, $conversationId, $callStatus, $durationSecs, $summary, $enquiryId);

    // Only create/update a CRM lead on first delivery of this conversation
    // (logVoiceCall returns null on a duplicate/retry) and when we actually
    // have a phone number to attach the lead to.
    if ($inserted !== null && $callerPhone !== '') {
        createEnquiryFromVoiceCall($conn, $companyId, $callerPhone, $summary !== '' ? $summary : 'AI voice agent call (' . $callStatus . ')');
    }
}

echo json_encode(['success' => true]);
