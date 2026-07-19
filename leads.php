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

function updateLeadStatus(mysqli $conn, int $companyId, int $leadId, string $status): bool {
    $stmt = $conn->prepare("UPDATE enquiries SET status=? WHERE id=? AND company_id=?");
    $stmt->bind_param('sii', $status, $leadId, $companyId);
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

// Guided-menu bot conversation state (whatsapp-bot-router.php) — one row per
// phone, upserted (not appended), since this is live "where are they in the
// menu tree" state, not a history log. Returns context_json already decoded
// into a plain array (empty array if null/absent) so callers never touch
// json_decode() directly.
function getOrCreateWhatsAppBotSession(mysqli $conn, int $companyId, string $phone): array {
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
    $stmt = $conn->prepare("UPDATE whatsapp_bot_sessions SET is_paused=0, paused_at=NULL, paused_by=NULL WHERE company_id=? AND phone=?");
    $stmt->bind_param('is', $companyId, $phone);
    $stmt->execute();
    $stmt->close();
    return true;
}

// No row at all means the bot has never been paused for this phone — not
// paused, by definition.
function isWhatsAppBotPaused(mysqli $conn, int $companyId, string $phone): bool {
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
        $stmt = $conn->prepare("INSERT INTO enquiries (company_id, type, name, phone, email, message, status) VALUES (?, 'commercial', '', ?, '', ?, 'New')");
        $stmt->bind_param('iss', $companyId, $phone, $noteText);
        $stmt->execute();
        $enquiryId = $stmt->insert_id;
        $stmt->close();
    }
    notifyStaffOfBotInterest($companyId, 'WhatsApp bot lead interest', $noteText . ' — ' . $phone);
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
