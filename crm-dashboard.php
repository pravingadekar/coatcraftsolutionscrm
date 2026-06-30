<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/mailer.php';

$view = $_GET['view'] ?? 'overview';
$search = trim($_GET['search'] ?? '');

$areaRanges = [
    '100-500' => [100, 500],
    '500-1000' => [500, 1000],
    '1000-1500' => [1000, 1500],
    '1500-2000' => [1500, 2000],
    '2000-2500' => [2000, 2500],
    '2500-3000' => [2500, 3000],
    '3000+' => [3000, null],
];
$areaRange = $_GET['area_range'] ?? '';
$areaMin = $areaMax = null;
if (isset($areaRanges[$areaRange])) {
    [$areaMin, $areaMax] = $areaRanges[$areaRange];
}

function fetchLeads($conn, $baseQuery, $companyId, $statusFilter, $typeFilter, $search, $areaMin, $areaMax) {
    $conditions = ["e.company_id = ?"];
    $params = [$companyId];
    $types = 'i';
    if ($statusFilter !== null) {
        $conditions[] = "e.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($typeFilter !== null) {
        $conditions[] = "e.type = ?";
        $params[] = $typeFilter;
        $types .= 's';
    }
    if ($search !== '') {
        $conditions[] = "(e.name LIKE CONCAT('%', ?, '%') OR e.phone LIKE CONCAT('%', ?, '%') OR e.location LIKE CONCAT('%', ?, '%'))";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= 'sss';
    }
    if ($areaMin !== null) {
        $conditions[] = "CAST(e.area AS UNSIGNED) >= ?";
        $params[] = $areaMin;
        $types .= 'i';
    }
    if ($areaMax !== null) {
        $conditions[] = "CAST(e.area AS UNSIGNED) <= ?";
        $params[] = $areaMax;
        $types .= 'i';
    }

    $sql = $baseQuery;
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY e.id DESC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function phoneActions($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    $safePhone = htmlspecialchars($phone);
    $waDigits = htmlspecialchars($digits);
    return '<div style="display:flex;flex-direction:column;gap:6px;">'
        . '<span>' . $safePhone . '</span>'
        . '<div style="display:flex;gap:6px;">'
        . '<a href="tel:+' . $waDigits . '" style="padding:4px 9px;border-radius:8px;background:#0f4a78;color:#fff;font-size:11px;text-decoration:none;">📞 Call</a>'
        . '<a href="https://wa.me/' . $waDigits . '" target="_blank" rel="noopener" style="padding:4px 9px;border-radius:8px;background:#25D366;color:#fff;font-size:11px;text-decoration:none;">💬 WhatsApp</a>'
        . '</div></div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isExpired) {
    $billingBlocked = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_daily_note'])) {
        addDailyNote($conn, $companyId, trim($_POST['title'] ?? ''), trim($_POST['note'] ?? ''));
    }

    if (isset($_POST['add_followup'])) {
        addLeadFollowup($conn, $companyId, intval($_POST['enquiry_id'] ?? 0), trim($_POST['followup_note'] ?? ''), trim($_POST['due_date'] ?? ''));
    }

    if (isset($_POST['update_status'])) {
        updateLeadStatus($conn, $companyId, intval($_POST['id']), $_POST['status']);
    }

    if (isset($_POST['add_update'])) {
        addLeadUpdate($conn, $companyId, intval($_POST['id'] ?? 0), trim($_POST['note'] ?? ''));
    }

    if (isset($_POST['schedule_site_visit'])) {
        $visitLeadId = intval($_POST['id'] ?? 0);
        $visitDate = trim($_POST['visit_date'] ?? '');
        $visitSlot = trim($_POST['visit_slot'] ?? '');
        if (scheduleSiteVisit($conn, $companyId, $visitLeadId, $visitDate, $visitSlot)) {
            $visitLead = getLeadById($conn, $companyId, $visitLeadId);
            if ($visitLead && $visitLead['email'] !== '') {
                $slots = getSiteVisitSlots();
                $slotEnd = $visitSlot;
                foreach ($slots as $slot) {
                    if ($slot['start'] === $visitSlot) {
                        $slotEnd = $slot['end'];
                        break;
                    }
                }
                sendSiteVisitInvite(
                    $visitLead['email'],
                    $visitLead['name'],
                    $tenantName,
                    __DIR__ . ($tenant['logo_path'] ?? '/new_logo.png'),
                    $visitDate,
                    $visitSlot,
                    $slotEnd,
                    $visitLead['location'] ?? ''
                );
            }
        }
    }

    if (isset($_POST['add_team_member']) && in_array(current_user()['role'], ['owner', 'admin'])) {
        $memberEmail = trim($_POST['member_email'] ?? '');
        $memberRole = in_array($_POST['member_role'] ?? '', ['admin', 'staff']) ? $_POST['member_role'] : 'staff';
        if ($memberEmail !== '' && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
            $tempPassword = bin2hex(random_bytes(6));
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (company_id, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE id = id");
            $stmt->bind_param('isss', $companyId, $memberEmail, $passwordHash, $memberRole);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $newMemberCreated = ['email' => $memberEmail, 'password' => $tempPassword];
            } else {
                $teamError = 'A user with that email already exists for this company.';
            }
            $stmt->close();
        } else {
            $teamError = 'Please enter a valid email address.';
        }
    }

    if (isset($_POST['remove_team_member']) && in_array(current_user()['role'], ['owner', 'admin'])) {
        $memberId = intval($_POST['user_id'] ?? 0);
        if ($memberId !== current_user()['id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
            $stmt->bind_param('ii', $memberId, $companyId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (isset($_POST['update_tenant_settings']) && in_array(current_user()['role'], ['owner', 'admin'])) {
        $displayName = trim($_POST['company_display_name'] ?? '');
        $themeColor = trim($_POST['theme_color'] ?? '');
        $smtpFromName = trim($_POST['smtp_from_name'] ?? '');
        $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $notifyEmail = trim($_POST['notify_email'] ?? '');

        if ($displayName === '') {
            $settingsError = 'Company display name is required.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
            $settingsError = 'Theme color must be a valid hex color.';
        } elseif (!filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) {
            $settingsError = 'SMTP from email must be a valid email address.';
        } elseif (!filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            $settingsError = 'Notification email must be a valid email address.';
        } else {
            $newLogoPath = $tenant['logo_path'] ?? null;
            if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $allowedExt = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true];
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (!isset($allowedExt[$ext])) {
                    $settingsError = 'Logo must be a JPG, PNG, or WEBP image.';
                } elseif ($_FILES['logo']['size'] > 1024 * 1024) {
                    $settingsError = 'Logo must be smaller than 1MB.';
                } elseif (getimagesize($_FILES['logo']['tmp_name']) === false) {
                    $settingsError = 'Uploaded file is not a valid image.';
                } else {
                    $uploadDir = __DIR__ . '/uploads/logos/';
                    $destFilename = 'company_' . $companyId . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $destFilename)) {
                        $newLogoPath = '/uploads/logos/' . $destFilename;
                    } else {
                        $settingsError = 'Failed to save the uploaded logo.';
                    }
                }
            }

            if (empty($settingsError)) {
                $stmt = $conn->prepare("UPDATE tenant_settings SET company_display_name = ?, logo_path = ?, theme_color = ?, smtp_from_name = ?, smtp_from_email = ?, notify_email = ? WHERE company_id = ?");
                $stmt->bind_param('ssssssi', $displayName, $newLogoPath, $themeColor, $smtpFromName, $smtpFromEmail, $notifyEmail, $companyId);
                $stmt->execute();
                $stmt->close();

                $tenant['company_display_name'] = $displayName;
                $tenant['logo_path'] = $newLogoPath;
                $tenant['theme_color'] = $themeColor;
                $tenant['smtp_from_name'] = $smtpFromName;
                $tenant['smtp_from_email'] = $smtpFromEmail;
                $tenant['notify_email'] = $notifyEmail;
                $tenantName = $displayName;
                $tenantLogo = $newLogoPath;
                $tenantColor = $themeColor;
                $settingsSaved = true;
            }
        }
    }

    if (isset($_POST['regenerate_form_token']) && in_array(current_user()['role'], ['owner', 'admin'])) {
        $newToken = bin2hex(random_bytes(16));
        $stmt = $conn->prepare("UPDATE tenant_settings SET form_token = ? WHERE company_id = ?");
        $stmt->bind_param('si', $newToken, $companyId);
        $stmt->execute();
        $stmt->close();
        $tenant['form_token'] = $newToken;
        $settingsSaved = true;
    }
}
$counts = getLeadStatusCounts($conn, $companyId);
$total = $counts['total'];
$newCount = $counts['new'];
$contactedCount = $counts['contacted'];
$closedCount = $counts['closed'];
$notInterestedCount = $counts['not_interested'];
$workDoneCount = $counts['work_done'];
$industrialCount = $counts['industrial'];
$residentialCount = $counts['residential'];
$weeklyCount = $counts['weekly'];
$openFollowupCount = $counts['open_followups'];
$overdueCount = $counts['overdue_followups'];
$dueSoonCount = $counts['due_soon_followups'];

$baseQuery = "SELECT e.*, (SELECT note FROM enquiry_updates u WHERE u.company_id=e.company_id AND u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update, (SELECT created_at FROM enquiry_updates u WHERE u.company_id=e.company_id AND u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update_at FROM enquiries e";
$allLeads = fetchLeads($conn, $baseQuery, $companyId, null, null, $search, $areaMin, $areaMax);
$newLeads = fetchLeads($conn, $baseQuery, $companyId, 'New', null, $search, $areaMin, $areaMax);
$contactedLeads = fetchLeads($conn, $baseQuery, $companyId, 'Contacted', null, $search, $areaMin, $areaMax);
$closedLeads = fetchLeads($conn, $baseQuery, $companyId, 'Closed', null, $search, $areaMin, $areaMax);
$industrialLeads = fetchLeads($conn, $baseQuery, $companyId, null, 'commercial', $search, $areaMin, $areaMax);
$residentialLeads = fetchLeads($conn, $baseQuery, $companyId, null, 'residential', $search, $areaMin, $areaMax);
$stmt = $conn->prepare("SELECT u.note, u.created_at, e.name, e.status FROM enquiry_updates u JOIN enquiries e ON u.enquiry_id=e.id AND u.company_id=e.company_id WHERE u.company_id=? ORDER BY u.created_at DESC LIMIT 8");
$stmt->bind_param('i', $companyId); $stmt->execute();
$recentUpdates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT f.*, e.name, e.phone, e.location, e.status FROM enquiry_followups f JOIN enquiries e ON e.id=f.enquiry_id AND e.company_id=f.company_id WHERE f.company_id=? ORDER BY f.status ASC, f.due_date IS NULL, f.due_date ASC, f.created_at DESC");
$stmt->bind_param('i', $companyId); $stmt->execute();
$followupTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM daily_notes WHERE company_id=? ORDER BY created_at DESC LIMIT 12");
$stmt->bind_param('i', $companyId); $stmt->execute();
$dailyNotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$statusGroups = ['New'=>[], 'Contacted'=>[], 'Closed'=>[]];
foreach ($allLeads as $lead) {
    if (!isset($statusGroups[$lead['status']])) {
        $statusGroups[$lead['status']] = [];
    }
    $statusGroups[$lead['status']][] = $lead;
}
$stmt = $conn->prepare("SELECT f.*, e.name, e.phone, e.location FROM enquiry_followups f JOIN enquiries e ON e.id=f.enquiry_id AND e.company_id=f.company_id WHERE f.company_id=? AND f.status='Open' AND f.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY f.due_date ASC");
$stmt->bind_param('i', $companyId); $stmt->execute();
$reminderTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Lead details for view=lead
$leadDetails = null;
$leadUpdates = [];
if ($view === 'lead' && isset($_GET['id'])) {
    $leadId = intval($_GET['id']);
    $leadDetails = getLeadById($conn, $companyId, $leadId);
    if ($leadDetails) {
        $leadUpdates = getLeadUpdates($conn, $companyId, $leadId);
    }
}

// Team members for view=team
$teamMembers = [];
if ($view === 'team') {
    $stmt = $conn->prepare("SELECT id, email, role, status, created_at FROM users WHERE company_id = ? ORDER BY created_at ASC");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $teamMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function badgeClass($status) {
    switch($status) {
        case 'Contacted': return 'status-contacted';
        case 'Closed': return 'status-closed';
        case 'Not Interested': return 'status-not-interested';
        case 'Work Done': return 'status-work-done';
        default: return 'status-new';
    }
}

function renderTable($rows) {
    if (empty($rows)) {
        return '<tr><td colspan="8" style="text-align:center;">No records found.</td></tr>';
    }

    $html = '';
    foreach ($rows as $row) {
        $lastUpdate = $row['last_update'] ? htmlspecialchars($row['last_update']) : 'No update';
        $lastAt = $row['last_update_at'] ? date('d M Y H:i', strtotime($row['last_update_at'])) : '-';
        $html .= '<tr>';
        $html .= '<td>' . intval($row['id']) . '</td>';
        $html .= '<td><a href="?view=lead&id=' . intval($row['id']) . '" style="color:#0f4a78;text-decoration:none;font-weight:500;">' . htmlspecialchars($row['name']) . '</a></td>';
        $html .= '<td>' . phoneActions($row['phone']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['location']) . '</td>';
        $html .= '<td>' . date('d M Y', strtotime($row['created_at'])) . '</td>';
        $html .= '<td>';
        $html .= '<form method="POST" style="display:inline;">';
        $html .= '<input type="hidden" name="id" value="' . intval($row['id']) . '">';
        $html .= '<select name="status" onchange="this.form.submit()" style="padding:4px 8px;border:1px solid #d9e2ea;border-radius:6px;font-size:12px;">';
        $statuses = ['New', 'Contacted', 'Closed', 'Not Interested', 'Work Done'];
        foreach ($statuses as $s) {
            $selected = $row['status'] == $s ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($s) . '" ' . $selected . '>' . htmlspecialchars($s) . '</option>';
        }
        $html .= '</select>';
        $html .= '<input type="hidden" name="update_status" value="1">';
        $html .= '</form>';
        $html .= '</td>';
        $html .= '<td>' . $lastAt . '</td>';
        $html .= '<td>' . $lastUpdate . '</td>';
        $html .= '</tr>';
    }
    return $html;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CRM Dashboard | <?= htmlspecialchars($tenantName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="<?= htmlspecialchars($tenantLogo) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($tenantLogo) ?>">
<meta name="theme-color" content="<?= htmlspecialchars($tenantColor) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f4f7fb;--panel:#ffffff;--primary:<?= htmlspecialchars($tenantColor) ?>;--primary-dark:#0a3153;--accent:#f59e0b;--muted:#475569;--border:#d9e2ea;--brand:<?= htmlspecialchars($tenantColor) ?>;--brand-light:#eef3f9;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Poppins',sans-serif;background:var(--bg);color:#1f2937;}
.crm-shell{display:flex;min-height:100vh;}
.sidebar{width:280px;background:linear-gradient(180deg, #10365a 0%, #071c34 100%);color:#fff;padding:24px 18px;position:sticky;top:0;align-self:flex-start;height:100vh;display:flex;flex-direction:column;overflow-y:auto;scrollbar-width:thin;}
.brand{margin-bottom:24px;background:#f5f5f5;padding:24px;border-radius:26px;border:1px solid rgba(255,255,255,.16);box-shadow:0 24px 60px rgba(0,0,0,.16);}
.brand-logo{display:block;width:170px;margin-bottom:18px;}
.brand h1{margin:0;font-size:24px;line-height:1.2;color:#0d1d2d;letter-spacing:.01em;}
.brand p{color:#d1e7dc;line-height:1.6;margin-top:10px;}

.sidebar nav{flex:1;}
.sidebar nav a{display:flex;align-items:center;gap:12px;padding:12px 14px;margin-bottom:6px;border-radius:14px;color:#cbd5e1;text-decoration:none;background:transparent;border:1px solid transparent;font-size:14px;font-weight:500;transition:transform .15s ease,background .15s,color .15s,box-shadow .15s;}
.sidebar nav a i{width:18px;text-align:center;font-size:14px;color:#7fa8cc;flex-shrink:0;transition:color .15s;}
.sidebar nav a:hover{background:rgba(255,255,255,.08);color:#fff;}
.sidebar nav a:hover i{color:#fff;}
.sidebar nav a.active{background:linear-gradient(135deg, #1e73be, #134d80);color:#fff;box-shadow:0 12px 24px rgba(0,0,0,.25);border-color:rgba(255,255,255,.12);}
.sidebar nav a.active i{color:#fff;}
.sidebar .nav-group{margin-bottom:18px;}
.sidebar .section-title{margin:0 0 10px;padding:0 4px;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#5d83a8;font-weight:600;}
.sidebar .link-button{display:flex;align-items:center;gap:10px;padding:12px 14px;margin-top:8px;border-radius:14px;background:rgba(255,255,255,.06);color:#e7f1ff;text-decoration:none;font-size:14px;font-weight:500;border:1px solid rgba(255,255,255,.1);transition:transform .15s ease,box-shadow .15s ease,background .15s;}
.sidebar .link-button:hover{background:rgba(255,255,255,.16);transform:translateY(-1px);}
.sidebar .sidebar-footer{margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1);}
.sidebar .logout-link{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.18);transition:background .15s,color .15s;}
.sidebar .logout-link:hover{background:rgba(248,113,113,.18);color:#fff;}
.crm-main{flex:1;padding:24px;}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.topbar h2{margin:0;font-size:34px;letter-spacing:-.03em;color:#091623;text-shadow:0 4px 18px rgba(15,23,42,.12);}
.topbar p{margin:8px 0 0;color:var(--muted);font-size:15px;max-width:560px;}
.action-btn{background:var(--primary);color:#fff;padding:12px 18px;border:none;border-radius:14px;text-decoration:none;cursor:pointer;box-shadow:0 12px 28px rgba(15,23,42,.16);transition:transform .2s ease,box-shadow .2s ease;font-weight:600;font-size:14px;font-family:inherit;display:inline-flex;align-items:center;justify-content:center;gap:8px;}
.action-btn:hover{transform:translateY(-1px);box-shadow:0 16px 30px rgba(15,23,42,.22);}
.action-btn.btn-success{background:#27ae60;}
.action-btn.btn-danger{background:#e74c3c;}
.panel{background:var(--panel);border:1px solid rgba(217,226,234,.75);border-radius:28px;padding:28px;box-shadow:0 24px 70px rgba(15,23,42,.08);margin-bottom:24px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:18px;}
.stat-card{background:#fff;border:1px solid rgba(217,226,234,.85);border-radius:22px;padding:26px;box-shadow:0 18px 40px rgba(15,23,42,.06);}
.stat-card h3{margin:0;font-size:34px;color:#0f2750;}
.stat-card p{margin:8px 0 0;color:var(--muted);}
.section-title-2{margin:0 0 16px;font-size:18px;color:#0f172a;}
.lead-detail-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;}
.lead-detail-meta .detail-field{min-width:180px;}
.lead-detail-section{margin-bottom:24px;}
.lead-detail-section:last-of-type{margin-bottom:0;}
.lead-detail-section-title{margin:0 0 12px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);}
.lead-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
.detail-field{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px 16px;min-width:0;}
.detail-label{display:block;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px;}
.detail-value{display:block;font-size:15px;color:#0f172a;font-weight:600;word-break:break-word;}
.form-textarea{width:100%;padding:14px 16px;border:1px solid #d9e2ea;border-radius:14px;font-size:14px;font-family:inherit;resize:vertical;margin-bottom:14px;transition:border-color .15s ease,box-shadow .15s ease;}
.form-textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(15,74,120,.12);}
.form-actions{display:flex;justify-content:flex-end;}
.visit-scheduled-card{display:flex;align-items:center;gap:14px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:16px;padding:14px 18px;margin-bottom:20px;color:#166534;}
.visit-scheduled-card i{font-size:20px;flex-shrink:0;}
.visit-form{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group label{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;}
.form-input{padding:12px 14px;border:1px solid #d9e2ea;border-radius:12px;font-size:14px;font-family:inherit;min-width:200px;transition:border-color .15s ease,box-shadow .15s ease;}
.form-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(15,74,120,.12);}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;}
.card{background:#fff;border:1px solid rgba(217,226,234,.75);border-radius:20px;padding:22px;box-shadow:0 18px 40px rgba(15,23,42,.05);}
.card h3{margin:0 0 14px;font-size:19px;color:#102a4d;}
.card p{margin:6px 0;color:var(--muted);line-height:1.75;}
.table-wrapper{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{padding:14px 12px;text-align:left;border-bottom:1px solid #e5e7eb;}
th{background:#f8fafc;color:#0f172a;font-weight:600;}
.badge{display:inline-flex;align-items:center;padding:.35em .75em;border-radius:999px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
.status-new{background:#eef2ff;color:#1e40af;}
.status-contacted{background:#e0f2fe;color:#075985;}
.status-closed{background:#fff7e6;color:#92400e;}
.status-not-interested{background:#fce7e7;color:#9b2c2c;}
.status-work-done{background:#ecfdf5;color:#166534;}
.note-list{list-style:none;padding:0;margin:0;}
.note-list li{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px;margin-bottom:12px;}
.note-list li small{display:block;margin-top:10px;color:var(--muted);}
.kanban-board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;}
.kanban-column{background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:18px;}
.kanban-column h4{margin:0 0 12px;font-size:16px;color:#0f172a;}
.kanban-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:0 8px 20px rgba(15,23,42,.04);}
.kanban-card p{margin:8px 0 0;color:#475569;font-size:13px;line-height:1.5;}
.kanban-empty{padding:14px;border-radius:14px;background:#fff;text-align:center;color:#64748b;}
.calculator-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:24px;}
.calculator-card{background:#f8fbf6;border:1px solid #d9e8e0;border-radius:24px;padding:24px;}
.calculator-card h3{margin:0 0 18px;font-size:22px;color:var(--brand);}
.calculator-card label{display:block;margin-bottom:12px;font-weight:600;color:#0f4f3d;}
.calculator-card input,
.calculator-card select{width:100%;padding:14px;border:1px solid #cfe2d6;border-radius:14px;font-size:16px;margin-top:6px;}
.calculator-card button{width:100%;padding:14px;border:none;border-radius:14px;background:var(--primary);color:#fff;font-size:16px;font-weight:700;cursor:pointer;margin-top:12px;}
.calculator-summary{background:#fff;border:1px solid #d9e8e0;border-radius:24px;padding:24px;}
.calculator-summary table{width:100%;border-collapse:collapse;font-size:14px;}
.calculator-summary th,
.calculator-summary td{padding:12px 10px;text-align:left;border-bottom:1px solid #e5ece8;}
.calculator-summary th{background:#f0f7f1;color:#0f5f46;font-weight:700;}
.calculator-total{margin-top:24px;background:linear-gradient(90deg, rgba(12,111,88,0.95), rgba(6,68,43,0.95));color:#fff;padding:24px;border-radius:24px;text-align:center;}
.calculator-total h2{margin:0;font-size:34px;}
.calculator-total p{margin:8px 0 0;font-size:16px;color:rgba(255,255,255,.85);}
.calculator-note{margin-top:18px;color:#0f5f46;font-weight:600;text-align:center;}
.install-btn{display:none;position:fixed;bottom:20px;right:20px;z-index:1000;background:var(--primary);color:#fff;border:none;border-radius:16px;padding:12px 18px;box-shadow:0 18px 40px rgba(15,23,42,.22);cursor:pointer;transition:transform .2s ease;}
.install-btn:hover{transform:translateY(-1px);}
.notify-banner{display:none;margin:20px 0;padding:18px 20px;border-radius:18px;border:1px solid rgba(15,74,120,.18);background:rgba(15,74,120,.08);color:#0f4a78;display:flex;justify-content:space-between;align-items:center;gap:12px;}
.notify-banner.show{display:flex;}
.notify-btn{background:#0f4a78;color:#fff;border:none;padding:10px 16px;border-radius:14px;cursor:pointer;box-shadow:0 12px 24px rgba(15,74,120,.14);}
.notify-btn:hover{opacity:.95;}
.mobile-topbar{display:none;}
.sidebar-overlay{display:none;}

@media(max-width:1024px){
    .crm-shell{flex-direction:column;}
    .mobile-topbar{display:flex;align-items:center;gap:14px;background:linear-gradient(180deg, #10365a 0%, #071c34 100%);color:#fff;padding:14px 18px;position:sticky;top:0;z-index:50;}
    .mobile-topbar button{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);color:#fff;width:42px;height:42px;border-radius:12px;font-size:18px;cursor:pointer;flex-shrink:0;}
    .mobile-topbar img{width:129px;height:40px;background-color:#fff;border-radius:8px;}
    .mobile-topbar span{font-weight:600;font-size:16px;}
    .sidebar{position:fixed;top:0;left:0;width:280px;max-width:82vw;height:100vh;z-index:100;transform:translateX(-100%);transition:transform .25s ease;box-shadow:0 0 40px rgba(0,0,0,.3);}
    .sidebar.open{transform:translateX(0);}
    .sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:90;opacity:0;visibility:hidden;transition:opacity .25s ease;}
    .sidebar-overlay.show{display:block;opacity:1;visibility:visible;}
    .crm-main{padding:18px;}
    .stats-grid{grid-template-columns:1fr 1fr;}
    .kanban-board{grid-template-columns:1fr;}
    .calculator-grid{grid-template-columns:1fr;}
}
@media(max-width:720px){.stats-grid{grid-template-columns:1fr;}.topbar{flex-direction:column;align-items:flex-start;}.topbar form{width:100%;}.topbar form input{width:100%;}.topbar .action-btn{width:100%;}.cards-grid{grid-template-columns:1fr;}.lead-actions{flex-direction:column;align-items:stretch;gap:12px;}.sidebar nav a{padding:12px 14px;font-size:14px;}.sidebar{padding:20px;}.visit-form{flex-direction:column;align-items:stretch;}.form-group,.form-input{width:100%;}.form-actions .action-btn{width:100%;}}
</style>
</head>
<body>
<div class="mobile-topbar">
    <button id="sidebarToggle" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
    <img src="<?= htmlspecialchars($tenantLogo) ?>" alt="<?= htmlspecialchars($tenantName) ?>">
    <span><?= htmlspecialchars($tenantName) ?></span>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="crm-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="<?= htmlspecialchars($tenantLogo) ?>" class="brand-logo" alt="<?= htmlspecialchars($tenantName) ?>">
            <h1><?= htmlspecialchars($tenantName) ?> CRM</h1>
        </div>
        <nav>
            <div class="nav-group">
                <a href="?view=overview" class="<?= $view==='overview' ? 'active' : '' ?>"><i class="fa-solid fa-gauge-high"></i>Overview</a>
            </div>

            <div class="nav-group">
                <div class="section-title">Leads</div>
                <a href="?view=all" class="<?= $view==='all' ? 'active' : '' ?>"><i class="fa-solid fa-list"></i>All Leads</a>
                <a href="?view=new" class="<?= $view==='new' ? 'active' : '' ?>"><i class="fa-solid fa-star"></i>New Leads</a>
                <a href="?view=contacted" class="<?= $view==='contacted' ? 'active' : '' ?>"><i class="fa-solid fa-phone"></i>Contacted</a>
                <a href="?view=closed" class="<?= $view==='closed' ? 'active' : '' ?>"><i class="fa-solid fa-circle-check"></i>Closed</a>
                <a href="?view=industrial" class="<?= $view==='industrial' ? 'active' : '' ?>"><i class="fa-solid fa-industry"></i>Industrial</a>
                <a href="?view=residential" class="<?= $view==='residential' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i>Residential</a>
            </div>

            <div class="nav-group">
                <div class="section-title">Workflow</div>
                <a href="?view=followups" class="<?= $view==='followups' ? 'active' : '' ?>"><i class="fa-solid fa-bell"></i>Follow-ups</a>
                <a href="?view=notes" class="<?= $view==='notes' ? 'active' : '' ?>"><i class="fa-solid fa-note-sticky"></i>Daily Notes</a>
                <a href="?view=pipeline" class="<?= $view==='pipeline' ? 'active' : '' ?>"><i class="fa-solid fa-diagram-project"></i>Pipeline</a>
            </div>

            <div class="nav-group">
                <div class="section-title">Tools</div>
                <a href="?view=calculator" class="<?= $view==='calculator' ? 'active' : '' ?>"><i class="fa-solid fa-calculator"></i>Calculator</a>
            </div>

            <?php if (in_array(current_user()['role'], ['owner', 'admin'])): ?>
            <div class="nav-group">
                <div class="section-title">Account</div>
                <a href="?view=team" class="<?= $view==='team' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i>Team</a>
                <a href="?view=leadform" class="<?= $view==='leadform' ? 'active' : '' ?>"><i class="fa-solid fa-link"></i>Lead Form</a>
                <a href="?view=settings" class="<?= $view==='settings' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i>Settings</a>
            </div>
            <?php endif; ?>

            <div class="nav-group">
                <div class="section-title">Plan</div>
                <a href="?view=billing" class="<?= $view==='billing' ? 'active' : '' ?>"><i class="fa-solid fa-credit-card"></i>Billing<?= $isExpired ? ' <span style="color:#dc2626;">&bull;</span>' : '' ?></a>
            </div>
        </nav>

        <div class="nav-group">
            <div class="section-title">Quick Actions</div>
            <a class="link-button" href="view-leads.php"><i class="fa-solid fa-table-list"></i>Open Lead Board</a>
            <a class="link-button" href="export.php"><i class="fa-solid fa-file-export"></i>Export Leads</a>
        </div>

        <div class="sidebar-footer">
            <a class="logout-link" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </div>
    </aside>
    <main class="crm-main">
        <button id="installPwaBtn" class="install-btn" type="button">Install App</button>
        <div id="notifyBanner" class="notify-banner">
            <span id="notifyBannerText">Enable notifications to receive new enquiry and follow-up alerts.</span>
            <button id="enableNotificationsBtn" class="notify-btn" type="button">Enable Notifications</button>
        </div>
        <?php if ($isExpired): ?>
        <div class="notify-banner" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
            <span>Your <?= $companyStatus === 'trial' ? 'trial' : 'plan' ?> expired on <?= date('d M Y', strtotime($companyValidUntil)) ?>. You can still view and export your leads, but adding or editing is paused until you renew.</span>
            <a class="notify-btn" style="text-decoration:none;" href="?view=billing">Renew Now</a>
        </div>
        <?php endif; ?>
        <div class="topbar">
            <div>
                <h2><?= htmlspecialchars($tenantName) ?> CRM Command Center</h2>
                <p>Manage leads, notes, follow-ups, and sales analytics from one premium dashboard built for speed and clarity.</p>
            </div>
            <a class="action-btn" href="view-leads.php">Legacy List</a>
        </div>

        <div class="panel">
            <div class="stats-grid">
                <div class="stat-card"><h3><?= $total ?></h3><p>Total leads</p></div>
                <div class="stat-card"><h3><?= $newCount ?></h3><p>New leads</p></div>
                <div class="stat-card"><h3><?= $contactedCount ?></h3><p>Contacted</p></div>
                <div class="stat-card"><h3><?= $closedCount ?></h3><p>Closed</p></div>
                <div class="stat-card"><h3><?= $notInterestedCount ?></h3><p>Not Interested</p></div>
                <div class="stat-card"><h3><?= $workDoneCount ?></h3><p>Work Done</p></div>
                <div class="stat-card"><h3><?= $industrialCount ?></h3><p>Industrial</p></div>
                <div class="stat-card"><h3><?= $residentialCount ?></h3><p>Residential</p></div>
            </div>
        </div>

        <?php if ($view === 'lead' && $leadDetails): ?>
            <div class="panel">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                    <h3 class="section-title-2">Lead Details: <?= htmlspecialchars($leadDetails['name']) ?></h3>
                    <a href="?view=all" class="action-btn" style="padding:8px 16px;font-size:14px;">Back to Leads</a>
                </div>
                <div class="lead-detail-meta">
                    <div class="detail-field">
                        <span class="detail-label">Status</span>
                        <span class="detail-value"><span class="badge <?= badgeClass($leadDetails['status']) ?>"><?= htmlspecialchars($leadDetails['status']) ?></span></span>
                    </div>
                    <?php if (!empty($leadDetails['created_at'])): ?>
                    <div class="detail-field">
                        <span class="detail-label">Submitted</span>
                        <span class="detail-value"><?= date('d M Y, H:i', strtotime($leadDetails['created_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                    $detailSections = [
                        'Contact Information' => [
                            'name' => 'Name', 'phone' => 'Phone', 'email' => 'Email',
                            'location' => 'Location', 'address' => 'Address', 'type' => 'Enquiry Type',
                        ],
                        'Project Requirements' => [
                            'area' => 'Area (sqft)', 'slab' => 'Slab', 'industry_usage' => 'Usage',
                            'work_type' => 'Work Type', 'heavyload' => 'Heavy Load', 'timeline' => 'Timeline',
                            'epoxy_type' => 'Epoxy Type', 'thickness' => 'Thickness (mm)', 'budget' => 'Budget',
                            'message' => 'Message',
                        ],
                        'Surface Condition' => [
                            'concrete_grade' => 'Concrete Grade', 'slab_age' => 'Slab Age', 'cracks' => 'Cracks',
                            'contamination' => 'Contamination', 'previous_coating' => 'Previous Coating',
                        ],
                        'Industrial Details' => [
                            'industry_type' => 'Industry Type', 'forklift' => 'Forklift', 'max_load' => 'Max Load',
                            'chemical_exposure' => 'Chemical Exposure', 'moisture_issue' => 'Moisture Issue',
                            'water_washing' => 'Water Washing',
                        ],
                        'Finish & Scheduling' => [
                            'anti_skid' => 'Anti Skid', 'preferred_color' => 'Preferred Color',
                            'finish_type' => 'Finish Type', 'line_marking' => 'Line Marking',
                            'start_date' => 'Start Date', 'urgent' => 'Urgent', 'working_hours' => 'Working Hours',
                        ],
                    ];
                ?>
                <?php foreach ($detailSections as $sectionTitle => $fields): ?>
                    <?php
                        $filled = [];
                        foreach ($fields as $key => $label) {
                            if (!empty($leadDetails[$key])) {
                                $filled[$key] = $label;
                            }
                        }
                    ?>
                    <?php if (!empty($filled)): ?>
                        <div class="lead-detail-section">
                            <h4 class="lead-detail-section-title"><?= htmlspecialchars($sectionTitle) ?></h4>
                            <div class="lead-detail-grid">
                                <?php foreach ($filled as $key => $label): ?>
                                    <div class="detail-field">
                                        <span class="detail-label"><?= htmlspecialchars($label) ?></span>
                                        <span class="detail-value"><?php
                                            if ($key === 'start_date') {
                                                echo date('d M Y', strtotime($leadDetails[$key]));
                                            } else {
                                                echo nl2br(htmlspecialchars($leadDetails[$key]));
                                            }
                                        ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="panel" style="margin-bottom:0;">
                    <h4 style="margin:0 0 16px;">Communication History</h4>
                    <?php if (!empty($leadUpdates)): ?>
                        <div class="note-list">
                            <?php foreach ($leadUpdates as $update): ?>
                                <li>
                                    <strong><?= date('d M Y H:i', strtotime($update['created_at'])) ?></strong><br>
                                    <?= nl2br(htmlspecialchars($update['note'])) ?>
                                </li>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No communications yet.</p>
                    <?php endif; ?>
                </div>
                <div class="panel" style="margin-top:24px;">
                    <h4 style="margin:0 0 16px;">Add New Comment</h4>
                    <form method="POST">
                        <input type="hidden" name="id" value="<?= $leadDetails['id'] ?>">
                        <textarea name="note" placeholder="Enter your communication details..." rows="4" class="form-textarea" required></textarea>
                        <div class="form-actions">
                            <button type="submit" name="add_update" class="action-btn"><i class="fa-solid fa-paper-plane"></i>Add Comment</button>
                        </div>
                    </form>
                </div>
                <div class="panel" style="margin-top:24px;">
                    <h4 style="margin:0 0 16px;">Schedule Site Visit</h4>
                    <?php $siteVisit = getLatestSiteVisit($conn, $companyId, $leadDetails['id']); ?>
                    <?php if ($siteVisit): ?>
                        <?php
                            $slots = getSiteVisitSlots();
                            $visitSlotEnd = $siteVisit['visit_time'];
                            foreach ($slots as $slot) {
                                if ($slot['start'] === date('H:i', strtotime($siteVisit['visit_time']))) {
                                    $visitSlotEnd = $slot['end'];
                                    break;
                                }
                            }
                        ?>
                        <div class="visit-scheduled-card">
                            <i class="fa-solid fa-calendar-check"></i>
                            <div>
                                <span class="detail-label">Latest scheduled visit</span>
                                <span class="detail-value">
                                    <?= date('D, d M Y', strtotime($siteVisit['visit_date'])) ?>,
                                    <?= date('g:i A', strtotime($siteVisit['visit_time'])) ?> - <?= date('g:i A', strtotime($visitSlotEnd)) ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="visit-form">
                        <input type="hidden" name="id" value="<?= $leadDetails['id'] ?>">
                        <div class="form-group">
                            <label for="visit_date">Date</label>
                            <input type="date" id="visit_date" name="visit_date" required class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="visit_slot">Time Slot</label>
                            <select id="visit_slot" name="visit_slot" required class="form-input">
                                <?php foreach (getSiteVisitSlots() as $slot): ?>
                                    <option value="<?= $slot['start'] ?>"><?= htmlspecialchars($slot['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="schedule_site_visit" class="action-btn btn-success" value="1"><i class="fa-solid fa-check"></i>Confirm Visit</button>
                    </form>
                </div>
            </div>
        <?php elseif ($view === 'overview'): ?>
            <div class="panel">
                <h3 class="section-title-2">Latest Activity</h3>
                <div class="cards-grid">
                    <div class="card"><h3>Recent Updates</h3><ul class="note-list">
                        <?php if (!empty($recentUpdates)): ?>
                            <?php foreach($recentUpdates as $update): ?>
                                <li>
                                    <strong><?= htmlspecialchars($update['name']) ?> (<?= htmlspecialchars($update['status']) ?>)</strong>
                                    <p><?= htmlspecialchars($update['note']) ?></p>
                                    <small><?= date('d M Y, H:i', strtotime($update['created_at'])) ?></small>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>No updates recorded yet.</li>
                        <?php endif; ?>
                    </ul></div>
                    <div class="card"><h3>Top Leads</h3>
                        <p>Review your newest leads and follow up quickly from the lead board.</p>
                        <p><strong>Recommended:</strong> use the “All Leads” module for full filtering and updates.</p>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'followups'): ?>
            <div class="panel">
                <h3 class="section-title-2">Follow-up Tasks</h3>
                <div class="cards-grid">
                    <div class="card"><h3>Open Tasks</h3><p><?= $openFollowupCount ?> open follow-ups</p></div>
                    <div class="card"><h3>Overdue</h3><p><?= $overdueCount ?> overdue tasks</p></div>
                    <div class="card"><h3>Weekly Leads</h3><p><?= $weeklyCount ?> new leads this week</p></div>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Add Follow-up</h3>
                <form method="POST" style="display:grid;gap:14px;">
                    <label><strong>Lead</strong><br>
                        <select name="enquiry_id" required style="width:100%;padding:12px;border-radius:14px;border:1px solid #d1d5db;">
                            <option value="">Select a lead</option>
                            <?php foreach ($allLeads as $lead): ?>
                                <option value="<?= intval($lead['id']) ?>"><?= htmlspecialchars($lead['name']) ?> — <?= htmlspecialchars($lead['location']) ?> (<?= htmlspecialchars($lead['status']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><strong>Follow-up Note</strong><br>
                        <textarea name="followup_note" rows="3" required style="width:100%;padding:12px;border-radius:14px;border:1px solid #d1d5db;"></textarea>
                    </label>
                    <label><strong>Due Date</strong><br>
                        <input type="date" name="due_date" style="width:100%;padding:12px;border-radius:14px;border:1px solid #d1d5db;">
                    </label>
                    <button type="submit" name="add_followup" value="1" class="action-btn">Create Follow-up</button>
                </form>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Follow-up Task List</h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Lead</th>
                                <th>Note</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($followupTasks)): ?>
                                <?php foreach ($followupTasks as $task): ?>
                                    <tr>
                                        <td><?= intval($task['id']) ?></td>
                                        <td><?= htmlspecialchars($task['name']) ?> (<?= htmlspecialchars($task['status']) ?>)</td>
                                        <td><?= htmlspecialchars($task['note']) ?></td>
                                        <td><?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '-' ?></td>
                                        <td><span class="badge <?= $task['status'] === 'Done' ? 'status-closed' : 'status-contacted' ?>"><?= htmlspecialchars($task['status']) ?></span></td>
                                        <td>
                                            <?php if ($task['status'] === 'Open'): ?>
                                                <form method="POST" style="margin:0;display:inline;">
                                                    <input type="hidden" name="task_id" value="<?= intval($task['id']) ?>">
                                                    <button type="submit" name="complete_followup" value="1" class="action-btn btn-success" style="padding:8px 12px;">Complete</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#16a34a;font-weight:700;">Done</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align:center;">No follow-up tasks yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($view === 'notes'): ?>
            <div class="panel">
                <h3 class="section-title-2">Daily Notes</h3>
                <div class="cards-grid">
                    <div class="card"><h3>Note Count</h3><p><?= count($dailyNotes) ?> recent notes</p></div>
                    <div class="card"><h3>Use for</h3><p>Meeting notes, daily action items, and quick client updates.</p></div>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Add Daily Note</h3>
                <form method="POST" style="display:grid;gap:14px;">
                    <label><strong>Title</strong><br>
                        <input type="text" name="title" required style="width:100%;padding:12px;border-radius:14px;border:1px solid #d1d5db;">
                    </label>
                    <label><strong>Note</strong><br>
                        <textarea name="note" rows="4" required style="width:100%;padding:12px;border-radius:14px;border:1px solid #d1d5db;"></textarea>
                    </label>
                    <button type="submit" name="add_daily_note" value="1" class="action-btn">Save Note</button>
                </form>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Recent Notes</h3>
                <div class="note-list">
                    <?php if (!empty($dailyNotes)): ?>
                        <?php foreach ($dailyNotes as $note): ?>
                            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;margin-bottom:12px;">
                                <strong><?= htmlspecialchars($note['title']) ?></strong>
                                <p><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                                <small style="color:var(--muted);"><?= date('d M Y, H:i', strtotime($note['created_at'])) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;">No daily notes yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($view === 'pipeline'): ?>
            <div class="panel">
                <h3 class="section-title-2">Sales Pipeline</h3>
                <div class="cards-grid">
                    <div class="stat-card"><h3><?= $total ?></h3><p>Total Leads</p></div>
                    <div class="stat-card"><h3><?= $newCount ?></h3><p>New Leads</p></div>
                    <div class="stat-card"><h3><?= $contactedCount ?></h3><p>Contacted</p></div>
                    <div class="stat-card"><h3><?= $closedCount ?></h3><p>Closed</p></div>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Pipeline Summary</h3>
                <div class="cards-grid">
                    <div class="card"><h3>New this week</h3><p><?= $weeklyCount ?> leads added</p></div>
                    <div class="card"><h3>Open follow-ups</h3><p><?= $openFollowupCount ?> tasks open</p></div>
                    <div class="card"><h3>Overdue</h3><p><?= $overdueCount ?> overdue</p></div>
                    <div class="card"><h3>Due soon</h3><p><?= $dueSoonCount ?> tasks in next 3 days</p></div>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Lead Detail Cards</h3>
                <div class="cards-grid">
                    <?php if (!empty($allLeads)): ?>
                        <?php foreach (array_slice($allLeads, 0, 6) as $lead): ?>
                            <div class="card">
                                <h3><?= htmlspecialchars($lead['name']) ?></h3>
                                <p><strong>Location:</strong> <?= htmlspecialchars($lead['location']) ?></p>
                                <p><strong>Phone:</strong> <?= htmlspecialchars($lead['phone']) ?></p>
                                <p><strong>Status:</strong> <span class="badge <?= badgeClass($lead['status']) ?>"><?= htmlspecialchars($lead['status']) ?></span></p>
                                <p><strong>Last update:</strong> <?= $lead['last_update_at'] ? date('d M Y H:i', strtotime($lead['last_update_at'])) : 'None' ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card"><p>No leads available for detail cards.</p></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Kanban Stage View</h3>
                <div class="kanban-board">
                    <?php foreach (['New', 'Contacted', 'Closed'] as $stage): ?>
                        <div class="kanban-column">
                            <h4><?= $stage ?></h4>
                            <?php if (!empty($statusGroups[$stage])): ?>
                                <?php foreach ($statusGroups[$stage] as $lead): ?>
                                    <div class="kanban-card">
                                        <strong><?= htmlspecialchars($lead['name']) ?></strong>
                                        <p><?= htmlspecialchars($lead['location']) ?></p>
                                        <p><strong>Phone:</strong> <?= htmlspecialchars($lead['phone']) ?></p>
                                        <p><strong>Last update:</strong> <?= $lead['last_update_at'] ? date('d M Y', strtotime($lead['last_update_at'])) : 'None' ?></p>
                                        <p><strong>Note:</strong> <?= htmlspecialchars($lead['last_update'] ?: 'No update yet') ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="kanban-empty">No leads in this stage.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel">
                <h3 class="section-title-2">Task Reminders</h3>
                <?php if (!empty($reminderTasks)): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Lead</th>
                                    <th>Note</th>
                                    <th>Due</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reminderTasks as $task): ?>
                                    <tr>
                                        <td><?= intval($task['id']) ?></td>
                                        <td><?= htmlspecialchars($task['name']) ?></td>
                                        <td><?= htmlspecialchars($task['note']) ?></td>
                                        <td><?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($task['phone']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="card"><p>No task reminders due in the next 3 days.</p></div>
                <?php endif; ?>
            </div>
        <?php elseif ($view === 'calculator'): ?>
            <div class="panel">
                <h3 class="section-title-2">CoatCraft Calculator</h3>
                <div class="calculator-grid">
                    <div class="calculator-card">
                        <h3>Epoxy Material Calculator</h3>
                        <label>Enter Area (Sqft)
                            <input type="number" id="calc-area" placeholder="Example 20000">
                        </label>
                        <label>Select Thickness
                            <select id="calc-mm">
                                <option value="2">2 MM</option>
                                <option value="3">3 MM</option>
                            </select>
                        </label>
                        <label>Labour Charge Per Sqft
                            <input type="number" id="calc-labourRate" value="8">
                        </label>
                        <label>Profit Per Sqft
                            <input type="number" id="calc-profitRate" value="5">
                        </label>
                        <label>Transportation Charge
                            <input type="number" id="calc-transportCharge" value="10000">
                        </label>
                        <button type="button" onclick="calculateCRM()">Calculate</button>
                    </div>

                    <div class="calculator-summary">
                        <table>
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th>Coverage / Kit</th>
                                    <th>Required Kits</th>
                                    <th>Rate / Kit</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Primer</td>
                                    <td>662 sqft</td>
                                    <td id="calc-primerKit">0</td>
                                    <td>₹1930</td>
                                    <td id="calc-primerTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td id="calc-screedName">Screed</td>
                                    <td id="calc-screedCoverage">0 sqft</td>
                                    <td id="calc-screedKit">0</td>
                                    <td>₹2180</td>
                                    <td id="calc-screedTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td>1MM Topcoat</td>
                                    <td>116 sqft</td>
                                    <td id="calc-topcoatKit">0</td>
                                    <td>₹2678</td>
                                    <td id="calc-topcoatTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td>Pigment</td>
                                    <td>116 sqft</td>
                                    <td id="calc-pigmentKit">0</td>
                                    <td>₹230</td>
                                    <td id="calc-pigmentTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td>Labour Charge</td>
                                    <td id="calc-labourRateText">₹8/sqft</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td id="calc-labourTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td>Profit</td>
                                    <td id="calc-profitRateText">₹5/sqft</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td id="calc-profitTotal">₹0</td>
                                </tr>
                                <tr>
                                    <td>Transportation</td>
                                    <td>Fixed</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td id="calc-transportTotal">₹0</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="calculator-total">
                            <p>Grand Total</p>
                            <h2 id="calc-grandTotal">₹0</h2>
                            <p>Cost Per Sqft: <span id="calc-perSqft">₹0</span></p>
                        </div>
                        <div class="calculator-note">GST Extra</div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'team' && in_array(current_user()['role'], ['owner', 'admin'])): ?>
            <div class="panel">
                <h3 class="section-title-2">Team</h3>
                <?php if (!empty($teamError)): ?>
                    <p style="color:#b91c1c;"><?= htmlspecialchars($teamError) ?></p>
                <?php endif; ?>
                <?php if (!empty($newMemberCreated)): ?>
                    <div class="result" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:16px;margin-bottom:18px;">
                        <p><strong>User created.</strong> Share these temporary login details — ask them to change the password after first login.</p>
                        <p>Email: <strong><?= htmlspecialchars($newMemberCreated['email']) ?></strong></p>
                        <p>Temporary password: <strong><?= htmlspecialchars($newMemberCreated['password']) ?></strong></p>
                    </div>
                <?php endif; ?>

                <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:24px;">
                    <label style="flex:1;min-width:220px;">Email
                        <input type="email" name="member_email" required style="width:100%;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <label>Role
                        <select name="member_role" style="padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </label>
                    <button type="submit" name="add_team_member" class="action-btn">Add Team Member</button>
                </form>

                <table>
                    <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($teamMembers as $member): ?>
                            <tr>
                                <td><?= htmlspecialchars($member['email']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($member['role'])) ?></td>
                                <td><?= htmlspecialchars(ucfirst($member['status'])) ?></td>
                                <td><?= date('d M Y', strtotime($member['created_at'])) ?></td>
                                <td>
                                    <?php if ((int)$member['id'] !== current_user()['id']): ?>
                                    <form method="POST" onsubmit="return confirm('Remove this team member?');">
                                        <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                                        <button type="submit" name="remove_team_member" class="action-btn btn-danger" style="padding:6px 10px;">Remove</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($view === 'leadform' && in_array(current_user()['role'], ['owner', 'admin'])): ?>
            <?php
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $leadFormUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $basePath . '/lead-form.php?t=' . urlencode($tenant['form_token'] ?? '');
            ?>
            <div class="panel">
                <h3 class="section-title-2">Your Public Lead Form</h3>
                <p>Share this link anywhere (your website, social media, ads) to start collecting leads. Anyone who submits it lands directly in your CRM.</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px;">
                    <input type="text" id="leadFormUrl" value="<?= htmlspecialchars($leadFormUrl) ?>" readonly style="flex:1;min-width:260px;padding:12px;border:1px solid #d9e2ea;border-radius:8px;font-size:14px;">
                    <button type="button" class="action-btn" onclick="navigator.clipboard.writeText(document.getElementById('leadFormUrl').value); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy Link', 1500);">Copy Link</button>
                    <a class="action-btn" href="<?= htmlspecialchars($leadFormUrl) ?>" target="_blank">Preview</a>
                </div>
            </div>
        <?php elseif ($view === 'settings' && in_array(current_user()['role'], ['owner', 'admin'])): ?>
            <div class="panel">
                <h3 class="section-title-2">Tenant Settings</h3>
                <?php if (!empty($settingsError)): ?>
                    <p style="color:#b91c1c;"><?= htmlspecialchars($settingsError) ?></p>
                <?php endif; ?>
                <?php if (!empty($settingsSaved)): ?>
                    <p style="color:#15803d;">Settings saved.</p>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px;max-width:480px;margin-bottom:24px;">
                    <label>Company display name
                        <input type="text" name="company_display_name" value="<?= htmlspecialchars($tenant['company_display_name'] ?? '') ?>" required style="width:100%;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <label>Logo
                        <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                            <img src="<?= htmlspecialchars($tenantLogo) ?>" alt="Current logo" style="height:40px;max-width:140px;object-fit:contain;">
                            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </label>
                    <label>Theme color
                        <input type="color" name="theme_color" value="<?= htmlspecialchars($tenant['theme_color'] ?? '#0f4a78') ?>" style="width:80px;height:40px;padding:2px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <label>Notification email
                        <input type="email" name="notify_email" value="<?= htmlspecialchars($tenant['notify_email'] ?? '') ?>" required style="width:100%;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <label>SMTP from name
                        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($tenant['smtp_from_name'] ?? '') ?>" required style="width:100%;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <label>SMTP from email
                        <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($tenant['smtp_from_email'] ?? '') ?>" required style="width:100%;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                    </label>
                    <button type="submit" name="update_tenant_settings" class="action-btn" style="align-self:flex-start;">Save Settings</button>
                </form>

                <div style="max-width:480px;">
                    <label>Lead form token
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px;">
                            <input type="text" id="formTokenValue" value="<?= htmlspecialchars($tenant['form_token'] ?? '') ?>" readonly style="flex:1;min-width:200px;padding:10px;border:1px solid #d9e2ea;border-radius:8px;">
                            <button type="button" class="action-btn" onclick="navigator.clipboard.writeText(document.getElementById('formTokenValue').value); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500);">Copy</button>
                        </div>
                    </label>
                    <form method="POST" onsubmit="return confirm('Regenerating the token invalidates your current lead form link. Continue?');" style="margin-top:10px;">
                        <button type="submit" name="regenerate_form_token" class="action-btn btn-danger" style="padding:8px 14px;">Regenerate Token</button>
                    </form>
                </div>
            </div>
        <?php elseif ($view === 'billing'): ?>
            <div class="panel" style="max-width:480px;">
                <h3 class="section-title-2">Billing</h3>
                <?php if ($isExpired): ?>
                    <p style="color:#991b1b;">Your <?= $companyStatus === 'trial' ? 'trial' : 'plan' ?> expired on <?= htmlspecialchars(date('d M Y', strtotime($companyValidUntil))) ?>.</p>
                <?php else: ?>
                    <p style="color:#15803d;">Active until <?= htmlspecialchars(date('d M Y', strtotime($companyValidUntil))) ?>.</p>
                <?php endif; ?>

                <?php if (in_array(current_user()['role'], ['owner', 'admin'])): ?>
                    <p>Pay ₹999 to extend your access by 30 days from your current expiry (or from today if already expired).</p>
                    <button type="button" id="payNowBtn" class="action-btn">Pay ₹999 — Extend 30 Days</button>
                    <p id="payStatus" style="margin-top:10px;"></p>
                    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                    <script>
                    document.getElementById('payNowBtn').addEventListener('click', function () {
                        var btn = this, statusEl = document.getElementById('payStatus');
                        btn.disabled = true;
                        statusEl.textContent = 'Creating order...';
                        fetch('billing-create-order.php', { method: 'POST' })
                            .then(function (r) { return r.json(); })
                            .then(function (order) {
                                if (!order.ok) { throw new Error(order.error || 'Could not create order'); }
                                var rzp = new Razorpay({
                                    key: order.key_id,
                                    order_id: order.order_id,
                                    amount: order.amount,
                                    currency: 'INR',
                                    name: <?= json_encode($tenantName) ?>,
                                    description: 'CRM access — 30 days',
                                    handler: function (response) {
                                        statusEl.textContent = 'Verifying payment...';
                                        fetch('billing-verify.php', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify(response)
                                        })
                                        .then(function (r) { return r.json(); })
                                        .then(function (result) {
                                            if (result.ok) {
                                                statusEl.style.color = '#15803d';
                                                statusEl.textContent = 'Payment verified! Access extended to ' + result.valid_until + '. Reloading...';
                                                setTimeout(function () { window.location.reload(); }, 1200);
                                            } else {
                                                statusEl.style.color = '#991b1b';
                                                statusEl.textContent = 'Payment verification failed: ' + (result.error || 'unknown error');
                                                btn.disabled = false;
                                            }
                                        });
                                    },
                                    modal: {
                                        ondismiss: function () { statusEl.textContent = ''; btn.disabled = false; }
                                    }
                                });
                                rzp.open();
                            })
                            .catch(function (err) {
                                statusEl.style.color = '#991b1b';
                                statusEl.textContent = err.message;
                                btn.disabled = false;
                            });
                    });
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($view, ['overview','all','new','contacted','closed','industrial','residential'])): ?>
            <div class="panel" style="margin-bottom:0;">
                <div class="topbar" style="padding:0;">
                    <div><h3 class="section-title-2"><?= $view === 'overview' ? 'All Leads' : ucfirst($view) . ' Leads' ?></h3></div>
                    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, phone, location" style="padding:12px;border-radius:14px;border:1px solid #d1d5db;width:240px;">
                        <select name="area_range" style="padding:12px;border-radius:14px;border:1px solid #d1d5db;">
                            <option value="">All Areas</option>
                            <?php foreach ($areaRanges as $key => $range): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $areaRange === $key ? 'selected' : '' ?>><?= $key === '3000+' ? '3000+ sqft' : $range[0] . ' - ' . $range[1] . ' sqft' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" style="padding:12px 18px;border-radius:14px;border:none;background:var(--primary);color:#fff;cursor:pointer;">Search</button>
                    </form>
                </div>
                <div class="table-wrapper" style="margin-top:18px;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Location</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Last Update</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            switch ($view) {
                                case 'new': echo renderTable($newLeads); break;
                                case 'contacted': echo renderTable($contactedLeads); break;
                                case 'closed': echo renderTable($closedLeads); break;
                                case 'industrial': echo renderTable($industrialLeads); break;
                                case 'residential': echo renderTable($residentialLeads); break;
                                default: echo renderTable($allLeads); break;
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
(function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggle');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('show');
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
})();

function calculateCRM() {
    var area = Number(document.getElementById('calc-area').value) || 0;
    var mm = Number(document.getElementById('calc-mm').value);
    var labourRate = Number(document.getElementById('calc-labourRate').value) || 0;
    var profitRate = Number(document.getElementById('calc-profitRate').value) || 0;
    var transportCharge = Number(document.getElementById('calc-transportCharge').value) || 0;

    var primerCoverage = 662;
    var screed1mmCoverage = 124;
    var screed2mmCoverage = 75;
    var topcoatCoverage = 116;
    var primerRate = 1930;
    var screedRate = 2180;
    var topcoatRate = 2678;
    var pigmentRate = 230;

    var primerKit = Math.ceil(area / primerCoverage);
    var primerTotal = primerKit * primerRate;

    var screedKit = 0;
    var topcoatKit = 0;

    if (mm === 2) {
        screedKit = Math.ceil(area / screed1mmCoverage);
        topcoatKit = Math.ceil(area / topcoatCoverage);
        document.getElementById('calc-screedName').innerText = '1MM Screed';
        document.getElementById('calc-screedCoverage').innerText = screed1mmCoverage + ' sqft';
    } else {
        screedKit = Math.ceil(area / screed2mmCoverage);
        topcoatKit = Math.ceil(area / topcoatCoverage);
        document.getElementById('calc-screedName').innerText = '2MM Screed';
        document.getElementById('calc-screedCoverage').innerText = screed2mmCoverage + ' sqft';
    }

    var pigmentKit = Math.ceil(area / topcoatCoverage);
    var pigmentTotal = pigmentKit * pigmentRate;
    var screedTotal = screedKit * screedRate;
    var topcoatTotal = topcoatKit * topcoatRate;
    var labourTotal = area * labourRate;
    var profitTotal = area * profitRate;
    var grandTotal = primerTotal + screedTotal + topcoatTotal + pigmentTotal + labourTotal + profitTotal + transportCharge;
    var perSqft = area > 0 ? grandTotal / area : 0;

    document.getElementById('calc-primerKit').innerText = primerKit;
    document.getElementById('calc-screedKit').innerText = screedKit;
    document.getElementById('calc-topcoatKit').innerText = topcoatKit;
    document.getElementById('calc-pigmentKit').innerText = pigmentKit;
    document.getElementById('calc-primerTotal').innerText = '₹' + primerTotal.toLocaleString();
    document.getElementById('calc-screedTotal').innerText = '₹' + screedTotal.toLocaleString();
    document.getElementById('calc-topcoatTotal').innerText = '₹' + topcoatTotal.toLocaleString();
    document.getElementById('calc-pigmentTotal').innerText = '₹' + pigmentTotal.toLocaleString();
    document.getElementById('calc-labourTotal').innerText = '₹' + labourTotal.toLocaleString();
    document.getElementById('calc-profitTotal').innerText = '₹' + profitTotal.toLocaleString();
    document.getElementById('calc-transportTotal').innerText = '₹' + transportCharge.toLocaleString();
    document.getElementById('calc-grandTotal').innerText = '₹' + grandTotal.toLocaleString();
    document.getElementById('calc-perSqft').innerText = '₹' + perSqft.toFixed(2);
    document.getElementById('calc-labourRateText').innerText = '₹' + labourRate + '/sqft';
    document.getElementById('calc-profitRateText').innerText = '₹' + profitRate + '/sqft';
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').catch(function(err) {
            console.warn('Service worker failed:', err);
        });
    });
}

let deferredPrompt;
const installBtn = document.getElementById('installPwaBtn');
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (installBtn) {
        installBtn.style.display = 'inline-flex';
    }
});
if (installBtn) {
    installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const choiceResult = await deferredPrompt.userChoice;
        deferredPrompt = null;
        installBtn.style.display = 'none';
        console.log('PWA install choice:', choiceResult.outcome);
    });
}

const notifyBanner = document.getElementById('notifyBanner');
const notifyBannerText = document.getElementById('notifyBannerText');
const enableNotificationsBtn = document.getElementById('enableNotificationsBtn');

if (enableNotificationsBtn) {
    enableNotificationsBtn.addEventListener('click', async () => {
        await requestNotificationPermission();
    });
}

function updateNotificationBanner() {
    if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }
    if (Notification.permission === 'default') {
        notifyBanner?.classList.add('show');
        notifyBannerText.textContent = 'Enable notifications to receive new enquiry and follow-up alerts.';
        enableNotificationsBtn.style.display = 'inline-flex';
    } else if (Notification.permission === 'denied') {
        notifyBanner?.classList.add('show');
        notifyBannerText.textContent = 'Notifications are blocked. Enable them in browser settings to receive alerts.';
        enableNotificationsBtn.style.display = 'none';
    } else {
        notifyBanner?.classList.remove('show');
    }
}

async function requestNotificationPermission() {
    if (Notification.permission !== 'default') {
        updateNotificationBanner();
        return;
    }
    const permission = await Notification.requestPermission();
    if (permission === 'granted') {
        await registerPushNotifications();
    }
    updateNotificationBanner();
}

// Push Notifications
async function registerPushNotifications() {
    if ('serviceWorker' in navigator && 'PushManager' in window && Notification.permission === 'granted') {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array('BMnfna-11JQtWTB7IO_h0g1O_ok3ehuNWgpyNurqzODxWIKAkmwULf_YNdbt0y56_MEH1oOMniR-dra8cFRks1w')
            });
            await fetch('/subscribe.php', {
                method: 'POST',
                body: JSON.stringify(subscription),
                headers: { 'Content-Type': 'application/json' }
            });
            console.log('Push notification subscribed');
        } catch (error) {
            console.error('Push notification registration failed:', error);
        }
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

window.addEventListener('load', () => {
    updateNotificationBanner();
    if (Notification.permission === 'granted') {
        registerPushNotifications();
    }
});

// Register push notifications on load
registerPushNotifications();
</script>
</body>
</html>
