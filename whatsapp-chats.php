<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/leads.php';

$conversations = getWhatsAppConversations($conn, $companyId);

$activeLeadId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$activeLead = null;
$thread = [];
if ($activeLeadId > 0) {
    $activeLead = getLeadById($conn, $companyId, $activeLeadId);
    if ($activeLead) {
        $thread = getWhatsAppThread($conn, $companyId, $activeLeadId);
        markWhatsAppRead($conn, $companyId, $activeLeadId);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>WhatsApp Chats | <?= htmlspecialchars($tenantName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= htmlspecialchars($tenantLogo) ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:<?= htmlspecialchars($tenantColor) ?>;--bg:#f4f7fb;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Poppins',sans-serif;background:var(--bg);color:#1f2937;}
.topbar{display:flex;align-items:center;gap:14px;background:linear-gradient(180deg, #10365a 0%, #071c34 100%);color:#fff;padding:14px 22px;}
.topbar img{height:36px;background:#fff;border-radius:8px;padding:2px;}
.topbar span{font-weight:600;font-size:16px;flex:1;}
.topbar a{color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.08);}
.topbar a:hover{background:rgba(255,255,255,.16);color:#fff;}
.chat-shell{display:flex;height:calc(100vh - 65px);}
.conv-list{width:320px;flex-shrink:0;background:#fff;border-right:1px solid #e2e8f0;overflow-y:auto;}
.conv-list h3{margin:0;padding:18px 18px 12px;font-size:15px;color:#0f172a;}
.conv-item{display:block;padding:14px 18px;text-decoration:none;color:inherit;border-bottom:1px solid #f1f5f9;position:relative;}
.conv-item:hover{background:#f8fafc;}
.conv-item.active{background:#eef3f9;}
.conv-name{font-weight:600;color:#0f172a;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:8px;}
.conv-preview{color:#64748b;font-size:12.5px;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.conv-time{color:#94a3b8;font-size:11px;margin-top:4px;}
.unread-dot{background:#22a559;color:#fff;font-size:11px;font-weight:700;border-radius:999px;padding:2px 7px;flex-shrink:0;}
.conv-empty{padding:24px 18px;color:#64748b;font-size:14px;}
.chat-pane{flex:1;display:flex;flex-direction:column;}
.chat-header{padding:16px 24px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;}
.chat-header .avatar{width:40px;height:40px;border-radius:50%;background:#0f4a78;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;}
.chat-header h3{margin:0;font-size:16px;color:#0f172a;}
.chat-header p{margin:2px 0 0;font-size:13px;color:#64748b;}
.chat-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:10px;background:#e9edf2;}
.bubble{max-width:60%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;box-shadow:0 2px 6px rgba(15,23,42,.06);}
.bubble .bubble-time{display:block;font-size:10.5px;color:#94a3b8;margin-top:4px;}
.bubble.in{align-self:flex-start;background:#fff;border-bottom-left-radius:4px;}
.bubble.out{align-self:flex-end;background:#dcf8c6;border-bottom-right-radius:4px;}
.chat-placeholder{flex:1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:15px;flex-direction:column;gap:12px;}
.chat-placeholder i{font-size:44px;color:#cbd5e1;}
@media(max-width:800px){
    .chat-shell{flex-direction:column;height:auto;}
    .conv-list{width:100%;max-height:40vh;}
}
</style>
</head>
<body>
<div class="topbar">
    <img src="<?= htmlspecialchars($tenantLogo) ?>" alt="<?= htmlspecialchars($tenantName) ?>">
    <span>WhatsApp Chats</span>
    <a href="crm-dashboard.php"><i class="fa-solid fa-arrow-left"></i>Back to Dashboard</a>
</div>
<div class="chat-shell">
    <div class="conv-list">
        <h3>Conversations</h3>
        <?php if (empty($conversations)): ?>
            <div class="conv-empty">No WhatsApp replies yet. When a lead replies, they'll show up here.</div>
        <?php else: ?>
            <?php foreach ($conversations as $conv): ?>
                <a class="conv-item <?= $activeLeadId === (int)$conv['enquiry_id'] ? 'active' : '' ?>" href="?id=<?= intval($conv['enquiry_id']) ?>">
                    <div class="conv-name">
                        <span><?= htmlspecialchars($conv['name']) ?></span>
                        <?php if ((int)$conv['unread_count'] > 0): ?>
                            <span class="unread-dot"><?= intval($conv['unread_count']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="conv-preview"><?= htmlspecialchars($conv['last_message']) ?></div>
                    <div class="conv-time"><?= date('d M Y, g:i A', strtotime($conv['last_received_at'])) ?></div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="chat-pane">
        <?php if ($activeLead): ?>
            <div class="chat-header">
                <div class="avatar"><?= strtoupper(substr($activeLead['name'], 0, 1)) ?></div>
                <div>
                    <h3><?= htmlspecialchars($activeLead['name']) ?></h3>
                    <p><?= htmlspecialchars($activeLead['phone']) ?></p>
                </div>
            </div>
            <div class="chat-body">
                <?php if (empty($thread)): ?>
                    <p style="color:#64748b;">No messages yet.</p>
                <?php else: ?>
                    <?php foreach ($thread as $msg): ?>
                        <div class="bubble <?= $msg['direction'] ?>">
                            <?= nl2br(htmlspecialchars($msg['body'])) ?>
                            <span class="bubble-time"><?= date('d M Y, g:i A', strtotime($msg['at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="chat-placeholder">
                <i class="fa-brands fa-whatsapp"></i>
                <p>Select a conversation to view messages</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
