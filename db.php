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
