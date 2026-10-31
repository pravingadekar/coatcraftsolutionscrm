<?php
// Meta Lead Ads webhook — a Facebook/Instagram lead form submission becomes
// an enquiry here (source = meta_ads) and is synced into the CRM, the same as
// a website form lead.
//
// Setup (Meta App dashboard > Webhooks > Page > subscribe to "leadgen"):
//   Callback URL:  https://<this host>/meta-leads-webhook.php
//   Verify token:  META_LEADS_VERIFY_TOKEN (config.php)
// The webhook only carries the lead id; the answers are fetched from the
// Graph API with META_PAGE_ACCESS_TOKEN (a Page token with leads_retrieval).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
// Safe includes only — see the note in whatsapp-webhook.php.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/push-config.php';

if (!defined('META_LEADS_VERIFY_TOKEN') || META_LEADS_VERIFY_TOKEN === '' || !defined('META_PAGE_ACCESS_TOKEN') || META_PAGE_ACCESS_TOKEN === '') {
    http_response_code(503);
    exit;
}

// One-time verification handshake when the callback URL is saved.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals(META_LEADS_VERIFY_TOKEN, $_GET['hub_verify_token'] ?? '')) {
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

// Same app secret as the WhatsApp webhook unless the Page uses another app.
$appSecret = defined('META_APP_SECRET') && META_APP_SECRET !== '' ? META_APP_SECRET : WA_APP_SECRET;
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
if ($signatureHeader === '' || !hash_equals($expectedSignature, $signatureHeader)) {
    http_response_code(401);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');

$companyId = defined('META_LEADS_COMPANY_ID') ? (int) META_LEADS_COMPANY_ID : (int) ERP_SYNC_COMPANY_ID;
$payload = json_decode($rawBody, true) ?? [];

foreach (($payload['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        if (($change['field'] ?? '') !== 'leadgen') {
            continue;
        }
        $leadgenId = (string) ($change['value']['leadgen_id'] ?? '');
        if ($leadgenId === '' || !preg_match('/^\d+$/', $leadgenId)) {
            continue;
        }

        // Claim the lead id first (INSERT IGNORE on a unique key) so a retried
        // delivery arriving mid-processing can't create a duplicate.
        $stmt = $conn->prepare("INSERT IGNORE INTO meta_lead_log (company_id, leadgen_id) VALUES (?, ?)");
        $stmt->bind_param('is', $companyId, $leadgenId);
        $stmt->execute();
        $isNew = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$isNew) {
            continue;
        }

        $lead = fetchMetaLead($leadgenId);
        if ($lead === null) {
            // Release the claim so Meta's retry can try again.
            $stmt = $conn->prepare("DELETE FROM meta_lead_log WHERE leadgen_id = ? AND enquiry_id IS NULL");
            $stmt->bind_param('s', $leadgenId);
            $stmt->execute();
            $stmt->close();
            http_response_code(500);
            continue;
        }

        $fields = [];
        foreach (($lead['field_data'] ?? []) as $field) {
            $fields[(string) ($field['name'] ?? '')] = implode(', ', (array) ($field['values'] ?? []));
        }
        unset($fields['']);

        $sourceDetail = substr(implode('; ', array_filter([
            !empty($lead['campaign_name']) ? 'campaign=' . $lead['campaign_name'] : '',
            !empty($lead['ad_name']) ? 'ad=' . $lead['ad_name'] : '',
            !empty($lead['form_id']) ? 'form=' . $lead['form_id'] : '',
            'leadgen=' . $leadgenId,
        ])), 0, 500);

        $enquiryId = createEnquiryFromMetaLead($conn, $companyId, $fields, $sourceDetail);

        $stmt = $conn->prepare("UPDATE meta_lead_log SET enquiry_id = ? WHERE leadgen_id = ?");
        $stmt->bind_param('is', $enquiryId, $leadgenId);
        $stmt->execute();
        $stmt->close();

        notifyStaffOfBotInterest($companyId, 'New Meta Ads lead', trim(($fields['full_name'] ?? '') . ' — ' . ($fields['phone_number'] ?? '')));
    }
}

echo json_encode(['ok' => true]);

// Graph API lookup of one lead's answers; null on any failure.
function fetchMetaLead(string $leadgenId): ?array {
    $version = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : WA_API_VERSION;
    $url = 'https://graph.facebook.com/' . $version . '/' . $leadgenId
        . '?fields=field_data,campaign_name,ad_name,form_id,created_time'
        . '&access_token=' . urlencode(META_PAGE_ACCESS_TOKEN);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        error_log('Meta lead fetch failed for ' . $leadgenId . ' (HTTP ' . $status . '): ' . substr((string) $body, 0, 500));
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
