<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/mailer.php';

// Win-back WhatsApp offers to old Closed / Not Interested leads. Nothing is
// automatic: an owner/admin picks the leads and presses Send. STOP replies
// are honoured (whatsapp-webhook.php) and each lead gets at most one offer
// per 90 days (getReengageCandidates()).

if (!in_array(current_user()['role'], ['owner', 'admin'], true)) {
    http_response_code(403);
    exit('Only the owner or an admin can send offers.');
}

const REENGAGE_BATCH_LIMIT = 50;

if (empty($_SESSION['reengage_csrf'])) {
    $_SESSION['reengage_csrf'] = bin2hex(random_bytes(16));
}

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['reengage_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Form expired, please reload the page.');
    }

    // Only ids that are still eligible right now can be sent to.
    $eligible = [];
    foreach (getReengageCandidates($conn, $companyId) as $row) {
        $eligible[(int) $row['id']] = $row;
    }
    $picked = array_slice(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), 0, REENGAGE_BATCH_LIMIT);

    $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($picked as $id) {
        if (!isset($eligible[$id])) {
            $results['skipped']++;
            continue;
        }
        $lead = $eligible[$id];
        if (sendReengageWhatsApp($lead['phone'], (string) $lead['name'], $tenantName)) {
            $stmt = $conn->prepare("UPDATE enquiries SET last_reengaged_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->bind_param('ii', $id, $companyId);
            $stmt->execute();
            $stmt->close();
            addLeadUpdate($conn, $companyId, $id, 'Win-back WhatsApp offer sent');
            $results['sent']++;
        } else {
            $results['failed']++;
        }
    }
}

$candidates = getReengageCandidates($conn, $companyId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Win-back Offers | <?= htmlspecialchars($tenantName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= htmlspecialchars($tenantLogo) ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:<?= htmlspecialchars($tenantColor) ?>;--bg:#f4f7fb;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Poppins',sans-serif;background:var(--bg);color:#1f2937;}
.topbar{display:flex;align-items:center;gap:14px;background:linear-gradient(180deg, #10365a 0%, #071c34 100%);color:#fff;padding:14px 22px;flex-wrap:wrap;}
.topbar img{height:36px;background:#fff;border-radius:8px;padding:2px;}
.topbar span{font-weight:600;font-size:16px;flex:1;}
.topbar a{color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.08);}
.wrap{max-width:1100px;margin:0 auto;padding:20px 16px 40px;}
h1{font-size:20px;margin:0 0 6px;}
.sub{color:#64748b;font-size:13px;margin:0 0 16px;}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px;}
.alert.ok{background:#ecfdf5;color:#166534;}
.alert.warn{background:#fff7ed;color:#9a3412;}
.panel{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(15,23,42,.06);overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #eef2f6;white-space:nowrap;}
th{font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;}
.actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;}
button{font-family:inherit;font-size:14px;padding:10px 18px;border:0;border-radius:10px;background:#16a34a;color:#fff;cursor:pointer;}
button:disabled{background:#94a3b8;cursor:not-allowed;}
</style>
</head>
<body>
<div class="topbar">
    <img src="<?= htmlspecialchars($tenantLogo) ?>" alt="">
    <span><?= htmlspecialchars($tenantName) ?></span>
    <a href="crm-dashboard.php"><i class="fa-solid fa-arrow-left"></i>Dashboard</a>
</div>
<div class="wrap">
    <h1><i class="fa-solid fa-bullhorn"></i> Win-back Offers</h1>
    <p class="sub">Closed / Not Interested leads older than 60 days, not offered in the last 90 days, and not opted out (STOP). Max <?= REENGAGE_BATCH_LIMIT ?> per send.</p>

    <?php if ($results !== null): ?>
        <div class="alert ok">Sent: <?= $results['sent'] ?> · Failed: <?= $results['failed'] ?> · Skipped (no longer eligible): <?= $results['skipped'] ?></div>
    <?php endif; ?>
    <?php if (!reengageEnabled()): ?>
        <div class="alert warn">Sending is off. Get a WhatsApp <strong>marketing</strong> template approved (params: customer name, company name; include "Reply STOP to opt out") and set <code>WA_TEMPLATE_REENGAGE</code> in config.php.</div>
    <?php endif; ?>

    <form method="POST" class="panel" onsubmit="return confirm('Send the WhatsApp offer to the selected leads?')">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['reengage_csrf']) ?>">
        <table>
            <thead><tr><th><input type="checkbox" id="all" aria-label="Select all"></th><th>Name</th><th>Phone</th><th>Location</th><th>Status</th><th>Enquired</th><th>Last offer</th></tr></thead>
            <tbody>
            <?php if (!$candidates): ?>
                <tr><td colspan="7">No eligible leads right now.</td></tr>
            <?php endif; ?>
            <?php foreach ($candidates as $c): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" class="pick"></td>
                    <td><a href="crm-dashboard.php?view=lead&id=<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name'] ?: '—') ?></a></td>
                    <td><?= htmlspecialchars($c['phone']) ?></td>
                    <td><?= htmlspecialchars((string) $c['location']) ?></td>
                    <td><?= htmlspecialchars($c['status']) ?></td>
                    <td><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                    <td><?= $c['last_reengaged_at'] ? date('d M Y', strtotime($c['last_reengaged_at'])) : 'Never' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="actions">
            <span id="count">0 selected</span>
            <button type="submit" id="send" disabled><i class="fa-brands fa-whatsapp"></i> Send offer</button>
        </div>
    </form>
</div>
<script>
(function () {
    var picks = document.querySelectorAll('.pick');
    var all = document.getElementById('all');
    var send = document.getElementById('send');
    var count = document.getElementById('count');
    var limit = <?= REENGAGE_BATCH_LIMIT ?>;
    var enabled = <?= reengageEnabled() ? 'true' : 'false' ?>;
    function update() {
        var n = document.querySelectorAll('.pick:checked').length;
        count.textContent = n + ' selected' + (n > limit ? ' (only the first ' + limit + ' will be sent)' : '');
        send.disabled = !enabled || n === 0;
    }
    all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
    picks.forEach(function (p) { p.addEventListener('change', update); });
    update();
})();
</script>
</body>
</html>
