<?php
// ONE-TIME migration: converts the existing single-tenant data into
// multi-tenant schema, with CoatCraft becoming company_id = 1.
// Safe to re-run — every step checks before acting.

require __DIR__ . '/db.php'; // also creates companies/users/tenant_settings if missing

function columnExists(mysqli $conn, string $table, string $column): bool {
    $dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count > 0;
}

function indexExists(mysqli $conn, string $table, string $indexName): bool {
    $dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param('sss', $dbName, $table, $indexName);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count > 0;
}

$log = [];

// 1) Add company_id column (+ backfill default 0) to the 5 pre-existing tables
$tablesToAlter = ['enquiries', 'enquiry_updates', 'enquiry_followups', 'daily_notes', 'push_subscriptions'];
foreach ($tablesToAlter as $table) {
    if (!columnExists($conn, $table, 'company_id')) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN company_id INT NOT NULL DEFAULT 0 AFTER id");
        $log[] = "Added company_id column to $table";
    } else {
        $log[] = "$table already has company_id, skipped";
    }
}

// 2) Add the composite indexes (idempotent check first)
$indexesToAdd = [
    'enquiries' => [
        'idx_company_status' => "ALTER TABLE enquiries ADD INDEX idx_company_status (company_id, status)",
        'idx_company_created' => "ALTER TABLE enquiries ADD INDEX idx_company_created (company_id, created_at)",
    ],
    'enquiry_updates' => [
        'idx_company_enquiry' => "ALTER TABLE enquiry_updates ADD INDEX idx_company_enquiry (company_id, enquiry_id)",
    ],
    'enquiry_followups' => [
        'idx_company_status_due' => "ALTER TABLE enquiry_followups ADD INDEX idx_company_status_due (company_id, status, due_date)",
    ],
    'daily_notes' => [
        'idx_company_created' => "ALTER TABLE daily_notes ADD INDEX idx_company_created (company_id, created_at)",
    ],
    'push_subscriptions' => [
        'idx_company' => "ALTER TABLE push_subscriptions ADD INDEX idx_company (company_id)",
    ],
];
foreach ($indexesToAdd as $table => $indexes) {
    foreach ($indexes as $indexName => $sql) {
        if (!indexExists($conn, $table, $indexName)) {
            $conn->query($sql);
            $log[] = "Added index $indexName on $table";
        } else {
            $log[] = "Index $indexName on $table already exists, skipped";
        }
    }
}

// 2b) Add valid_until to companies (billing enforcement) + backfill existing rows.
// Trial companies get the standard 14-day window from now; already-active companies
// (the seeded production tenant) get a generous 1-year grace period so this migration
// never locks out a paying customer who predates billing enforcement.
if (!columnExists($conn, 'companies', 'valid_until')) {
    $conn->query("ALTER TABLE companies ADD COLUMN valid_until DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 14 DAY) AFTER status");
    $conn->query("UPDATE companies SET valid_until = NOW() + INTERVAL 14 DAY WHERE status = 'trial'");
    $conn->query("UPDATE companies SET valid_until = NOW() + INTERVAL 365 DAY WHERE status = 'active'");
    $log[] = "Added valid_until column to companies and backfilled (trial: +14d, active: +365d)";
} else {
    $log[] = "companies already has valid_until, skipped";
}

// 3) Create the CoatCraft tenant (company_id = 1) if it doesn't exist yet
$result = $conn->query("SELECT id FROM companies WHERE slug = 'coatcraft'");
$company = $result->fetch_assoc();
if (!$company) {
    $conn->query("INSERT INTO companies (id, name, slug, status) VALUES (1, 'CoatCraft Solutions', 'coatcraft', 'active')");
    $companyId = 1;
    $log[] = "Created companies row id=1 for CoatCraft";
} else {
    $companyId = (int)$company['id'];
    $log[] = "CoatCraft company already exists with id=$companyId";
}

// 4) Create tenant_settings row for CoatCraft if missing
$result = $conn->query("SELECT company_id FROM tenant_settings WHERE company_id = $companyId");
if (!$result->fetch_assoc()) {
    $formToken = bin2hex(random_bytes(16));
    $fromName = SMTP_FROM_NAME;
    $fromEmail = SMTP_FROM_EMAIL;
    $stmt = $conn->prepare(
        "INSERT INTO tenant_settings (company_id, company_display_name, logo_path, theme_color, smtp_from_name, smtp_from_email, notify_email, form_token)
         VALUES (?, 'CoatCraft Solutions', '/new_logo.png', '#0f4a78', ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $companyId, $fromName, $fromEmail, $fromEmail, $formToken);
    $stmt->execute();
    $stmt->close();
    $log[] = "Created tenant_settings for company_id=$companyId with form_token=$formToken";
} else {
    $log[] = "tenant_settings for company_id=$companyId already exists";
}

// 5) Backfill all existing rows (company_id = 0) to the CoatCraft tenant
foreach ($tablesToAlter as $table) {
    $conn->query("UPDATE `$table` SET company_id = $companyId WHERE company_id = 0");
    $affected = $conn->affected_rows;
    $log[] = "Backfilled $affected rows in $table to company_id=$companyId";
}

// 6) Create the first user row from the existing hardcoded admin credentials, if no users exist yet for this company
$result = $conn->query("SELECT id FROM users WHERE company_id = $companyId LIMIT 1");
if (!$result->fetch_assoc()) {
    $defaultEmail = 'admin@coatcraft.local';
    $passwordHash = ADMIN_PASSWORD_HASH;
    $stmt = $conn->prepare(
        "INSERT INTO users (company_id, email, password_hash, role, status) VALUES (?, ?, ?, 'owner', 'active')"
    );
    $stmt->bind_param('iss', $companyId, $defaultEmail, $passwordHash);
    $stmt->execute();
    $stmt->close();
    $log[] = "Created initial owner user: $defaultEmail (same password as old ADMIN_PASSWORD_HASH)";
} else {
    $log[] = "Users already exist for company_id=$companyId, skipped";
}

header('Content-Type: text/plain');
echo implode("\n", $log) . "\n";
