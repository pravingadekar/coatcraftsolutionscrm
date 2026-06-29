<?php
// Shared bootstrap for authenticated, tenant-scoped pages
// (crm-dashboard.php, view-leads.php, export.php).
// Sets up the DB connection, enforces login, and loads the current
// tenant's id + branding settings so pages don't repeat this wiring.

require_once __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/db.php';

$companyId = current_company_id();

$stmt = $conn->prepare("SELECT * FROM tenant_settings WHERE company_id = ?");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$appBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$tenantName = $tenant['company_display_name'] ?? 'CoatCraft CRM';
$tenantLogo = $appBasePath . ($tenant['logo_path'] ?? '/new_logo.png');
$tenantColor = $tenant['theme_color'] ?? '#0f4a78';

$stmt = $conn->prepare("SELECT status, valid_until FROM companies WHERE id = ?");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$companyStatus = $company['status'] ?? 'trial';
$companyValidUntil = $company['valid_until'] ?? null;
$isExpired = $companyValidUntil !== null && strtotime($companyValidUntil) < time();
