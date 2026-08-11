<?php
// Copy this file to config.php and fill in real values. config.php is gitignored.

// Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'coatcraft_enquiry');
define('DB_USER', 'root');
define('DB_PASSWORD', '');

// SMTP (PHPMailer)
define('SMTP_HOST', 'smtpout.secureserver.net');
define('SMTP_USERNAME', 'info@workmanager.in');
define('SMTP_PASSWORD', 'CHANGE_ME');
define('SMTP_SECURE', 'ssl');
define('SMTP_PORT', 465);
define('SMTP_FROM_EMAIL', 'info@workmanager.in');
define('SMTP_FROM_NAME', 'CoatCraft Solutions');

// Web Push VAPID keys
define('VAPID_PUBLIC_KEY', 'CHANGE_ME');
define('VAPID_PRIVATE_KEY', 'CHANGE_ME');
define('VAPID_SUBJECT', 'mailto:info@workmanager.in');

// Admin login (generate a hash with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);")
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'CHANGE_ME');

// Secret key for cron-triggered scripts (reminder.php) that run across all
// tenants without a logged-in session. Generate with: php -r "echo bin2hex(random_bytes(24));"
define('CRON_SECRET', 'CHANGE_ME');

// Razorpay (billing.php / billing-create-order.php / billing-verify.php)
// Get test-mode keys from https://dashboard.razorpay.com/app/keys
define('RAZORPAY_KEY_ID', 'CHANGE_ME');
define('RAZORPAY_KEY_SECRET', 'CHANGE_ME');

// Client-facing email (enquiry thank-you, site visit confirmations) — sent via
// a separate mailbox from the tenant-notification SMTP_* config above.
define('SALES_SMTP_HOST', 'smtpout.secureserver.net');
define('SALES_SMTP_USERNAME', 'sales@coatcraftsolutions.com');
define('SALES_SMTP_PASSWORD', 'CHANGE_ME');
define('SALES_SMTP_SECURE', 'ssl');
define('SALES_SMTP_PORT', 465);
define('SALES_SMTP_FROM_EMAIL', 'sales@coatcraftsolutions.com');
define('SALES_SMTP_FROM_NAME', 'CoatCraft Solutions');

// Brevo (api.brevo.com) transactional email API — used instead of SMTP for
// all outgoing mail (tenant notification, thank-you, site visit, password
// reset) because several hosts block outbound SMTP ports but allow HTTPS.
// Get an API key from https://app.brevo.com/settings/keys/api and verify
// SMTP_FROM_EMAIL/SALES_SMTP_FROM_EMAIL as senders there.
define('BREVO_API_KEY', 'CHANGE_ME');

// Brevo transactional SMS sender — alphanumeric, max 11 chars. Must be
// DLT-registered on Brevo's dashboard for delivery to Indian numbers
// (TRAI requirement, separate from this API key).
define('BREVO_SMS_SENDER', 'CoatCraft');

// Secret key for the missed-call SMS webhook (missed-call-sms.php).
// MacroDroid sends this in every request so random IPs can't trigger SMS.
// Generate a new value with: php -r "echo bin2hex(random_bytes(16));"
define('MISSED_CALL_SECRET', 'CHANGE_ME');
define('MISSED_CALL_COMPANY_ID', 1); // your company's ID in the companies table

// Public base URL the app is served from — used to build a public image
// URL for the tenant logo in emails (linking instead of base64-embedding
// avoids Gmail's ~102KB "message clipped" truncation on branded emails).
define('SITE_URL', 'https://coatcraftcrm.workmanager.in');

// Meta WhatsApp Cloud API (graph.facebook.com) — sends the same client-facing
// notifications (enquiry thank-you, site visit confirmation) as WhatsApp
// messages alongside the existing emails. Business-initiated messages only
// work through pre-approved Message Templates (see WA_TEMPLATE_* below) —
// free-form text is rejected outside a 24h customer-initiated window. Get
// the Phone Number ID / WABA ID / a permanent System User access token from
// Meta Business Manager > WhatsApp > API Setup.
define('WA_PHONE_NUMBER_ID', 'CHANGE_ME');
define('WA_ACCESS_TOKEN', 'CHANGE_ME');
define('WA_API_VERSION', 'v21.0');
define('WA_TEMPLATE_LANG', 'en');
// Exact approved template names from WhatsApp Manager > Message Templates —
// must match exactly (name, language, and param count) or the send fails.
define('WA_TEMPLATE_ENQUIRY_THANKYOU', 'enquiry_thank_you');
define('WA_TEMPLATE_SITE_VISIT', 'site_visit_confirmation');
define('WA_TEMPLATE_QUOTE_FOLLOWUP', 'quote_followup');
define('WA_TEMPLATE_MISSED_CALL', 'missed_call_thankyou_coatcraft');

// Rule-based WhatsApp FAQ bot (whatsapp-webhook.php) — canned replies sent to
// unknown numbers (no matching CRM lead) based on simple keyword matching.
// Sent as free-form text within Meta's 24h customer-service window, so these
// are NOT real Meta templates and need no approval — edit directly to change.
define('WA_BOT_REPLY_PRICING',
    "CoatCraft Solutions - Approximate Pricing (per sq.ft):\n\n" .
    "🏠 Residential Flooring: Starting ₹300/sqft\n" .
    "🏭 Industrial Flooring: Starting ₹45/sqft (depends on thickness & material brand)\n" .
    "🎨 3D Epoxy: ₹350/sqft\n" .
    "🖨️ Digital Paper Epoxy: ₹350/sqft\n\n" .
    "*Terms & conditions apply. Final price depends on thickness, material brand, and a free site visit & survey.\n" .
    "📞 Call/WhatsApp: +91 77458 89111"
);
define('WA_BOT_REPLY_SERVICES',
    "CoatCraft Solutions - Our Services:\n\n" .
    "• Epoxy Flooring (Industrial, Metallic, 3D, Digital Paper, Italian Marble, Flakes)\n" .
    "• PU Flooring & PU Concrete\n" .
    "• ESD / Anti-Static Flooring\n" .
    "• Car Parking Flooring\n" .
    "• Chemical Resistant Flooring\n" .
    "• Epoxy Waterproofing\n" .
    "• Wall Coating & Coving\n" .
    "• Floor Repair & Refurbishment\n" .
    "• Epoxy Table Tops & Frames\n\n" .
    "📞 For details: +91 77458 89111 | 🌐 www.coatcraftsolutions.com"
);
define('WA_BOT_REPLY_WARRANTY',
    "CoatCraft Solutions flooring systems come with up to 2 years warranty (subject to terms & maintenance guidelines).\n\n" .
    "For warranty details specific to your project, call/WhatsApp us: +91 77458 89111"
);
define('WA_BOT_REPLY_FALLBACK',
    "Thank you for messaging CoatCraft Solutions! 🙂 Our team will get back to you shortly.\n\n" .
    "You can also reply 1 for Pricing, 2 for Services, 3 for Warranty — or call/WhatsApp +91 77458 89111 for immediate assistance."
);
// Sent on a phone's very first-ever inbound message, regardless of what they
// typed (even "hi") — so they see the menu before anything else.
define('WA_BOT_REPLY_MENU',
    "Welcome to CoatCraft Solutions! 👋\n\n" .
    "Please reply with a number, or just type your question:\n\n" .
    "1️⃣ Pricing\n" .
    "2️⃣ Services\n" .
    "3️⃣ Warranty\n\n" .
    "📞 Or call/WhatsApp us anytime: +91 77458 89111"
);

// Incoming-message webhook (whatsapp-webhook.php). WA_WEBHOOK_VERIFY_TOKEN is
// a random string you choose and paste into Meta's Webhook config (App >
// WhatsApp > Configuration) — Meta echoes it back on setup to prove you own
// the endpoint. WA_APP_SECRET is from Meta App Dashboard > Settings > Basic >
// App Secret — used to verify the X-Hub-Signature-256 header on every
// incoming webhook call, so a spoofed request can't inject fake messages.
define('WA_WEBHOOK_VERIFY_TOKEN', 'CHANGE_ME');
define('WA_APP_SECRET', 'CHANGE_ME');

// Google Gemini API (generativelanguage.googleapis.com) — powers the
// WhatsApp bot's "Talk to AI Expert" free-form flooring consultation
// (whatsapp-bot-ai.php). Free tier, get a key from
// https://aistudio.google.com/apikey (no card required).
define('GEMINI_API_KEY', 'CHANGE_ME');
define('GEMINI_MODEL', 'gemini-3.5-flash');

// ElevenLabs Conversational AI voice agent (elevenlabs-call-webhook.php) —
// answers phone calls forwarded from the business line when unanswered/busy.
// The live call audio itself is bridged directly between Twilio and
// ElevenLabs (configured in their dashboards, no PHP involved) — this app
// only receives ElevenLabs' post-call webhook to log the call as a CRM lead.
// ELEVENLABS_WEBHOOK_SECRET is the signing secret shown when you create the
// post-call webhook in the ElevenLabs dashboard (Conversational AI > agent >
// Webhooks) — used to verify the request really came from ElevenLabs.
define('ELEVENLABS_AGENT_ID', 'CHANGE_ME');
define('ELEVENLABS_WEBHOOK_SECRET', 'CHANGE_ME');
define('ELEVENLABS_CALL_COMPANY_ID', 1); // your company's ID in the companies table

// Sync public-form enquiries (sendmail.php) into the Laravel ERP app's own
// Leads module, right after a successful `enquiries` insert — see
// syncEnquiryToErpLead() in leads.php. Both apps' databases must live on the
// same MySQL server/port for this cross-database INSERT to work. Only fires
// for ERP_SYNC_COMPANY_ID (your company's CRM tenant id) — never for other
// CRM tenants if this app ever serves more than one business.
// ERP_SYNC_ENABLED is an instant kill-switch.
define('ERP_SYNC_ENABLED', true);
define('ERP_DB_NAME', 'CHANGE_ME');       // the ERP app's database name
define('ERP_SYNC_COMPANY_ID', 1);         // your company's ID in the companies table
define('ERP_LEAD_CREATED_BY', 'CHANGE_ME');  // ERP users.id that should own synced leads
define('ERP_LEAD_USER_ID', 'CHANGE_ME');     // usually same as ERP_LEAD_CREATED_BY
define('ERP_LEAD_PIPELINE_ID', 'CHANGE_ME'); // ERP pipelines.id to file new leads under
define('ERP_LEAD_STAGE_ID', 'CHANGE_ME');    // ERP stages.id, typically the first/"Draft" stage
define('ERP_LEAD_SOURCE_ID', 'CHANGE_ME');   // ERP sources.id, e.g. a "Websites" source
