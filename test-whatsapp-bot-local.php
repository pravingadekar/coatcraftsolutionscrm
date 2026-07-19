<?php
// DEV-ONLY local test tool — NOT deployed/used in production. Simulates one
// inbound WhatsApp message hitting whatsapp-webhook.php (HMAC-signed, same as
// a real Meta callback) so you can drive the guided-menu bot's Stage 1-4 tree
// without a real phone or an ngrok tunnel. Run from CLI only:
//
//   php test-whatsapp-bot-local.php --phone 919999900001 --text "hi"
//   php test-whatsapp-bot-local.php --phone 919999900001 --id "menu:industry"
//   php test-whatsapp-bot-local.php --phone 919999900001 --id "industry:warehouse"
//
// --phone: any digits-only test number. Use a fake one (e.g. 91999990000X)
// to just check the DB state below. Use your OWN real WhatsApp number
// instead if you want to actually SEE the bot's list/button messages arrive
// on your phone (sending doesn't need a tunnel, only Meta receiving your
// replies back would — so tap what you want, then re-run this script with
// --id set to whatever you tapped, to keep the conversation moving).
//
// --text "..."   simulates typed text (used for the very first message, or
//                free-text fallback testing).
// --id "..."     simulates tapping a list/button reply (menu:industry,
//                industry:warehouse, svc:pu_concrete, svcdetail:price:...,
//                menu:human_expert, etc. — see whatsapp-bot-router.php for
//                all ids each stage accepts).
// --title "..."  optional, human-readable text stored alongside --id
//                (defaults to the id itself if omitted).
//
// After sending, prints the phone's current whatsapp_bot_sessions row (stage
// + context) and its most recent logged bot reply, so you can see the
// transition without opening a DB tool. Then open whatsapp-chats.php in your
// browser (normal login) to see the full chat thread as bubbles.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a CLI-only dev tool — run it with `php test-whatsapp-bot-local.php ...`, not over HTTP.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';

// Hand-rolled parser instead of getopt() — getopt()'s optional-value long
// options (the "::" syntax) silently drop the value when passed as
// `--id "foo"` (space-separated) rather than `--id=foo`, which is an easy
// trap to fall into from the command line. This accepts both forms.
function parseArg(array $argv, string $name): ?string {
    foreach ($argv as $i => $arg) {
        if ($arg === "--$name" && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen("--$name="));
        }
    }
    return null;
}

$phone = preg_replace('/\D/', '', parseArg($argv, 'phone') ?? '');
$text = parseArg($argv, 'text');
$interactiveId = parseArg($argv, 'id');
$title = parseArg($argv, 'title') ?? $interactiveId;

if ($phone === '' || ($text === null && $interactiveId === null)) {
    fwrite(STDERR, "Usage: php test-whatsapp-bot-local.php --phone <digits> (--text \"message\" | --id \"menu:industry\" [--title \"...\"])\n");
    exit(1);
}

$message = [
    'from' => $phone,
    'id' => 'wamid.LOCALTEST' . time() . rand(1000, 9999),
    'timestamp' => (string)time(),
];
if ($interactiveId !== null) {
    $message['type'] = 'interactive';
    // Meta sends list_reply for list-menu taps and button_reply for
    // button taps — the router treats both the same way (reads .id), so
    // list_reply is fine for either kind of simulated tap here.
    $message['interactive'] = ['list_reply' => ['id' => $interactiveId, 'title' => $title]];
} else {
    $message['type'] = 'text';
    $message['text'] = ['body' => $text];
}

$payload = ['entry' => [['changes' => [['value' => ['messages' => [$message]]]]]]];
$rawBody = json_encode($payload);
$signature = 'sha256=' . hash_hmac('sha256', $rawBody, WA_APP_SECRET);

// Hardcoded rather than derived from $_SERVER — this runs under the CLI SAPI,
// which doesn't populate SCRIPT_NAME/HTTP request globals the way Apache does.
// Adjust if your local XAMPP vhost path ever changes.
$webhookUrl = 'http://localhost/CoatCraftSolutions/CoatCraftSolutions/whatsapp-webhook.php';
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . $signature],
    CURLOPT_POSTFIELDS => $rawBody,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "Sent to: $webhookUrl\n";
echo "HTTP: $httpCode " . ($curlError ? "(curl error: $curlError)" : '') . "\n";
echo "Response: $response\n\n";

$companyId = MISSED_CALL_COMPANY_ID;

$stmt = $conn->prepare("SELECT stage, context_json, is_paused, paused_by, updated_at FROM whatsapp_bot_sessions WHERE company_id=? AND phone=?");
$stmt->bind_param('is', $companyId, $phone);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($session) {
    echo "Session state for $phone:\n";
    echo "  stage:   {$session['stage']}\n";
    echo "  context: {$session['context_json']}\n";
    echo "  paused:  " . ($session['is_paused'] ? "YES (by {$session['paused_by']})" : 'no') . "\n";
} else {
    echo "No session row yet for $phone.\n";
}

$stmt = $conn->prepare("SELECT reply_body, sent_at FROM whatsapp_bot_replies WHERE company_id=? AND phone=? ORDER BY sent_at DESC LIMIT 1");
$stmt->bind_param('is', $companyId, $phone);
$stmt->execute();
$lastReply = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($lastReply) {
    echo "\nMost recent bot reply sent ({$lastReply['sent_at']}):\n---\n{$lastReply['reply_body']}\n---\n";
} else {
    echo "\nNo bot reply was logged for this message (either nothing matched, or the Meta send failed — check php_error_log for 'WhatsApp Cloud API ... failed').\n";
}

echo "\nOpen whatsapp-chats.php?phone=$phone (or the matching lead's ?id=) in your browser to see the full thread.\n";
