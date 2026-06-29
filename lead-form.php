<?php
require __DIR__ . '/db.php';

$token = trim($_GET['t'] ?? '');
$tenant = null;
if ($token !== '') {
    $stmt = $conn->prepare("SELECT company_display_name, logo_path, theme_color FROM tenant_settings WHERE form_token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$tenant) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>Form not found</title><meta name="viewport" content="width=device-width, initial-scale=1"></head>
    <body style="font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f7fb;color:#1f2937;">
        <p>This lead form link is invalid or no longer active.</p>
    </body>
    </html>
    <?php
    exit;
}

$appBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$companyName = $tenant['company_display_name'] ?: 'Enquiry Form';
$logo = $appBasePath . ($tenant['logo_path'] ?: '/new_logo.png');
$themeColor = $tenant['theme_color'] ?: '#0f4a78';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($companyName) ?> - Enquiry Form</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:'Segoe UI',Arial,sans-serif;margin:0;background:linear-gradient(135deg, <?= htmlspecialchars($themeColor) ?>, #0f3057);}
.container{max-width:560px;margin:40px auto;background:#fff;padding:30px;border-radius:14px;box-shadow:0 18px 40px rgba(0,0,0,.18);}
.brand-header{text-align:center;margin-bottom:24px;}
.brand-logo{max-width:160px;width:100%;height:auto;}
h2{text-align:center;color:<?= htmlspecialchars($themeColor) ?>;margin:10px 0 25px;}
label{font-size:14px;font-weight:600;color:#333;display:block;margin-bottom:8px;}
.required{color:red;}
input,textarea{width:100%;padding:12px;margin-bottom:18px;border-radius:8px;border:1px solid #ccc;font-size:14px;box-sizing:border-box;}
input:focus,textarea:focus{border-color:<?= htmlspecialchars($themeColor) ?>;outline:none;}
button{width:100%;padding:14px;border:none;border-radius:8px;background:<?= htmlspecialchars($themeColor) ?>;color:#fff;font-weight:700;font-size:15px;cursor:pointer;}
button:disabled{opacity:.6;cursor:not-allowed;}
#popup{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);align-items:center;justify-content:center;z-index:999;}
#popup .box{background:#fff;padding:30px;border-radius:14px;max-width:340px;text-align:center;}
#popup button{margin-top:14px;}
</style>
</head>
<body>
<div class="container">
    <div class="brand-header">
        <img src="<?= htmlspecialchars($logo) ?>" class="brand-logo" alt="<?= htmlspecialchars($companyName) ?>" onerror="this.style.display='none'">
        <h2><?= htmlspecialchars($companyName) ?> - Enquiry Form</h2>
    </div>

    <form id="leadForm">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($token) ?>">

        <label>Full Name <span class="required">*</span></label>
        <input type="text" name="name" required>

        <label>Phone Number <span class="required">*</span></label>
        <input type="tel" name="phone" maxlength="10" inputmode="numeric" required>

        <label>Email <span class="required">*</span></label>
        <input type="email" name="email" required>

        <label>Location</label>
        <input type="text" name="location">

        <label>Message <span class="required">*</span></label>
        <textarea name="message" rows="4" required placeholder="Tell us what you need..."></textarea>

        <button type="submit" id="submitBtn">Submit Enquiry</button>
    </form>
</div>

<div id="popup">
    <div class="box">
        <h3>Thank you!</h3>
        <p>Your enquiry has been submitted. We'll get back to you soon.</p>
        <button onclick="document.getElementById('popup').style.display='none'; document.getElementById('leadForm').reset();">Close</button>
    </div>
</div>

<script>
document.getElementById('leadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerText = 'Submitting...';

    fetch('sendmail.php', { method: 'POST', body: new FormData(this) })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = 'Submit Enquiry';
            if (data.success) {
                document.getElementById('popup').style.display = 'flex';
            } else {
                alert('Error: ' + (data.message || 'Something went wrong'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerText = 'Submit Enquiry';
            alert('Something went wrong! Please try again.');
        });
});
</script>
</body>
</html>
