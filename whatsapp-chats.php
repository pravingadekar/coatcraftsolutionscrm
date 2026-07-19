<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/mailer.php';

$activeLeadId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$activePhone = isset($_GET['phone']) ? preg_replace('/\D/', '', $_GET['phone']) : '';

// Manual staff replies + bot pause/resume toggle. Same inline
// POST-then-re-render-the-same-URL pattern as crm-dashboard.php — the
// form's action carries the ?id=/?phone= query string so the page reloads
// the same conversation afterward (no redirect, so it's safe to compute this
// error message here and just render it further down in the same request).
$manualReplyError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postPhone = isset($_POST['phone']) ? preg_replace('/\D/', '', $_POST['phone']) : '';
    $postLeadId = $activeLeadId > 0 ? $activeLeadId : null;
    if ($postPhone !== '') {
        if (isset($_POST['send_manual_reply'])) {
            $replyBody = trim($_POST['reply_body'] ?? '');
            if ($replyBody !== '') {
                $sent = sendManualWhatsAppReply($conn, $companyId, $postLeadId, $postPhone, $replyBody, current_user()['id']);
                if (!$sent) {
                    // WhatsApp only allows a free-text reply within 24h of the
                    // customer's last inbound message — this is the most
                    // common reason a manual reply silently fails to deliver,
                    // so surface it instead of letting staff think it sent.
                    $manualReplyError = "Message failed to send. The customer likely hasn't messaged in the last 24 hours (WhatsApp only allows free replies within that window), or there was a network/API issue. Check with them another way if this is urgent.";
                }
            }
        } elseif (isset($_POST['pause_bot'])) {
            pauseWhatsAppBot($conn, $companyId, $postPhone, 'staff:' . current_user()['id'] . ':manual');
        } elseif (isset($_POST['resume_bot'])) {
            resumeWhatsAppBot($conn, $companyId, $postPhone);
        }
    }
}

$conversations = getWhatsAppConversations($conn, $companyId);
$unknownConversations = getUnknownWhatsAppConversations($conn, $companyId);

$activeLead = null;
$thread = [];
$chatTitle = '';
$chatSubtitle = '';
$activeChatPhone = '';
if ($activeLeadId > 0) {
    $activeLead = getLeadById($conn, $companyId, $activeLeadId);
    if ($activeLead) {
        $thread = getWhatsAppThread($conn, $companyId, $activeLeadId);
        markWhatsAppRead($conn, $companyId, $activeLeadId);
        $chatTitle = $activeLead['name'];
        $chatSubtitle = $activeLead['phone'];
        $activeChatPhone = $activeLead['phone'];
    }
} elseif ($activePhone !== '') {
    $thread = getWhatsAppThreadByPhone($conn, $companyId, $activePhone);
    markWhatsAppReadByPhone($conn, $companyId, $activePhone);
    $chatTitle = $activePhone;
    $chatSubtitle = 'New number (no lead yet)';
    $activeChatPhone = $activePhone;
}
$hasActiveChat = $activeLead !== null || $activePhone !== '';
$botPaused = $activeChatPhone !== '' ? isWhatsAppBotPaused($conn, $companyId, $activeChatPhone) : false;
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
.bot-toggle{margin-left:auto;display:flex;align-items:center;gap:10px;}
.bot-badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap;}
.bot-badge.active{background:#dcfce7;color:#15803d;}
.bot-badge.paused{background:#fee2e2;color:#b91c1c;}
.bot-toggle button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;cursor:pointer;color:#334155;}
.bot-toggle button:hover{background:#f8fafc;}
.reply-error{margin:0 24px;padding:10px 14px;background:#fee2e2;color:#b91c1c;border-radius:8px;font-size:13px;border-top:1px solid #e2e8f0;}
.chat-input-form{display:flex;gap:10px;padding:14px 24px;background:#fff;border-top:1px solid #e2e8f0;}
.chat-input-form textarea{flex:1;resize:none;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-family:inherit;font-size:14px;height:44px;}
.chat-input-form button{background:var(--primary);color:#fff;border:none;border-radius:10px;padding:0 18px;font-weight:600;cursor:pointer;}
.chat-input-form button:hover{opacity:.92;}
.chat-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:10px;background:#e9edf2;}
.bubble{max-width:60%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;box-shadow:0 2px 6px rgba(15,23,42,.06);}
.bubble .bubble-time{display:block;font-size:10.5px;color:#94a3b8;margin-top:4px;}
.bubble .bubble-failed{display:block;font-size:11px;color:#b91c1c;font-weight:600;margin-top:4px;}
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

        <h3 style="margin-top:18px;">New numbers</h3>
        <?php if (empty($unknownConversations)): ?>
            <div class="conv-empty">No new numbers yet.</div>
        <?php else: ?>
            <?php foreach ($unknownConversations as $conv): ?>
                <a class="conv-item <?= $activePhone !== '' && $activePhone === preg_replace('/\D/', '', $conv['phone']) ? 'active' : '' ?>" href="?phone=<?= urlencode($conv['phone']) ?>">
                    <div class="conv-name">
                        <span><?= htmlspecialchars($conv['phone']) ?></span>
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
        <?php if ($hasActiveChat): ?>
            <div class="chat-header">
                <?php if ($activeLead): ?>
                    <div class="avatar"><?= strtoupper(substr($activeLead['name'], 0, 1)) ?></div>
                <?php else: ?>
                    <div class="avatar"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div>
                    <h3><?= htmlspecialchars($chatTitle) ?></h3>
                    <p><?= htmlspecialchars($chatSubtitle) ?></p>
                </div>
                <div class="bot-toggle">
                    <span class="bot-badge <?= $botPaused ? 'paused' : 'active' ?>"><?= $botPaused ? 'Bot paused' : 'Bot active' ?></span>
                    <form method="POST" action="?<?= $activeLeadId > 0 ? 'id=' . $activeLeadId : 'phone=' . urlencode($activePhone) ?>">
                        <input type="hidden" name="phone" value="<?= htmlspecialchars($activeChatPhone) ?>">
                        <button type="submit" name="<?= $botPaused ? 'resume_bot' : 'pause_bot' ?>" value="1"><?= $botPaused ? 'Resume bot' : 'Pause bot' ?></button>
                    </form>
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
                            <?php if (($msg['delivery_status'] ?? null) === 'failed'): ?>
                                <span class="bubble-failed"><i class="fa-solid fa-triangle-exclamation"></i> Not delivered<?= !empty($msg['error_message']) ? ' — ' . htmlspecialchars($msg['error_message']) : '' ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($manualReplyError): ?>
                <div class="reply-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($manualReplyError) ?></div>
            <?php endif; ?>
            <form method="POST" class="chat-input-form" action="?<?= $activeLeadId > 0 ? 'id=' . $activeLeadId : 'phone=' . urlencode($activePhone) ?>">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($activeChatPhone) ?>">
                <textarea name="reply_body" placeholder="Type a reply..." required></textarea>
                <button type="submit" name="send_manual_reply" value="1">Send</button>
            </form>
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
