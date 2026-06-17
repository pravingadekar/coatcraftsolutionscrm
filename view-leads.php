<?php
require_once 'auth.php';
require_login();
require 'db.php';

/* Add Client Update Note */
if(isset($_POST['add_update'])){
    $id = intval($_POST['id']);
    $note = trim($_POST['note'] ?? '');
    if($note !== ''){
        $stmt = $conn->prepare("INSERT INTO enquiry_updates (enquiry_id, note, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $id, $note);
        $stmt->execute();
        $stmt->close();
    }
}

/* Update Status */
if(isset($_POST['update_status'])){
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE enquiries SET status=? WHERE id=?");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $stmt->close();
}

/* Delete */
if(isset($_GET['delete'])){
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM enquiries WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

/* Search */
$where = "";
$search = "";
if(isset($_GET['search'])){
    $search = $_GET['search'];
    $where = "WHERE name LIKE CONCAT('%', ?, '%') OR phone LIKE CONCAT('%', ?, '%') OR location LIKE CONCAT('%', ?, '%')";
}

$sql = "SELECT e.*,
    (SELECT note FROM enquiry_updates u WHERE u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update,
    (SELECT created_at FROM enquiry_updates u WHERE u.enquiry_id=e.id ORDER BY created_at DESC LIMIT 1) AS last_update_at
    FROM enquiries e $where ORDER BY e.id DESC";
if ($where !== "") {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
$total = $conn->query("SELECT COUNT(*) as count FROM enquiries")->fetch_assoc()['count'];

$newCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='New'")->fetch_assoc()['c'];
$contactedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Contacted'")->fetch_assoc()['c'];
$closedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Closed'")->fetch_assoc()['c'];
$notInterestedCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Not Interested'")->fetch_assoc()['c'];
$workDoneCount = $conn->query("SELECT COUNT(*) as c FROM enquiries WHERE status='Work Done'")->fetch_assoc()['c'];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>CoatCraft CRM Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/new_logo.png">
<link rel="apple-touch-icon" href="/new_logo.png">
<meta name="theme-color" content="#0f4a78">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">

<style>
body{font-family:'Poppins',sans-serif;margin:0;background:#f4f7fb;}
*{
    box-sizing:border-box;
}

html, body{
    margin:0;
    padding:0;
    overflow-x:hidden;
}
.header{
    background:#1e73be;
    color:#fff;
    padding:20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    width:100%;
}

.card{
    background:#fff;
   
    padding:15px;
    border-radius:10px;
    box-shadow:0 5px 15px rgba(0,0,0,0.08);
  
}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{background:#1e73be;color:#fff;padding:12px;text-align:left;}
td{padding:10px;border-bottom:1px solid #eee;}
tr:hover{background:#f1f6fc;}

select, input[type="text"]{
    padding:6px;
    border-radius:6px;
    border:1px solid #ccc;
}

button{
    padding:6px 10px;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

.status-new{color:#e67e22;font-weight:600;}
.status-contacted{color:#2980b9;font-weight:600;}
.status-closed{color:#27ae60;font-weight:600;}
.status-not-interested{color:#9b2c2c;font-weight:600;}
.status-work-done{color:#205e12;font-weight:600;}

.delete-btn{
    background:#e74c3c;
    color:#fff;
}

.export-btn{
    background:#27ae60;
    color:#fff;
}
.search-box{
    margin:20px;
}
.card-container{
    display:grid;
    grid-template-columns:1fr;
    gap:15px;
    padding:10px;
}

.lead-card{
    background:#fff;
    padding:20px;
    border-radius:12px;
    box-shadow:0 5px 20px rgba(0,0,0,0.08);
}

.lead-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}

.lead-header h3{
    margin:0;
    color:#1e73be;
}

.lead-id{
    font-size:12px;
    color:#888;
}

.lead-info p{
    margin:5px 0;
    font-size:14px;
}

.lead-footer{
    margin-top:15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

details summary{
    cursor:pointer;
    font-weight:600;
    margin-top:8px;
}
.stats{
    display:flex;
    gap:20px;
    margin:20px;
}
.stat-card{
    flex:1;
    background:#fff;
    padding:20px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,0.08);
    text-align:center;
}
.stat-card h3{margin:0;font-size:28px;}
.new{border-left:5px solid #e67e22;}
.contacted{border-left:5px solid #2980b9;}
.closed{border-left:5px solid #27ae60;}
.not-interested{border-left:5px solid #9b2c2c;}
.work-done{border-left:5px solid #205e12;}

.card-container{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
    gap:20px;
    padding:20px;
}

.lead-card{
    background:#fff;
    padding:20px;
    border-radius:12px;
    box-shadow:0 8px 25px rgba(0,0,0,0.08);
}

.lead-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.badge{
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    background:#eee;
}

.lead-actions{
    margin-top:15px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

button{
    padding:6px 10px;
    border:none;
    border-radius:6px;
    cursor:pointer;
}

.wa-btn{background:#25D366;color:#fff;}
.delete-btn{background:#e74c3c;color:#fff;}

.modal{
    display:none;
    position:fixed;
    top:0;left:0;
    width:100%;height:100%;
    background:rgba(0,0,0,0.5);
}

.modal-content{
    background:#fff;
    padding:30px;
    margin:5% auto;
    width:60%;
    border-radius:10px;
}

.close{
    float:right;
    cursor:pointer;
    font-size:22px;
}
.modal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);
    overflow-y:auto;
    z-index:999;
}

.modal-box{
    background:#fff;
    width:95%;
    max-width:1000px;
    margin:20px auto;
    border-radius:15px;
    overflow:hidden;
    animation:fadeIn 0.3s ease-in-out;
}

.modal-header{
    background:#1e73be;
    color:#fff;
    padding:20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-header h2{
    margin:0;
}

.close-btn{
    font-size:26px;
    cursor:pointer;
}

.modal-body{
    padding:20px;
    max-height:75vh;
    overflow-y:auto;
}

.modal-section{
    margin-bottom:25px;
}

.modal-section h3{
    margin-bottom:10px;
    color:#1e73be;
    border-bottom:2px solid #eee;
    padding-bottom:5px;
}
.lead-actions{
    margin-top:15px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.lead-actions button,
.lead-actions select{
    flex:1 1 auto;
    min-width:100px;
}

.grid-2{
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
}

.grid-2 p{
    background:#f8f9fc;
    padding:8px 12px;
    border-radius:6px;
    font-size:14px;
}

.message-box{
    background:#f4f7fb;
    padding:15px;
    border-radius:8px;
    font-size:14px;
}
@media(min-width:768px){
    .grid-2{
        grid-template-columns:repeat(2,1fr);
    }
}
@media(max-width:768px){
    .header{
        flex-direction:column;
        align-items:flex-start;
        gap:8px;
    }
}
@media(min-width:768px){
    .card-container{
        grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
    }
}
@keyframes fadeIn{
    from{opacity:0; transform:translateY(-10px);}
    to{opacity:1; transform:translateY(0);}
}
</style>
</head>
<body>

<div class="header">
    <h2>📋 CoatCraft CRM Dashboard</h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <span>Total Leads: <strong><?= $total ?></strong></span>
        <a href="crm-dashboard.php"><button style="background:#27ae60;color:#fff;">Open CRM Dashboard</button></a>
    </div>
</div>
<div id="notifyBanner" style="display:none;margin:16px 0;padding:16px;border-radius:14px;border:1px solid #b3d4fc;background:#eaf4ff;color:#0e4f8b;display:flex;justify-content:space-between;align-items:center;gap:12px;">
    <span id="notifyBannerText">Enable notifications to receive new enquiry and follow-up alerts.</span>
    <button id="enableNotificationsBtn" style="background:#0f4a78;color:#fff;border:none;padding:10px 16px;border-radius:12px;cursor:pointer;">Enable Notifications</button>
</div>

<div class="stats">
    <div class="stat-card new">
        <h3><?= $newCount ?></h3>
        <p>New Leads</p>
    </div>
    <div class="stat-card contacted">
        <h3><?= $contactedCount ?></h3>
        <p>Contacted</p>
    </div>
    <div class="stat-card closed">
        <h3><?= $closedCount ?></h3>
        <p>Closed</p>
    </div>
    <div class="stat-card not-interested">
        <h3><?= $notInterestedCount ?></h3>
        <p>Not Interested</p>
    </div>
    <div class="stat-card work-done">
        <h3><?= $workDoneCount ?></h3>
        <p>Work Done</p>
    </div>
</div>

<div class="search-box">
<form method="GET">
<input type="text" name="search" placeholder="Search name, phone, location">
<button type="submit">Search</button>
<a href="view-leads.php"><button type="button">Reset</button></a>
</form>
</div>

<div class="card" style="overflow-x:auto;">
<a href="export.php"><button class="export-btn">Export to Excel</button></a>

<div class="card-container">
<?php while($row = $result->fetch_assoc()): ?>

<div class="lead-card <?= strtolower(str_replace(' ', '-', $row['status'])) ?>">

    <div class="lead-top">
        <div>
            <h3><?= $row['name'] ?></h3>
            <small>#<?= $row['id'] ?> | <?= $row['location'] ?> || 
            <?= date('d M Y', strtotime($row['created_at'])) ?></small>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="badge" style="background:<?= $row['type'] === 'residential' ? '#e8f5e9' : '#e3f2fd' ?>;color:<?= $row['type'] === 'residential' ? '#2e7d32' : '#1565c0' ?>;"><?= ucfirst($row['type']) ?></span>
            <span class="badge"><?= $row['status'] ?></span>
        </div>
    </div>

    <div class="lead-body">
        <p><strong>📞</strong> <?= $row['phone'] ?></p>
        <p><strong>📧</strong> <?= $row['email'] ?></p>
        <?php if($row['type'] === 'residential'): ?>
            <p><strong>📍 Address:</strong> <?= $row['address'] ?></p>
            <p><strong>🔨 Work Type:</strong> <?= str_replace(',', ', ', $row['work_type']) ?></p>
            <?php if($row['budget']): ?><p><strong>💰 Budget:</strong> <?= $row['budget'] ?></p><?php endif; ?>
        <?php else: ?>
            <p><strong>📐 Area:</strong> <?= $row['area'] ?></p>
            <p><strong>🏭 Usage:</strong> <?= $row['industry_usage'] ?></p>
            <p><strong>🧪 Epoxy Type:</strong> <?= $row['epoxy_type'] ?></p>
            <p><strong>📏 Thickness:</strong> <?= $row['thickness'] ?></p>
        <?php endif; ?>
        <p><strong>⏳ Timeline:</strong> <?= $row['timeline'] ?></p>
        <p><strong>📝 Latest Update:</strong> <?= $row['last_update'] ? htmlspecialchars($row['last_update']) : 'No updates yet' ?></p>
        <?php if($row['last_update_at']): ?>
            <p><small>Updated: <?= date('d M Y H:i', strtotime($row['last_update_at'])) ?></small></p>
        <?php endif; ?>
    </div>

    <div class="lead-actions">

        <!-- WhatsApp Button -->
        <a target="_blank"
        href="https://wa.me/91<?= $row['phone'] ?>?text=Hello <?= $row['name'] ?>, regarding your epoxy flooring enquiry.">
        <button class="wa-btn">WhatsApp</button>
        </a>

        <!-- View Details -->
        <button onclick="openModal(<?= $row['id'] ?>)">View</button>

        <!-- Status Update -->
        <form method="POST">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <select name="status" onchange="this.form.submit()">
                <option <?= $row['status']=='New'?'selected':'' ?>>New</option>
                <option <?= $row['status']=='Contacted'?'selected':'' ?>>Contacted</option>
                <option <?= $row['status']=='Closed'?'selected':'' ?>>Closed</option>
                <option <?= $row['status']=='Not Interested'?'selected':'' ?>>Not Interested</option>
                <option <?= $row['status']=='Work Done'?'selected':'' ?>>Work Done</option>
            </select>
            <input type="hidden" name="update_status" value="1">
        </form>

    </div>

</div>

<!-- Hidden Modal -->
<div id="modal<?= $row['id'] ?>" class="modal">
<div class="modal-box">

<div class="modal-header">
    <div>
        <h2><?= $row['name'] ?></h2>
        <p><?= $row['location'] ?> | <?= $row['phone'] ?></p>
    </div>
    <span onclick="closeModal(<?= $row['id'] ?>)" class="close-btn">&times;</span>
</div>

<div class="modal-body">

    <!-- Basic Info -->
    <div class="modal-section">
        <h3>📋 Project Overview</h3>
        <div class="grid-2">
            <p><strong>Email:</strong> <?= $row['email'] ?></p>
            <?php if($row['type'] === 'residential'): ?>
                <p><strong>Address:</strong> <?= $row['address'] ?></p>
                <p><strong>Work Type:</strong> <?= str_replace(',', ', ', $row['work_type']) ?></p>
                <?php if($row['budget']): ?><p><strong>Budget:</strong> <?= $row['budget'] ?></p><?php endif; ?>
            <?php else: ?>
                <p><strong>Area:</strong> <?= $row['area'] ?></p>
                <p><strong>Location:</strong> <?= $row['location'] ?></p>
                <p><strong>Slab Type:</strong> <?= $row['slab'] ?></p>
            <?php endif; ?>
            <p><strong>Industry Usage:</strong> <?= $row['industry_usage'] ?></p>
            <p><strong>Timeline:</strong> <?= $row['timeline'] ?></p>
            <p><strong>Start Date:</strong> <?= $row['start_date'] ?></p>
            <p><strong>Urgent:</strong> <?= $row['urgent'] ?></p>
        </div>
    </div>
            <p><strong>Industry Usage:</strong> <?= $row['industry_usage'] ?></p>
            <p><strong>Timeline:</strong> <?= $row['timeline'] ?></p>
            <p><strong>Start Date:</strong> <?= $row['start_date'] ?></p>
            <p><strong>Urgent:</strong> <?= $row['urgent'] ?></p>
        </div>
    </div>

    <!-- Technical Details (Commercial Only) -->
    <?php if($row['type'] === 'commercial'): ?>
    <div class="modal-section">
        <h3>🏗 Technical Details</h3>
        <div class="grid-2">
            <p><strong>Concrete Grade:</strong> <?= $row['concrete_grade'] ?></p>
            <p><strong>Slab Age:</strong> <?= $row['slab_age'] ?></p>
            <p><strong>Cracks:</strong> <?= $row['cracks'] ?></p>
            <p><strong>Contamination:</strong> <?= $row['contamination'] ?></p>
            <p><strong>Previous Coating:</strong> <?= $row['previous_coating'] ?></p>
            <p><strong>Moisture Issue:</strong> <?= $row['moisture_issue'] ?></p>
        </div>
    </div>

    <!-- Load & Usage (Commercial Only) -->
    <div class="modal-section">
        <h3>🚜 Load & Chemical Info</h3>
        <div class="grid-2">
            <p><strong>Heavy Load:</strong> <?= $row['heavyload'] ?></p>
            <p><strong>Forklift:</strong> <?= $row['forklift'] ?></p>
            <p><strong>Max Load:</strong> <?= $row['max_load'] ?></p>
            <p><strong>Chemical Exposure:</strong> <?= $row['chemical_exposure'] ?></p>
            <p><strong>Water Washing:</strong> <?= $row['water_washing'] ?></p>
            <p><strong>Anti Skid:</strong> <?= $row['anti_skid'] ?></p>
        </div>
    </div>

    <!-- Finish (Commercial Only) -->
    <div class="modal-section">
        <h3>🎨 Finish & Design</h3>
        <div class="grid-2">
            <p><strong>Preferred Color:</strong> <?= $row['preferred_color'] ?></p>
            <p><strong>Finish Type:</strong> <?= $row['finish_type'] ?></p>
            <p><strong>Line Marking:</strong> <?= $row['line_marking'] ?></p>
            <p><strong>Working Hours:</strong> <?= $row['working_hours'] ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update History -->
    <div class="modal-section">
        <h3>📝 Update History</h3>
        <?php
        $updatesStmt = $conn->prepare("SELECT note, created_at FROM enquiry_updates WHERE enquiry_id=? ORDER BY created_at DESC LIMIT 10");
        $updatesStmt->bind_param("i", $row['id']);
        $updatesStmt->execute();
        $updates = $updatesStmt->get_result();
        ?>
        <?php if($updates && $updates->num_rows): ?>
            <?php while($update = $updates->fetch_assoc()): ?>
                <div class="message-box">
                    <p><?= nl2br(htmlspecialchars($update['note'])) ?></p>
                    <small>Updated: <?= date('d M Y, H:i', strtotime($update['created_at'])) ?></small>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="message-box">No updates added yet.</div>
        <?php endif; ?>
    </div>

    <div class="modal-section">
        <h3>Add Client Update</h3>
        <form method="POST">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <textarea name="note" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ccc;" placeholder="Add a client update or follow-up note"></textarea>
            <button type="submit" name="add_update" value="1" style="margin-top:10px;background:#1e73be;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;">Save Update</button>
        </form>
    </div>

    <!-- Message -->
    <div class="modal-section">
        <h3>💬 Client Message</h3>
        <div class="message-box">
            <?= htmlspecialchars($row['message']) ?>
        </div>
    </div>

</div>
</div>
</div>

<?php endwhile; ?>
</div>


</div>
<script>
function openModal(id){
    document.getElementById('modal'+id).style.display="block";
}
function closeModal(id){
    document.getElementById('modal'+id).style.display="none";
}
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').catch(function(err) {
            console.warn('Service worker failed:', err);
        });
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
        notifyBanner?.style.display = 'flex';
        notifyBannerText.textContent = 'Enable notifications to receive new enquiry and follow-up alerts.';
        enableNotificationsBtn.style.display = 'inline-flex';
    } else if (Notification.permission === 'denied') {
        notifyBanner?.style.display = 'flex';
        notifyBannerText.textContent = 'Notifications are blocked. Enable them in browser settings to receive alerts.';
        enableNotificationsBtn.style.display = 'none';
    } else {
        notifyBanner?.style.display = 'none';
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