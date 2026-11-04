<?php
// Shared, tenant-scoped lead query helpers used by crm-dashboard.php and
// view-leads.php, so the same SQL isn't duplicated in both files.
// Every function here requires $companyId and always filters by it.

function getLeadStatusCounts(mysqli $conn, int $companyId): array {
    $counts = [];
    $statusQueries = [
        'total' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=?",
        'new' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='New'",
        'contacted' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Contacted'",
        'closed' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Closed'",
        'not_interested' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Not Interested'",
        'work_done' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Work Done'",
        'industrial' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND type='commercial'",
        'residential' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND type='residential'",
        'weekly' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        'open_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open'",
        'overdue_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open' AND due_date < CURDATE() AND due_date IS NOT NULL",
        'due_soon_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)",
    ];
    foreach ($statusQueries as $key => $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $counts[$key] = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
    }
    return $counts;
}

function getLeadById(mysqli $conn, int $companyId, int $leadId): ?array {
    $stmt = $conn->prepare("SELECT * FROM enquiries WHERE id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getLeadUpdates(mysqli $conn, int $companyId, int $leadId, int $limit = 0): array {
    $sql = "SELECT * FROM enquiry_updates WHERE enquiry_id=? AND company_id=? ORDER BY created_at DESC";
    if ($limit > 0) {
        $sql .= " LIMIT " . $limit;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Only inserts if the enquiry actually belongs to this company —
// silently no-ops otherwise (defense against a spoofed enquiry id).
function addLeadUpdate(mysqli $conn, int $companyId, int $leadId, string $note): bool {
    if ($note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_updates (company_id, enquiry_id, note, created_at) SELECT ?, id, ?, NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('isii', $companyId, $note, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function addLeadFollowup(mysqli $conn, int $companyId, int $leadId, string $note, string $dueDate): bool {
    if ($note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_followups (company_id, enquiry_id, note, due_date, status, created_at) SELECT ?, id, ?, ?, 'Open', NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('issii', $companyId, $note, $dueDate, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function completeLeadFollowup(mysqli $conn, int $companyId, int $taskId): bool {
    $stmt = $conn->prepare("UPDATE enquiry_followups SET status='Done' WHERE id=? AND company_id=?");
    $stmt->bind_param('ii', $taskId, $companyId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();
    return $updated;
}

// Pipeline order. Quoted / Won / Paid are also set automatically by the
// CRM (App\Services\DashboardSyncService) when a quotation is sent,
// accepted, and its invoice fully paid.
const LEAD_STATUSES = ['New', 'Contacted', 'Quoted', 'Won', 'Paid', 'Work Done', 'Closed', 'Not Interested'];

function updateLeadStatus(mysqli $conn, int $companyId, int $leadId, string $status): bool {
    if (!in_array($status, LEAD_STATUSES, true)) {
        return false;
    }
    // First move to 'Work Done' starts the Google review request timer
    // (reminder.php); later edits never reset it.
    $stmt = $conn->prepare("UPDATE enquiries SET status=?, work_done_at = IF(?='Work Done', COALESCE(work_done_at, NOW()), work_done_at) WHERE id=? AND company_id=?");
    $stmt->bind_param('ssii', $status, $status, $leadId, $companyId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();
    return $updated;
}

function deleteLead(mysqli $conn, int $companyId, int $leadId): bool {
    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM enquiry_updates WHERE enquiry_id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM enquiry_followups WHERE enquiry_id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM enquiry_site_visits WHERE enquiry_id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM enquiries WHERE id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    $conn->commit();
    return $deleted;
}

function scheduleSiteVisit(mysqli $conn, int $companyId, int $leadId, string $visitDate, string $visitTime): bool {
    if ($visitDate === '' || $visitTime === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_site_visits (company_id, enquiry_id, visit_date, visit_time, created_at) SELECT ?, id, ?, ?, NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('issii', $companyId, $visitDate, $visitTime, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function getLatestSiteVisit(mysqli $conn, int $companyId, int $leadId): ?array {
    $stmt = $conn->prepare("SELECT * FROM enquiry_site_visits WHERE enquiry_id=? AND company_id=? ORDER BY visit_date DESC, visit_time DESC LIMIT 1");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// All scheduled site visits for the tenant (past and future), each joined with
// its lead's name/phone/email/location so the Site Visits dashboard view
// doesn't need a separate lookup per row.
function getAllSiteVisits(mysqli $conn, int $companyId): array {
    $stmt = $conn->prepare(
        "SELECT sv.id, sv.enquiry_id, sv.visit_date, sv.visit_time,
                e.name, e.phone, e.email, e.location
         FROM enquiry_site_visits sv
         JOIN enquiries e ON e.id = sv.enquiry_id AND e.company_id = sv.company_id
         WHERE sv.company_id=?
         ORDER BY sv.visit_date ASC, sv.visit_time ASC"
    );
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/* Generates fixed hourly site-visit slots (e.g. 9:00 AM - 10:00 AM) so visits don't overlap. */
function getSiteVisitSlots(string $start = '09:00', string $end = '18:00', int $stepMinutes = 60): array {
    $slots = [];
    $cursor = strtotime($start);
    $endTs = strtotime($end);
    while ($cursor < $endTs) {
        $slotEnd = $cursor + $stepMinutes * 60;
        $slots[] = [
            'start' => date('H:i', $cursor),
            'end' => date('H:i', $slotEnd),
            'label' => date('g:i A', $cursor) . ' - ' . date('g:i A', $slotEnd),
        ];
        $cursor = $slotEnd;
    }
    return $slots;
}

function logWhatsAppSend(mysqli $conn, int $companyId, int $leadId, string $templateName, bool $success): bool {
    $status = $success ? 'sent' : 'failed';
    $stmt = $conn->prepare("INSERT INTO enquiry_whatsapp_log (company_id, enquiry_id, template_name, status, created_at) SELECT ?, id, ?, ?, NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('issii', $companyId, $templateName, $status, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function getLatestWhatsAppSend(mysqli $conn, int $companyId, int $leadId, string $templateName): ?array {
    $stmt = $conn->prepare("SELECT * FROM enquiry_whatsapp_log WHERE enquiry_id=? AND company_id=? AND template_name=? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('iis', $leadId, $companyId, $templateName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Matches on the last 10 digits so it doesn't matter whether the stored lead
// phone or the incoming WhatsApp "from" number includes a country code.
function findLeadIdByPhone(mysqli $conn, int $companyId, string $phone): ?int {
    $stmt = $conn->prepare("SELECT id FROM enquiries WHERE company_id=? AND RIGHT(phone,10)=RIGHT(?,10) ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

// Mirrors a public-form enquiry into the Laravel ERP app's own Leads module
// (see sendmail.php's call site, right after a successful `enquiries`
// insert). Both apps share the same MySQL server/port, so this is a plain
// cross-database INSERT via the fully-qualified `{ERP_DB_NAME}.leads` table
// name on the *same* $conn — no second DB connection needed. Deliberately
// swallows every failure (missing config, unreachable ERP DB, schema
// mismatch, etc.) and never throws: the caller must not let a sync problem
// turn an already-successful enquiry submission into a failed response for
// the visitor. Only fires for ERP_SYNC_COMPANY_ID (see config.php) since
// this CRM app itself is multi-tenant and other tenants' enquiries must
// never leak into this one ERP instance.
// $channel ('website', 'whatsapp', 'voice', 'missed_call', 'meta_ads') picks
// the ERP lead subject and source — see erpSourceIdForChannel().
function syncEnquiryToErpLead(mysqli $conn, int $companyId, int $enquiryId, string $name, string $phone, string $email, string $type, string $notes, string $location = '', string $channel = 'website'): void {
    if (!defined('ERP_SYNC_ENABLED') || !ERP_SYNC_ENABLED) {
        return;
    }
    if (!defined('ERP_SYNC_COMPANY_ID') || $companyId !== (int)ERP_SYNC_COMPANY_ID) {
        return;
    }

    try {
        $db      = ERP_DB_NAME;
        $labels  = ['website' => 'Website', 'whatsapp' => 'WhatsApp', 'voice' => 'AI Voice Call', 'missed_call' => 'Missed Call', 'meta_ads' => 'Meta Ads'];
        $subject = ($labels[$channel] ?? 'Website') . ' Enquiry (' . ucfirst($type) . ')';
        // ERP leads.name is required; bot/voice leads arrive without one.
        if ($name === '') {
            $name = 'Lead ' . $phone;
        }

        $stmt = $conn->prepare(
            "INSERT INTO `{$db}`.`leads`
                (name, email, phone, location, subject, user_id, pipeline_id, stage_id, sources, notes, `order`, created_by, is_active, is_converted, date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, 0, CURDATE(), NOW(), NOW())"
        );
        if (!$stmt) {
            throw new \Exception('Prepare failed: ' . $conn->error);
        }

        $userId     = (int) ERP_LEAD_USER_ID;
        $pipelineId = (int) ERP_LEAD_PIPELINE_ID;
        $stageId    = (int) ERP_LEAD_STAGE_ID;
        $sources    = (string) erpSourceIdForChannel($channel);
        $createdBy  = (int) ERP_LEAD_CREATED_BY;

        $stmt->bind_param(
            'sssssiiissi',
            $name, $email, $phone, $location, $subject,
            $userId, $pipelineId, $stageId, $sources, $notes,
            $createdBy
        );

        if (!$stmt->execute()) {
            throw new \Exception('Execute failed: ' . $stmt->error);
        }
        $erpLeadId = $stmt->insert_id;
        $stmt->close();

        if ($erpLeadId) {
            $upd = $conn->prepare("UPDATE enquiries SET erp_lead_id = ? WHERE id = ?");
            $upd->bind_param('ii', $erpLeadId, $enquiryId);
            $upd->execute();
            $upd->close();
        }
    } catch (\Throwable $e) {
        error_log('ERP lead sync failed for enquiry #' . $enquiryId . ': ' . $e->getMessage());
    }
}

// Per-channel ERP sources.id, e.g. define('ERP_SOURCE_ID_WHATSAPP', 14) in
// config.php. Falls back to ERP_LEAD_SOURCE_ID ("Websites") when a channel
// has no source of its own yet.
function erpSourceIdForChannel(string $channel): int {
    $const = 'ERP_SOURCE_ID_' . strtoupper($channel);
    return defined($const) ? (int) constant($const) : (int) ERP_LEAD_SOURCE_ID;
}

// Bot/voice/missed-call leads are created without going through sendmail.php,
// so they sync here — only when the enquiry row is brand new, never for a
// note added to an existing lead (that lead was already synced, or predates
// the sync and shouldn't be duplicated).
function syncNewChannelEnquiryToErp(mysqli $conn, int $companyId, int $enquiryId, string $phone, string $notes, string $channel): void {
    if ($enquiryId > 0) {
        syncEnquiryToErpLead($conn, $companyId, $enquiryId, '', $phone, '', 'commercial', $notes, '', $channel);
    }
}

// Idempotent on wa_message_id — Meta retries webhook delivery on any
// non-2xx/slow response, so a duplicate delivery must not double-insert.
function logWhatsAppInbound(mysqli $conn, int $companyId, ?int $enquiryId, string $phone, string $waMessageId, string $messageBody): bool {
    $stmt = $conn->prepare("INSERT IGNORE INTO whatsapp_inbound_messages (company_id, enquiry_id, phone, wa_message_id, message_body, received_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('iisss', $companyId, $enquiryId, $phone, $waMessageId, $messageBody);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function getWhatsAppInboundMessages(mysqli $conn, int $companyId, int $leadId): array {
    $stmt = $conn->prepare("SELECT * FROM whatsapp_inbound_messages WHERE enquiry_id=? AND company_id=? ORDER BY received_at DESC");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getUnreadWhatsAppCount(mysqli $conn, int $companyId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM whatsapp_inbound_messages WHERE company_id=? AND is_read=0");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}

// One row per lead that has ever sent an inbound WhatsApp reply, most
// recently active first — this is the left-hand conversation list on
// whatsapp-chats.php, so staff can see who replied without opening every
// lead individually.
function getWhatsAppConversations(mysqli $conn, int $companyId): array {
    $stmt = $conn->prepare(
        "SELECT e.id AS enquiry_id, e.name, e.phone,
                m.message_body AS last_message, m.received_at AS last_received_at,
                (SELECT COUNT(*) FROM whatsapp_inbound_messages m2 WHERE m2.enquiry_id = e.id AND m2.company_id = e.company_id AND m2.is_read = 0) AS unread_count
         FROM whatsapp_inbound_messages m
         JOIN enquiries e ON e.id = m.enquiry_id AND e.company_id = m.company_id
         WHERE m.company_id = ? AND m.id = (
             SELECT MAX(m3.id) FROM whatsapp_inbound_messages m3 WHERE m3.enquiry_id = e.id AND m3.company_id = e.company_id
         )
         ORDER BY m.received_at DESC"
    );
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Merges inbound replies and outbound template sends into one chronological
// thread for a single lead, so whatsapp-chats.php can render it like a
// normal WhatsApp conversation (incoming vs outgoing bubbles).
function getWhatsAppThread(mysqli $conn, int $companyId, int $leadId): array {
    $thread = [];

    $stmt = $conn->prepare("SELECT message_body, received_at FROM whatsapp_inbound_messages WHERE enquiry_id=? AND company_id=? ORDER BY received_at ASC");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'in', 'body' => $row['message_body'], 'at' => $row['received_at']];
    }
    $stmt->close();

    $templateLabels = [
        WA_TEMPLATE_ENQUIRY_THANKYOU => 'Enquiry thank-you message sent',
        WA_TEMPLATE_SITE_VISIT => 'Site visit confirmation sent',
        WA_TEMPLATE_QUOTE_FOLLOWUP => 'Quotation follow-up sent',
    ];
    $stmt = $conn->prepare("SELECT template_name, status, created_at FROM enquiry_whatsapp_log WHERE enquiry_id=? AND company_id=? ORDER BY created_at ASC");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if ($row['status'] !== 'sent') {
            continue;
        }
        $label = $templateLabels[$row['template_name']] ?? ('Message sent (' . $row['template_name'] . ')');
        $thread[] = ['direction' => 'out', 'body' => $label, 'at' => $row['created_at']];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT reply_body, sent_at FROM whatsapp_bot_replies WHERE enquiry_id=? AND company_id=? ORDER BY sent_at ASC");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'out', 'body' => $row['reply_body'], 'at' => $row['sent_at']];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT reply_body, sent_at, delivery_status, error_message FROM whatsapp_staff_replies WHERE enquiry_id=? AND company_id=? ORDER BY sent_at ASC");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'out', 'body' => $row['reply_body'], 'at' => $row['sent_at'], 'delivery_status' => $row['delivery_status'], 'error_message' => $row['error_message']];
    }
    $stmt->close();

    usort($thread, fn($a, $b) => strtotime($a['at']) <=> strtotime($b['at']));
    return $thread;
}

function markWhatsAppRead(mysqli $conn, int $companyId, int $leadId): void {
    $stmt = $conn->prepare("UPDATE whatsapp_inbound_messages SET is_read=1 WHERE enquiry_id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $stmt->close();
}

// Records one rule-based bot reply (whatsapp-webhook.php), so it can be
// shown alongside the inbound message it answered on whatsapp-chats.php.
// $enquiryId is null for numbers with no matching lead.
function logWhatsAppBotReply(mysqli $conn, int $companyId, string $phone, string $replyBody, ?int $enquiryId = null): void {
    $stmt = $conn->prepare("INSERT INTO whatsapp_bot_replies (company_id, phone, enquiry_id, reply_body, sent_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('isis', $companyId, $phone, $enquiryId, $replyBody);
    $stmt->execute();
    $stmt->close();
}

// Decides whether missed-call-sms.php should send its WhatsApp message for
// this call, and records the decision. Rule: up to 2 sends per 30-day cycle
// per (company, phone) — the 1st and 2nd call in a cycle each get a message,
// further calls within the same 30 days are silently skipped, and once 30
// days have passed since the cycle started, the next call starts a fresh
// cycle (treated like a brand new enquiry). This keeps same-day repeat
// callers from being spammed, and stops nagging an already-converted
// customer who calls often for payment/work coordination.
function shouldSendMissedCallMessage(mysqli $conn, int $companyId, string $phone): bool {
    $stmt = $conn->prepare("SELECT cycle_started_at, send_count FROM missed_call_message_log WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        $stmt = $conn->prepare("INSERT INTO missed_call_message_log (company_id, phone, cycle_started_at, send_count) VALUES (?, ?, NOW(), 1)");
        $stmt->bind_param('is', $companyId, $phone);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    $daysSinceCycleStart = (new DateTime($row['cycle_started_at']))->diff(new DateTime())->days;

    if ($daysSinceCycleStart >= 30) {
        $stmt = $conn->prepare("UPDATE missed_call_message_log SET cycle_started_at = NOW(), send_count = 1 WHERE company_id=? AND phone=?");
        $stmt->bind_param('is', $companyId, $phone);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    if ((int)$row['send_count'] < 2) {
        $stmt = $conn->prepare("UPDATE missed_call_message_log SET send_count = send_count + 1 WHERE company_id=? AND phone=?");
        $stmt->bind_param('is', $companyId, $phone);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    return false;
}

// Whether the bot has ever replied to this phone before, for this company —
// decides whether the next inbound message from them gets the welcome menu
// (first contact) or a keyword-matched answer (they've seen the menu already).
function hasWhatsAppBotRepliedBefore(mysqli $conn, int $companyId, string $phone): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM whatsapp_bot_replies WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count > 0;
}

// Canonical key for anything that indexes whatsapp_bot_sessions/
// whatsapp_staff_replies by phone — strips everything but digits and keeps
// only the last 10, matching the fuzzy RIGHT(phone,10) matching
// findLeadIdByPhone() already uses. Without this, the SAME real number
// arrives in different string shapes from different sources (Meta's webhook
// `from` field always includes the country code, e.g. "918888111833", while
// a lead's stored `enquiries.phone` is often just the bare 10-digit number
// the customer typed on the enquiry form, e.g. "8888111833") — pausing from
// one side and resuming from the other would silently touch two different
// rows, leaving the bot stuck paused with the UI showing "active". Every
// function below canonicalizes internally so callers can pass phone in
// whatever format they have it.
function canonicalPhoneKey(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return substr($digits, -10);
}

// Guided-menu bot conversation state (whatsapp-bot-router.php) — one row per
// phone, upserted (not appended), since this is live "where are they in the
// menu tree" state, not a history log. Returns context_json already decoded
// into a plain array (empty array if null/absent) so callers never touch
// json_decode() directly.
function getOrCreateWhatsAppBotSession(mysqli $conn, int $companyId, string $phone): array {
    $phone = canonicalPhoneKey($phone);
    $stmt = $conn->prepare("INSERT INTO whatsapp_bot_sessions (company_id, phone) VALUES (?, ?) ON DUPLICATE KEY UPDATE phone = phone");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM whatsapp_bot_sessions WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $row['context'] = $row['context_json'] !== null ? (json_decode($row['context_json'], true) ?? []) : [];
    return $row;
}

function updateWhatsAppBotSession(mysqli $conn, int $companyId, string $phone, string $stage, array $context): bool {
    $phone = canonicalPhoneKey($phone);
    $contextJson = json_encode($context);
    $stmt = $conn->prepare("UPDATE whatsapp_bot_sessions SET stage=?, context_json=? WHERE company_id=? AND phone=?");
    $stmt->bind_param('ssis', $stage, $contextJson, $companyId, $phone);
    $stmt->execute();
    $updated = $stmt->affected_rows >= 0;
    $stmt->close();
    return $updated;
}

// Silences the guided-menu bot for this phone — used both when a customer
// asks for a human/AI expert and when staff sends a manual reply (see
// sendManualWhatsAppReply() below). Upserts rather than assuming a session
// row already exists, so staff can pre-emptively pause a phone that has
// never messaged the bot (e.g. before calling a fresh lead).
function pauseWhatsAppBot(mysqli $conn, int $companyId, string $phone, string $pausedBy): bool {
    $phone = canonicalPhoneKey($phone);
    $stmt = $conn->prepare("INSERT INTO whatsapp_bot_sessions (company_id, phone, is_paused, paused_at, paused_by) VALUES (?, ?, 1, NOW(), ?)
        ON DUPLICATE KEY UPDATE is_paused=1, paused_at=NOW(), paused_by=VALUES(paused_by)");
    $stmt->bind_param('iss', $companyId, $phone, $pausedBy);
    $stmt->execute();
    $stmt->close();
    return true;
}

// Called from whatsapp-webhook.php's status-callback handling — matches a
// Meta delivery status update back to the manual reply it belongs to via
// wa_message_id (set at send time by sendManualWhatsAppReply()) and records
// the outcome so whatsapp-chats.php can show staff a "failed to deliver"
// notice instead of leaving them to assume a 2xx send meant the customer
// actually got it. $status should be one of Meta's status values
// (sent/delivered/read/failed); only 'failed' needs $errorMessage.
function updateWhatsAppStaffReplyDeliveryStatus(mysqli $conn, string $waMessageId, string $status, ?string $errorMessage = null): bool {
    $stmt = $conn->prepare("UPDATE whatsapp_staff_replies SET delivery_status=?, error_message=? WHERE wa_message_id=?");
    $stmt->bind_param('sss', $status, $errorMessage, $waMessageId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();
    return $updated;
}

function resumeWhatsAppBot(mysqli $conn, int $companyId, string $phone): bool {
    $phone = canonicalPhoneKey($phone);
    $stmt = $conn->prepare("UPDATE whatsapp_bot_sessions SET is_paused=0, paused_at=NULL, paused_by=NULL WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $stmt->close();
    return true;
}

// No row at all means the bot has never been paused for this phone — not
// paused, by definition.
function isWhatsAppBotPaused(mysqli $conn, int $companyId, string $phone): bool {
    $phone = canonicalPhoneKey($phone);
    $stmt = $conn->prepare("SELECT is_paused FROM whatsapp_bot_sessions WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null && (int)$row['is_paused'] === 1;
}

// Sends a staff-authored WhatsApp reply from whatsapp-chats.php and pauses
// the guided-menu bot for this phone as a side effect — this is the single
// call site that hooks manual replies to the pause mechanism, so pausing
// can't be bypassed by a future new send path. Caller must require_once
// mailer.php for sendViaWhatsAppCloudApiText()/normalizeIndianPhoneForSms().
function sendManualWhatsAppReply(mysqli $conn, int $companyId, ?int $enquiryId, string $phone, string $body, int $userId): bool {
    if ($body === '') {
        return false;
    }
    $recipient = normalizeIndianPhoneForSms($phone);
    if ($recipient === null) {
        return false;
    }
    // $waMessageId lets a later status callback (whatsapp-webhook.php) find
    // this exact row and flip delivery_status to delivered/failed — Meta
    // accepts this send synchronously (2xx) even when it will actually fail
    // to deliver (e.g. outside the customer's 24h window), so a true return
    // here only means "Meta accepted the request", not "the customer got it".
    $waMessageId = null;
    if (!sendViaWhatsAppCloudApiText($recipient, $body, $waMessageId)) {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO whatsapp_staff_replies (company_id, enquiry_id, phone, user_id, reply_body, wa_message_id, delivery_status, sent_at) VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW())");
    $stmt->bind_param('iisiss', $companyId, $enquiryId, $phone, $userId, $body, $waMessageId);
    $stmt->execute();
    $stmt->close();

    pauseWhatsAppBot($conn, $companyId, $phone, 'staff:' . $userId);
    return true;
}

// Shared "stub" helper for guided-menu options that are out of scope for this
// phase (Instant Estimate, AI Expert, Site Visit, Quotation) — captures the
// customer's interest into the CRM (creating a minimal lead if none exists
// yet for this phone) rather than silently dead-ending the conversation.
// Real estimate/quotation/site-visit-booking engines are future phases; this
// just makes sure sales sees the request.
function captureWhatsAppBotInterest(mysqli $conn, int $companyId, string $phone, ?int $enquiryId, string $noteText): int {
    if ($enquiryId !== null) {
        addLeadUpdate($conn, $companyId, $enquiryId, $noteText);
    } else {
        // Deliberately omits created_at/updated_at from the column list (relying
        // on their DB defaults) — same convention as sendmail.php's enquiries
        // insert, and avoids assuming updated_at exists (some older tenant DBs
        // predate that column and were never migrated).
        $stmt = $conn->prepare("INSERT INTO enquiries (company_id, type, name, phone, email, message, status, source) VALUES (?, 'commercial', '', ?, '', ?, 'New', 'whatsapp')");
        $stmt->bind_param('iss', $companyId, $phone, $noteText);
        $stmt->execute();
        $enquiryId = $stmt->insert_id;
        $stmt->close();
        syncNewChannelEnquiryToErp($conn, $companyId, $enquiryId, $phone, $noteText, 'whatsapp');
    }
    notifyStaffOfBotInterest($companyId, 'WhatsApp bot lead interest', $noteText . ' — ' . $phone);
    return $enquiryId;
}

// Idempotent on conversation_id — ElevenLabs (like Meta) may retry webhook
// delivery, so a replayed post-call payload must not create a duplicate row.
function logVoiceCall(mysqli $conn, int $companyId, string $callerPhone, string $conversationId, string $callStatus, ?int $durationSeconds, ?string $summary, ?int $enquiryId): ?int {
    $stmt = $conn->prepare("INSERT IGNORE INTO voice_call_log (company_id, caller_phone, conversation_id, call_status, duration_seconds, transcript_summary, enquiry_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssisi', $companyId, $callerPhone, $conversationId, $callStatus, $durationSeconds, $summary, $enquiryId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $insertId = $stmt->insert_id;
    $stmt->close();
    return $inserted ? $insertId : null;
}

// Same shape as captureWhatsAppBotInterest() above, but for a phone call
// handled entirely by the ElevenLabs AI voice agent rather than the WhatsApp
// bot — kept separate so the staff push notification correctly says "AI
// voice call" instead of "WhatsApp bot lead interest".
function createEnquiryFromVoiceCall(mysqli $conn, int $companyId, string $phone, string $summary): int {
    $enquiryId = findLeadIdByPhone($conn, $companyId, $phone);
    if ($enquiryId !== null) {
        addLeadUpdate($conn, $companyId, $enquiryId, "AI voice call: " . $summary);
    } else {
        $stmt = $conn->prepare("INSERT INTO enquiries (company_id, type, name, phone, email, message, status, source) VALUES (?, 'commercial', '', ?, '', ?, 'New', 'voice')");
        $stmt->bind_param('iss', $companyId, $phone, $summary);
        $stmt->execute();
        $enquiryId = $stmt->insert_id;
        $stmt->close();
        syncNewChannelEnquiryToErp($conn, $companyId, $enquiryId, $phone, "AI voice call: " . $summary, 'voice');
    }
    notifyStaffOfBotInterest($companyId, 'AI voice call handled', $summary . ' — ' . $phone);
    return $enquiryId;
}

// Missed call on the business line (missed-call-sms.php) — logs the caller as
// a lead so a callback isn't forgotten, even if they never fill the form the
// WhatsApp message links to. No push here: missed-call-sms.php doesn't load
// the web-push vendor/config that notifyStaffOfBotInterest() needs.
function createEnquiryFromMissedCall(mysqli $conn, int $companyId, string $phone): int {
    $note = 'Missed call — call back';
    $enquiryId = findLeadIdByPhone($conn, $companyId, $phone);
    if ($enquiryId !== null) {
        addLeadUpdate($conn, $companyId, $enquiryId, $note);
        return $enquiryId;
    }
    $stmt = $conn->prepare("INSERT INTO enquiries (company_id, type, name, phone, email, message, status, source) VALUES (?, 'commercial', '', ?, '', ?, 'New', 'missed_call')");
    $stmt->bind_param('iss', $companyId, $phone, $note);
    $stmt->execute();
    $enquiryId = $stmt->insert_id;
    $stmt->close();
    syncNewChannelEnquiryToErp($conn, $companyId, $enquiryId, $phone, $note, 'missed_call');
    return $enquiryId;
}

// Customer replied STOP / UNSUBSCRIBE on WhatsApp (whatsapp-webhook.php).
function isWhatsAppOptOutMessage(string $body): bool {
    return in_array(strtoupper(trim($body)), ['STOP', 'UNSUBSCRIBE', 'STOP ALL'], true);
}

function recordWhatsAppOptOut(mysqli $conn, int $companyId, string $phone): void {
    $last10 = substr(preg_replace('/\D+/', '', $phone), -10);
    if (strlen($last10) !== 10) {
        return;
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO whatsapp_opt_outs (company_id, phone_last10) VALUES (?, ?)");
    $stmt->bind_param('is', $companyId, $last10);
    $stmt->execute();
    $stmt->close();
}

// Old Closed / Not Interested leads eligible for a win-back WhatsApp offer
// (reengage.php): older than $minAgeDays, not offered in the last 90 days,
// and never opted out.
function getReengageCandidates(mysqli $conn, int $companyId, int $minAgeDays = 60): array {
    $stmt = $conn->prepare(
        "SELECT e.id, e.name, e.phone, e.location, e.status, e.created_at, e.last_reengaged_at
         FROM enquiries e
         LEFT JOIN whatsapp_opt_outs o ON o.company_id = e.company_id AND o.phone_last10 = RIGHT(e.phone, 10)
         WHERE e.company_id = ? AND e.status IN ('Closed', 'Not Interested') AND e.phone <> ''
           AND e.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
           AND (e.last_reengaged_at IS NULL OR e.last_reengaged_at <= DATE_SUB(NOW(), INTERVAL 90 DAY))
           AND o.id IS NULL
         ORDER BY e.created_at DESC LIMIT 200"
    );
    $stmt->bind_param('ii', $companyId, $minAgeDays);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Meta Lead Ads lead (meta-leads-webhook.php). $fields is the lead form's
// field_data flattened to name => value. Standard Meta fields map to their
// columns; every other answer (area, floor type...) goes into the message,
// and an answer whose question mentions "area"/"sq" fills the area column so
// the CRM's Auto Quote can read it. An existing lead with the same phone just
// gets a note.
function createEnquiryFromMetaLead(mysqli $conn, int $companyId, array $fields, string $sourceDetail): int {
    $name = trim($fields['full_name'] ?? trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? '')));
    $phone = trim($fields['phone_number'] ?? '');
    $email = trim($fields['email'] ?? '');
    $location = trim($fields['city'] ?? ($fields['street_address'] ?? ''));

    $area = '';
    $answers = [];
    foreach ($fields as $key => $value) {
        if (in_array($key, ['full_name', 'first_name', 'last_name', 'phone_number', 'email', 'city', 'street_address'], true)) {
            continue;
        }
        if ($area === '' && preg_match('/area|sq/i', $key)) {
            $area = $value;
        }
        $answers[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
    }
    $message = implode("\n", $answers);

    $existingId = $phone !== '' ? findLeadIdByPhone($conn, $companyId, $phone) : null;
    if ($existingId !== null) {
        addLeadUpdate($conn, $companyId, $existingId, 'New Meta Ads enquiry' . ($message !== '' ? ":\n" . $message : ''));
        return $existingId;
    }

    $stmt = $conn->prepare("INSERT INTO enquiries (company_id, type, name, phone, email, location, area, message, status, source, source_detail) VALUES (?, 'commercial', ?, ?, ?, ?, ?, ?, 'New', 'meta_ads', ?)");
    $stmt->bind_param('isssssss', $companyId, $name, $phone, $email, $location, $area, $message, $sourceDetail);
    $stmt->execute();
    $enquiryId = $stmt->insert_id;
    $stmt->close();

    $notes = implode("\n", array_filter([
        $location !== '' ? "Location: $location" : '',
        $area !== '' ? "Area: $area" : '',
        $message,
        $sourceDetail !== '' ? "Source: $sourceDetail" : '',
    ]));
    syncEnquiryToErpLead($conn, $companyId, $enquiryId, $name, $phone, $email, 'commercial', $notes, $location, 'meta_ads');
    return $enquiryId;
}

// Same push-send logic as sendPushNotification() in sendmail.php/reminder.php
// (both full endpoint scripts, unsafe to require_once from a webhook — see
// the note in whatsapp-webhook.php), duplicated here rather than shared so
// this file only needs the safe vendor/autoload.php + push-config.php
// includes. Caller must have required those first.
function notifyStaffOfBotInterest(int $companyId, string $title, string $body): void {
    global $conn;
    try {
        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $stmt = $conn->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE company_id = ?");
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            return;
        }
        $payload = json_encode(['type' => 'whatsapp_bot_interest', 'title' => $title, 'body' => $body, 'url' => '/whatsapp-chats.php']);
        while ($row = $result->fetch_assoc()) {
            try {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth'],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
                if (!$report->isSuccess()) {
                    error_log('WhatsApp bot push send failure: ' . $report->getReason());
                }
            } catch (\Throwable $e) {
                error_log('WhatsApp bot push exception: ' . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('WhatsApp bot push notification error: ' . $e->getMessage());
    }
}

// getWhatsAppConversations()'s counterpart for numbers with NO matching lead
// yet (enquiry_id IS NULL) — these are invisible to getWhatsAppConversations()
// since it INNER JOINs enquiries, so without this the rule-based bot's
// conversations would never show up anywhere on whatsapp-chats.php.
function getUnknownWhatsAppConversations(mysqli $conn, int $companyId): array {
    $stmt = $conn->prepare(
        "SELECT m.phone,
                m.message_body AS last_message, m.received_at AS last_received_at,
                (SELECT COUNT(*) FROM whatsapp_inbound_messages m2 WHERE m2.phone = m.phone AND m2.company_id = m.company_id AND m2.enquiry_id IS NULL AND m2.is_read = 0) AS unread_count
         FROM whatsapp_inbound_messages m
         WHERE m.company_id = ? AND m.enquiry_id IS NULL AND m.id = (
             SELECT MAX(m3.id) FROM whatsapp_inbound_messages m3 WHERE m3.phone = m.phone AND m3.company_id = m.company_id AND m3.enquiry_id IS NULL
         )
         ORDER BY m.received_at DESC"
    );
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// getWhatsAppThread()'s counterpart for an unknown number — merges inbound
// messages with the bot's own logged replies into one chronological thread.
function getWhatsAppThreadByPhone(mysqli $conn, int $companyId, string $phone): array {
    $thread = [];

    $stmt = $conn->prepare("SELECT message_body, received_at FROM whatsapp_inbound_messages WHERE phone=? AND company_id=? AND enquiry_id IS NULL ORDER BY received_at ASC");
    $stmt->bind_param('si', $phone, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'in', 'body' => $row['message_body'], 'at' => $row['received_at']];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT reply_body, sent_at FROM whatsapp_bot_replies WHERE phone=? AND company_id=? ORDER BY sent_at ASC");
    $stmt->bind_param('si', $phone, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'out', 'body' => $row['reply_body'], 'at' => $row['sent_at']];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT reply_body, sent_at, delivery_status, error_message FROM whatsapp_staff_replies WHERE phone=? AND company_id=? ORDER BY sent_at ASC");
    $stmt->bind_param('si', $phone, $companyId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $thread[] = ['direction' => 'out', 'body' => $row['reply_body'], 'at' => $row['sent_at'], 'delivery_status' => $row['delivery_status'], 'error_message' => $row['error_message']];
    }
    $stmt->close();

    usort($thread, fn($a, $b) => strtotime($a['at']) <=> strtotime($b['at']));
    return $thread;
}

function markWhatsAppReadByPhone(mysqli $conn, int $companyId, string $phone): void {
    $stmt = $conn->prepare("UPDATE whatsapp_inbound_messages SET is_read=1 WHERE phone=? AND company_id=? AND enquiry_id IS NULL");
    $stmt->bind_param('si', $phone, $companyId);
    $stmt->execute();
    $stmt->close();
}

function addDailyNote(mysqli $conn, int $companyId, string $title, string $note): bool {
    if ($title === '' || $note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO daily_notes (company_id, title, note, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iss', $companyId, $title, $note);
    $stmt->execute();
    $stmt->close();
    return true;
}

/* Owner's daily CRM summary (reminder.php's morning send, and the owner
   replying "summary" on WhatsApp — whatsapp-webhook.php). Returns 'text':
   the full list, one block per lead, for a free-form message; and 'params':
   [date, 4 counts] for the WA_TEMPLATE_DAILY_SUMMARY template, which can't
   carry line breaks so it only holds the counts. "Quotations to send" = site
   visit done but lead still New/Contacted. Dates are IST. */
function buildOwnerDailySummary(mysqli $conn, int $companyId): array {
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $today = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

    $fetch = function ($sql, $date) use ($conn, $companyId) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $companyId, $date);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    };
    $newLeads = $fetch("SELECT name, phone, location, area FROM enquiries WHERE company_id = ? AND DATE(created_at) = ? ORDER BY created_at", $yesterday);
    $visits = $fetch("SELECT e.name, e.phone, e.location, e.area, sv.visit_time FROM enquiry_site_visits sv JOIN enquiries e ON sv.enquiry_id = e.id AND e.company_id = sv.company_id WHERE sv.company_id = ? AND sv.visit_date = ? ORDER BY sv.visit_time", $today);
    $quotes = $fetch("SELECT e.name, e.phone, e.location, e.area, MAX(sv.visit_date) AS visited FROM enquiries e JOIN enquiry_site_visits sv ON sv.enquiry_id = e.id AND sv.company_id = e.company_id WHERE e.company_id = ? AND e.status IN ('New', 'Contacted') AND sv.visit_date < ? GROUP BY e.id ORDER BY visited DESC", $today);
    $followups = $fetch("SELECT e.name, e.phone, e.location, e.area, ef.note FROM enquiry_followups ef JOIN enquiries e ON ef.enquiry_id = e.id AND e.company_id = ef.company_id WHERE ef.company_id = ? AND ef.status = 'Open' AND ef.due_date <= ? ORDER BY ef.due_date", $today);

    // One lead = name line, then location/area and phone on their own lines.
    $leadBlock = function ($n, $row, $extraLine = '') {
        $name = trim((string) $row['name']) !== '' ? trim($row['name']) : 'No name';
        $place = array_filter([
            trim((string) ($row['location'] ?? '')) !== '' ? '📍 ' . trim($row['location']) : '',
            trim((string) ($row['area'] ?? '')) !== '' ? '📐 ' . trim($row['area']) : '',
        ]);
        $lines = ["$n. *$name*"];
        if ($place) {
            $lines[] = '    ' . implode('  |  ', $place);
        }
        $lines[] = '    📞 ' . $row['phone'];
        if ($extraLine !== '') {
            $lines[] = '    ' . $extraLine;
        }
        return implode("\n", $lines);
    };
    $section = function ($icon, $title, $rows, $extra = null) use ($leadBlock) {
        $text = "$icon *$title: " . count($rows) . '*';
        $shown = array_slice($rows, 0, 10);
        foreach ($shown as $i => $row) {
            $text .= "\n\n" . $leadBlock($i + 1, $row, $extra ? $extra($row) : '');
        }
        if (count($rows) > count($shown)) {
            $text .= "\n\n…and " . (count($rows) - count($shown)) . ' more in the CRM';
        }
        return $text;
    };

    $dateLabel = $now->format('d M Y');
    $divider = "\n\n━━━━━━━━━━━━\n\n";
    $text = "📊 *CRM Summary – $dateLabel*" . $divider
        . $section('🆕', 'New enquiries yesterday', $newLeads) . $divider
        . $section('🚗', 'Site visits today', $visits, fn($r) => '🕐 ' . date('g:i A', strtotime($r['visit_time']))) . $divider
        . $section('📝', 'Quotations to send', $quotes, fn($r) => '🗓 Visited ' . date('d M', strtotime($r['visited']))) . $divider
        . $section('🔔', 'Follow-ups due', $followups, fn($r) => '💬 ' . preg_replace('/\s+/', ' ', trim($r['note']))) . $divider
        . '🔗 ' . rtrim(SITE_URL, '/') . '/crm-dashboard.php';

    // WhatsApp caps a text message at 4096 characters.
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3950) . "\n\n…(cut short, see the CRM)";
    }

    return [
        'text' => $text,
        'params' => [$dateLabel, (string) count($newLeads), (string) count($visits), (string) count($quotes), (string) count($followups)],
    ];
}

/* True when this inbound WhatsApp is the owner (DAILY_SUMMARY_PHONE) asking
   for the full summary list. */
function isOwnerSummaryRequest(string $fromPhone, string $body): bool {
    if (!defined('DAILY_SUMMARY_PHONE') || DAILY_SUMMARY_PHONE === '') {
        return false;
    }
    $owner = substr(preg_replace('/\D/', '', DAILY_SUMMARY_PHONE), -10);
    return substr(preg_replace('/\D/', '', $fromPhone), -10) === $owner
        && preg_match('/^\s*(summary|details|report)\s*[.!]?\s*$/i', $body) === 1;
}
