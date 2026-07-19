<?php
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    throw new Exception("Database Connection Failed: " . $conn->connect_error);
}

// Companies (tenants)
$conn->query("CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'trial',
    valid_until DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 14 DAY),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Manual top-up payments (Razorpay) — audit trail; enforcement reads
// companies.valid_until directly, this table is not consulted at request time.
$conn->query("CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    razorpay_order_id VARCHAR(64) NOT NULL,
    razorpay_payment_id VARCHAR(64) DEFAULT NULL,
    amount_paise INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'created',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Basic abuse throttle for public endpoints (sendmail.php, subscribe.php).
// One row per (action, identifier e.g. ip+form_token); window resets after
// rate_limits.window_seconds, no Redis needed at this scale.
$conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
    rate_key VARCHAR(191) NOT NULL PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Users (per-company logins, replaces single hardcoded admin)
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'staff',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email_per_company (company_id, email),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Password reset tokens
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Per-tenant branding/config
$conn->query("CREATE TABLE IF NOT EXISTS tenant_settings (
    company_id INT NOT NULL PRIMARY KEY,
    company_display_name VARCHAR(255),
    logo_path VARCHAR(255),
    theme_color VARCHAR(7) DEFAULT '#0f4a78',
    smtp_from_name VARCHAR(255),
    smtp_from_email VARCHAR(255),
    notify_email VARCHAR(255),
    form_token VARCHAR(64) NOT NULL,
    settings_json JSON NULL,
    UNIQUE KEY unique_form_token (form_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Lead update notes table
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_enquiry (company_id, enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Follow-up tasks table
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT NOT NULL,
    note TEXT NOT NULL,
    due_date DATE DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_status_due (company_id, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Scheduled site visits for a lead
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_site_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT NOT NULL,
    visit_date DATE NOT NULL,
    visit_time TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_enquiry (company_id, enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Log of manually-triggered WhatsApp template sends from the CRM dashboard
// (e.g. the "Send Quotation Follow-up" button) — lets the dashboard show
// "last sent" status per lead instead of staff re-sending blind.
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_whatsapp_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_enquiry (company_id, enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Incoming WhatsApp messages (customer replies), received via
// whatsapp-webhook.php. unique_wa_message_id makes inserts idempotent since
// Meta retries webhook delivery on any non-2xx/slow response.
$conn->query("CREATE TABLE IF NOT EXISTS whatsapp_inbound_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    wa_message_id VARCHAR(100) NOT NULL,
    message_body TEXT,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_wa_message_id (wa_message_id),
    INDEX idx_company_enquiry (company_id, enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// is_read powers the unread badge/count on the WhatsApp Chats page — added
// after the table above already existed in production, so it's an idempotent
// ALTER (checked via information_schema) rather than part of the CREATE, same
// pattern as migrate_multitenant.php.
$hasIsReadColumn = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_inbound_messages' AND COLUMN_NAME = 'is_read'"
)->fetch_assoc()['c'] > 0;
if (!$hasIsReadColumn) {
    $conn->query("ALTER TABLE whatsapp_inbound_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER message_body");
}

// Append-only log of the rule-based WhatsApp bot's replies (whatsapp-webhook.php)
// — lets whatsapp-chats.php show what the bot told each number, alongside
// their inbound messages. Also doubles as the "have we ever replied to this
// phone before" marker that decides whether the next inbound message gets
// the welcome menu or a keyword-matched answer.
$conn->query("CREATE TABLE IF NOT EXISTS whatsapp_bot_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    reply_body TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_phone (company_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// enquiry_id lets a bot reply be linked to a known lead (added after the
// table above already existed in production once known-lead numbers were
// also allowed to get bot replies) — idempotent ALTER, same pattern as the
// whatsapp_inbound_messages.is_read column above.
$hasBotReplyEnquiryIdColumn = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_bot_replies' AND COLUMN_NAME = 'enquiry_id'"
)->fetch_assoc()['c'] > 0;
if (!$hasBotReplyEnquiryIdColumn) {
    $conn->query("ALTER TABLE whatsapp_bot_replies ADD COLUMN enquiry_id INT DEFAULT NULL AFTER phone");
}

// Rate-limits missed-call-sms.php's WhatsApp send per caller: up to 2 sends
// per 30-day cycle (see shouldSendMissedCallMessage() in leads.php), so a
// number that calls repeatedly in one day — or an already-converted customer
// calling often for payment/work coordination — doesn't get the "please
// enquire" message every single time they call.
$conn->query("CREATE TABLE IF NOT EXISTS missed_call_message_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    cycle_started_at DATETIME NOT NULL,
    send_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY unique_company_phone (company_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Live conversation state for the guided-menu WhatsApp bot (whatsapp-webhook.php
// / whatsapp-bot-router.php) — one row per phone (upserted, not appended),
// since this tracks where a phone currently is in the menu tree plus its
// selections, not a history log. context_json is a flexible JSON blob (not
// fixed columns) so later phases (estimate/quotation flows) can add fields
// without needing new ALTERs. is_paused/paused_at/paused_by let staff take
// over a conversation (via whatsapp-chats.php) without the bot talking over
// them; a paused phone gets zero bot activity until resumed.
$conn->query("CREATE TABLE IF NOT EXISTS whatsapp_bot_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    stage VARCHAR(40) NOT NULL DEFAULT 'welcome',
    context_json TEXT DEFAULT NULL,
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    paused_at DATETIME DEFAULT NULL,
    paused_by VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company_phone (company_id, phone),
    INDEX idx_company_stage (company_id, stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Staff-authored WhatsApp replies sent manually from whatsapp-chats.php.
// Kept separate from whatsapp_bot_replies (which means "the bot said this" and
// is read by hasWhatsAppBotRepliedBefore()-style logic) so human-authored
// messages never get confused with bot output.
$conn->query("CREATE TABLE IF NOT EXISTS whatsapp_staff_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    enquiry_id INT DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    user_id INT NOT NULL,
    reply_body TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_enquiry (company_id, enquiry_id),
    INDEX idx_company_phone (company_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Daily notes table
$conn->query("CREATE TABLE IF NOT EXISTS daily_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Push subscriptions table
$conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(255)),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Main Enquiries Table
$conn->query("CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'commercial',
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    location VARCHAR(255),
    address TEXT,
    area VARCHAR(100),
    slab VARCHAR(100),
    industry_usage VARCHAR(255),
    work_type VARCHAR(500),
    heavyload VARCHAR(50),
    timeline VARCHAR(100),
    epoxy_type VARCHAR(255),
    thickness VARCHAR(50),
    message TEXT,
    budget VARCHAR(100),
    concrete_grade VARCHAR(50),
    slab_age VARCHAR(100),
    cracks VARCHAR(255),
    contamination VARCHAR(100),
    previous_coating VARCHAR(100),
    industry_type VARCHAR(100),
    forklift VARCHAR(50),
    max_load VARCHAR(100),
    chemical_exposure VARCHAR(100),
    moisture_issue VARCHAR(50),
    water_washing VARCHAR(50),
    anti_skid VARCHAR(50),
    preferred_color VARCHAR(100),
    finish_type VARCHAR(100),
    line_marking VARCHAR(50),
    start_date DATE,
    urgent VARCHAR(50),
    working_hours VARCHAR(100),
    status VARCHAR(50) NOT NULL DEFAULT 'New',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_status (company_id, status),
    INDEX idx_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
?>
