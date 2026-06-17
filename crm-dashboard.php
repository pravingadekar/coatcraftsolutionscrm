<?php
require_once 'auth.php';
require_login();
require 'db.php';

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

function fetchLeads($conn, $baseQuery, $statusFilter, $typeFilter, $search, $areaMin, $areaMax) {
    $conditions = [];
    $params = [];
    $types = '';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_daily_note'])) {
        $title = trim($_POST['title'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if ($title !== '' && $note !== '') {
            $stmt = $conn->prepare("INSERT INTO daily_notes (title, note, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('ss', $title, $note);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (isset($_POST['add_followup'])) {
        $enquiryId = intval($_POST['enquiry_id'] ?? 0);
        $note = trim($_POST['followup_note'] ?? '');
        $dueDate = trim($_POST['due_date'] ?? '');
        if ($enquiryId > 0 && $note !== '') {
            $stmt = $conn->prepare("INSERT INTO enquiry_followups (enquiry_id, note, due_date, status, created_at) VALUES (?, ?, ?, 'Open', NOW())");
            $stmt->bind_param('iss', $enquiryId, $note, $dueDate);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (isset($_POST['update_status'])) {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        $stmt = $conn->prepare("UPDATE enquiries SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
    }

    if (isset($_POST['add_update'])) {
        $enquiryId = intval($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($enquiryId > 0 && $note !== '') {
            $stmt = $conn->prepare("INSERT INTO enquiry_updates (enquiry_id, note, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('is', $enquiryId, $note);
            $stmt->execute();
            $stmt->close();
        }
    }}
$total = $conn->query("SELECT COUNT(*) as count FROM enquiries")->fetch_assoc()['count'];
$newCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='New'")->fetch_assoc()['c'];
$contactedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Contacted'")->fetch_assoc()['c'];
$closedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Closed'")->fetch_assoc()['c'];
$notInterestedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Not Interested'")->fetch_assoc()['c'];
$workDoneCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Work Done'")->fetch_assoc()['c'];
$industrialCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE type='commercial'")->fetch_assoc()['c'];
$residentialCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE type='residential'")->fetch_assoc()['c'];
$weeklyCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$openFollowupCount = $conn->query("SELECT COUNT(*) as c FROM enquiry_followups WHERE status='Open'")->fetch_assoc()['c'];
$overdueCount = $conn->query("SELECT COUNT(*) as c FROM enquiry_followups WHERE status='Open' AND due_date < CURDATE() AND due_date IS NOT NULL")->fetch_assoc()['c'];
$dueSoonCount = $conn->query("SELECT COUNT(*) as c FROM enquiry_followups WHERE status='Open' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

$baseQuery = "SELECT e.*, (SELECT note FROM enquiry_updates u WHERE u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update, (SELECT created_at FROM enquiry_updates u WHERE u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update_at FROM enquiries e";
$allLeads = fetchLeads($conn, $baseQuery, null, null, $search, $areaMin, $areaMax);
$newLeads = fetchLeads($conn, $baseQuery, 'New', null, $search, $areaMin, $areaMax);
$contactedLeads = fetchLeads($conn, $baseQuery, 'Contacted', null, $search, $areaMin, $areaMax);
$closedLeads = fetchLeads($conn, $baseQuery, 'Closed', null, $search, $areaMin, $areaMax);
$industrialLeads = fetchLeads($conn, $baseQuery, null, 'commercial', $search, $areaMin, $areaMax);
$residentialLeads = fetchLeads($conn, $baseQuery, null, 'residential', $search, $areaMin, $areaMax);
$recentUpdates = $conn->query("SELECT u.note, u.created_at, e.name, e.status FROM enquiry_updates u JOIN enquiries e ON u.enquiry_id=e.id ORDER BY u.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$followupTasks = $conn->query("SELECT f.*, e.name, e.phone, e.location, e.status FROM enquiry_followups f JOIN enquiries e ON e.id=f.enquiry_id ORDER BY f.status ASC, f.due_date IS NULL, f.due_date ASC, f.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$dailyNotes = $conn->query("SELECT * FROM daily_notes ORDER BY created_at DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$statusGroups = ['New'=>[], 'Contacted'=>[], 'Closed'=>[]];
foreach ($allLeads as $lead) {
    if (!isset($statusGroups[$lead['status']])) {
        $statusGroups[$lead['status']] = [];
    }
    $statusGroups[$lead['status']][] = $lead;
}
$reminderTasks = $conn->query("SELECT f.*, e.name, e.phone, e.location FROM enquiry_followups f JOIN enquiries e ON e.id=f.enquiry_id WHERE f.status='Open' AND f.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY f.due_date ASC")->fetch_all(MYSQLI_ASSOC);

// Lead details for view=lead
$leadDetails = null;
$leadUpdates = [];
if ($view === 'lead' && isset($_GET['id'])) {
    $leadId = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM enquiries WHERE id=?");
    $stmt->bind_param("i", $leadId);
    $stmt->execute();
    $leadDetails = $stmt->get_result()->fetch_assoc();
    if ($leadDetails) {
        $stmt = $conn->prepare("SELECT * FROM enquiry_updates WHERE enquiry_id=? ORDER BY created_at DESC");
        $stmt->bind_param("i", $leadId);
        $stmt->execute();
        $leadUpdates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
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
<title>CRM Dashboard | CoatCraft</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/new_logo.png">
<link rel="apple-touch-icon" href="/new_logo.png">
<meta name="theme-color" content="#0f4a78">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f4f7fb;--panel:#ffffff;--primary:#0f4a78;--primary-dark:#0a3153;--accent:#f59e0b;--muted:#475569;--border:#d9e2ea;--brand:#0f4a78;--brand-light:#eef3f9;}
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
.topbar .action-btn{background:var(--primary);color:#fff;padding:12px 18px;border:none;border-radius:14px;text-decoration:none;cursor:pointer;box-shadow:0 12px 28px rgba(15,23,42,.16);transition:transform .2s ease,box-shadow .2s ease;}
.topbar .action-btn:hover{transform:translateY(-1px);box-shadow:0 16px 30px rgba(15,23,42,.22);}
.panel{background:var(--panel);border:1px solid rgba(217,226,234,.75);border-radius:28px;padding:28px;box-shadow:0 24px 70px rgba(15,23,42,.08);margin-bottom:24px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:18px;}
.stat-card{background:#fff;border:1px solid rgba(217,226,234,.85);border-radius:22px;padding:26px;box-shadow:0 18px 40px rgba(15,23,42,.06);}
.stat-card h3{margin:0;font-size:34px;color:#0f2750;}
.stat-card p{margin:8px 0 0;color:var(--muted);}
.section-title-2{margin:0 0 16px;font-size:18px;color:#0f172a;}
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
@media(max-width:1024px){.crm-shell{flex-direction:column;}.sidebar{width:100%;height:auto;position:relative;}.crm-main{padding:18px;}.stats-grid{grid-template-columns:1fr 1fr;}.kanban-board{grid-template-columns:1fr;}.calculator-grid{grid-template-columns:1fr;}}
@media(max-width:720px){.stats-grid{grid-template-columns:1fr;}.topbar{flex-direction:column;align-items:flex-start;}.topbar form{width:100%;}.topbar form input{width:100%;}.topbar .action-btn{width:100%;}.cards-grid{grid-template-columns:1fr;}.lead-actions{flex-direction:column;align-items:stretch;gap:12px;}.sidebar nav a{padding:12px 14px;font-size:14px;}.sidebar{padding:20px;}}
</style>
</head>
<body>
<div class="crm-shell">
    <aside class="sidebar">
        <div class="brand">
            <img src="new_logo.png" class="brand-logo" alt="CoatCraft Solutions">
            <h1>CoatCraft CRM</h1>
            <p style="color:#d1e7dc;margin-top:10px;line-height:1.5;"></p>
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
        <div class="topbar">
            <div>
                <h2>CoatCraft CRM Command Center</h2>
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
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
                    <div>
                        <p><strong>Name:</strong> <?= htmlspecialchars($leadDetails['name']) ?></p>
                        <p><strong>Phone:</strong> <?= htmlspecialchars($leadDetails['phone']) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($leadDetails['email']) ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($leadDetails['location']) ?></p>
                        <p><strong>Status:</strong> <span class="badge <?= badgeClass($leadDetails['status']) ?>"><?= htmlspecialchars($leadDetails['status']) ?></span></p>
                    </div>
                    <div>
                        <p><strong>Area:</strong> <?= htmlspecialchars($leadDetails['area']) ?> sqft</p>
                        <p><strong>Usage:</strong> <?= htmlspecialchars($leadDetails['industry_usage']) ?></p>
                        <p><strong>Epoxy Type:</strong> <?= htmlspecialchars($leadDetails['epoxy_type']) ?></p>
                        <p><strong>Thickness:</strong> <?= htmlspecialchars($leadDetails['thickness']) ?> mm</p>
                        <p><strong>Timeline:</strong> <?= htmlspecialchars($leadDetails['timeline']) ?></p>
                    </div>
                </div>
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
                        <textarea name="note" placeholder="Enter your communication details..." rows="4" style="width:100%;padding:12px;border:1px solid #d9e2ea;border-radius:8px;font-size:14px;margin-bottom:12px;"></textarea>
                        <button type="submit" name="add_update" class="action-btn" style="padding:10px 20px;">Add Comment</button>
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
                                                    <button type="submit" name="complete_followup" value="1" style="padding:8px 12px;border:none;border-radius:10px;background:#27ae60;color:#fff;cursor:pointer;">Complete</button>
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
