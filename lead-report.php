<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/leads.php';

// Monthly lead report: leads created in the month, by channel, and how far
// each got in the pipeline (current status). Quoted / Won / Paid are kept up
// to date by the CRM (DashboardSyncService), so this reads end to end.

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));

$quotedStatuses = ['Quoted', 'Won', 'Paid', 'Work Done'];
$wonStatuses = ['Won', 'Paid', 'Work Done'];
$sourceLabels = ['website' => 'Website', 'whatsapp' => 'WhatsApp Bot', 'voice' => 'AI Voice Call', 'missed_call' => 'Missed Call', 'meta_ads' => 'Meta Ads', '' => 'Not tracked (older leads)'];

$stmt = $conn->prepare("SELECT COALESCE(source, '') AS source, status, COUNT(*) AS c FROM enquiries WHERE company_id = ? AND created_at >= ? AND created_at < ? GROUP BY COALESCE(source, ''), status");
$stmt->bind_param('iss', $companyId, $start, $end);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$bySource = [];
$totals = ['leads' => 0, 'quoted' => 0, 'won' => 0];
foreach ($rows as $row) {
    $src = $row['source'];
    $bySource[$src] = $bySource[$src] ?? ['leads' => 0, 'quoted' => 0, 'won' => 0];
    $n = (int) $row['c'];
    $bySource[$src]['leads'] += $n;
    $totals['leads'] += $n;
    if (in_array($row['status'], $quotedStatuses, true)) { $bySource[$src]['quoted'] += $n; $totals['quoted'] += $n; }
    if (in_array($row['status'], $wonStatuses, true)) { $bySource[$src]['won'] += $n; $totals['won'] += $n; }
}
uasort($bySource, fn($a, $b) => $b['leads'] <=> $a['leads']);

// Website campaigns (utm_campaign) for the month
$stmt = $conn->prepare("SELECT source_detail, status FROM enquiries WHERE company_id = ? AND created_at >= ? AND created_at < ? AND source IN ('website', 'meta_ads') AND source_detail IS NOT NULL AND source_detail <> ''");
$stmt->bind_param('iss', $companyId, $start, $end);
$stmt->execute();
$campaigns = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $name = preg_match('/(?:utm_campaign|campaign)=([^;]+)/', $row['source_detail'], $m) ? trim($m[1])
        : (preg_match('/landing_page=([^;]+)/', $row['source_detail'], $m) ? 'Page: ' . trim($m[1]) : 'Other');
    $campaigns[$name] = $campaigns[$name] ?? ['leads' => 0, 'won' => 0];
    $campaigns[$name]['leads']++;
    if (in_array($row['status'], $wonStatuses, true)) { $campaigns[$name]['won']++; }
}
$stmt->close();
uasort($campaigns, fn($a, $b) => $b['leads'] <=> $a['leads']);
$campaigns = array_slice($campaigns, 0, 10, true);

// Last 6 months trend
$trendStart = date('Y-m-01', strtotime($start . ' -5 months'));
$won = "'" . implode("','", $wonStatuses) . "'";
$stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS leads, SUM(status IN ($won)) AS won FROM enquiries WHERE company_id = ? AND created_at >= ? AND created_at < ? GROUP BY m ORDER BY m");
$stmt->bind_param('iss', $companyId, $trendStart, $end);
$stmt->execute();
$trend = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$trendMax = max(1, ...array_map(fn($t) => (int) $t['leads'], $trend ?: [['leads' => 1]]));

// Average quotation value this month, from the CRM (CoatCraft's own tenant only).
$avgQuote = null;
if (defined('ERP_SYNC_ENABLED') && ERP_SYNC_ENABLED && $companyId === (int) ERP_SYNC_COMPANY_ID) {
    try {
        $db = ERP_DB_NAME;
        $createdBy = (int) ERP_LEAD_CREATED_BY;
        $stmt = $conn->prepare("SELECT AVG(t.total) AS avg_total, COUNT(*) AS n FROM (
                SELECT p.id, SUM(pp.price * pp.quantity - pp.discount) AS total
                FROM `{$db}`.proposals p JOIN `{$db}`.proposal_products pp ON pp.proposal_id = p.id
                WHERE p.created_by = ? AND p.status >= 1 AND p.send_date >= ? AND p.send_date < ?
                GROUP BY p.id) t");
        $stmt->bind_param('iss', $createdBy, $start, $end);
        $stmt->execute();
        $avgQuote = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('lead-report avg quote failed: ' . $e->getMessage());
    }
}

function pct($part, $whole) { return $whole > 0 ? round($part * 100 / $whole) . '%' : '–'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lead Report | <?= htmlspecialchars($tenantName) ?></title>
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
.head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.head h1{font-size:20px;margin:0;}
.head form{display:flex;gap:8px;}
.head input,.head button{font-family:inherit;font-size:14px;padding:8px 12px;border:1px solid #d9e2ea;border-radius:8px;background:#fff;}
.head button{background:var(--primary);color:#fff;border-color:var(--primary);cursor:pointer;}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px;}
.stat{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
.stat h3{margin:0;font-size:24px;color:var(--primary);}
.stat p{margin:4px 0 0;font-size:13px;color:#64748b;}
.panel{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(15,23,42,.06);margin-bottom:18px;overflow-x:auto;}
.panel h2{font-size:16px;margin:0 0 12px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #eef2f6;white-space:nowrap;}
th{font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;}
td.num,th.num{text-align:right;}
.bar{height:10px;background:#e2e8f0;border-radius:6px;min-width:120px;}
.bar span{display:block;height:100%;background:var(--primary);border-radius:6px;}
.note{font-size:12px;color:#64748b;margin:0;}
</style>
</head>
<body>
<div class="topbar">
    <img src="<?= htmlspecialchars($tenantLogo) ?>" alt="">
    <span><?= htmlspecialchars($tenantName) ?></span>
    <a href="crm-dashboard.php"><i class="fa-solid fa-arrow-left"></i>Dashboard</a>
</div>
<div class="wrap">
    <div class="head">
        <h1><i class="fa-solid fa-chart-column"></i> Lead Report — <?= htmlspecialchars(date('F Y', strtotime($start))) ?></h1>
        <form method="GET">
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
            <button type="submit">Show</button>
        </form>
    </div>

    <div class="stats">
        <div class="stat"><h3><?= $totals['leads'] ?></h3><p>Leads received</p></div>
        <div class="stat"><h3><?= $totals['quoted'] ?></h3><p>Quoted (<?= pct($totals['quoted'], $totals['leads']) ?>)</p></div>
        <div class="stat"><h3><?= $totals['won'] ?></h3><p>Won (<?= pct($totals['won'], $totals['leads']) ?>)</p></div>
        <div class="stat"><h3><?= $avgQuote && $avgQuote['n'] > 0 ? '₹' . number_format((float) $avgQuote['avg_total']) : '–' ?></h3><p>Avg quotation value<?= $avgQuote && $avgQuote['n'] > 0 ? ' (' . (int) $avgQuote['n'] . ' sent)' : '' ?></p></div>
    </div>

    <div class="panel">
        <h2>By channel</h2>
        <table>
            <thead><tr><th>Channel</th><th class="num">Leads</th><th class="num">Quoted</th><th class="num">Won</th><th class="num">Conversion</th></tr></thead>
            <tbody>
            <?php if (!$bySource): ?>
                <tr><td colspan="5">No leads this month.</td></tr>
            <?php endif; ?>
            <?php foreach ($bySource as $src => $s): ?>
                <tr>
                    <td><?= htmlspecialchars($sourceLabels[$src] ?? ucfirst($src)) ?></td>
                    <td class="num"><?= $s['leads'] ?></td>
                    <td class="num"><?= $s['quoted'] ?></td>
                    <td class="num"><?= $s['won'] ?></td>
                    <td class="num"><?= pct($s['won'], $s['leads']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($campaigns): ?>
    <div class="panel">
        <h2>Top campaigns / landing pages</h2>
        <table>
            <thead><tr><th>Campaign</th><th class="num">Leads</th><th class="num">Won</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $name => $c): ?>
                <tr><td><?= htmlspecialchars($name) ?></td><td class="num"><?= $c['leads'] ?></td><td class="num"><?= $c['won'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Last 6 months</h2>
        <table>
            <thead><tr><th>Month</th><th>Leads</th><th class="num">Leads</th><th class="num">Won</th></tr></thead>
            <tbody>
            <?php foreach ($trend as $t): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M Y', strtotime($t['m'] . '-01'))) ?></td>
                    <td><div class="bar"><span style="width:<?= round($t['leads'] * 100 / $trendMax) ?>%"></span></div></td>
                    <td class="num"><?= (int) $t['leads'] ?></td>
                    <td class="num"><?= (int) $t['won'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="note">Counts leads created in the month by their current status. Quoted = Quoted/Won/Paid/Work Done; Won = Won/Paid/Work Done.</p>
</div>
</body>
</html>
