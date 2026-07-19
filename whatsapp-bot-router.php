<?php
// Guided-menu WhatsApp bot state machine (Stages 1-4 of the AI Sales Chatbot
// Master Workflow spec) — driven by whatsapp-webhook.php on every inbound
// message from a phone whose bot isn't paused. Reads/writes
// whatsapp_bot_sessions (leads.php) and sends interactive list/button
// messages (mailer.php) built from the static content in
// whatsapp-bot-content.php. Falls back to the pre-existing keyword-matching
// bot (resolveWhatsAppBotReply()) for free-text input outside the menu tree,
// so a user who ignores the menu and just types "price" still gets an answer.

const WA_BOT_MENU_BUTTON = 'Choose an option';

function routeWhatsAppBotMessage(mysqli $conn, int $companyId, string $from, array $session, ?string $interactiveId, string $body, ?int $enquiryId): void {
    $stage = $session['stage'];
    $context = $session['context'];

    switch ($stage) {
        case 'industry_list':
            waBotRouteIndustryList($conn, $companyId, $from, $interactiveId, $body, $context, $enquiryId);
            return;
        case 'industry_detail':
            waBotRouteIndustryDetail($conn, $companyId, $from, $interactiveId, $body, $context, $enquiryId);
            return;
        case 'service_list':
            waBotRouteServiceList($conn, $companyId, $from, $interactiveId, $body, $context, $enquiryId);
            return;
        case 'service_detail':
            waBotRouteServiceDetail($conn, $companyId, $from, $interactiveId, $body, $context, $enquiryId);
            return;
        case 'welcome_sent':
            waBotRouteWelcomeSent($conn, $companyId, $from, $interactiveId, $body, $enquiryId);
            return;
        case 'ai_consult':
            waBotRouteAiConsult($conn, $companyId, $from, $interactiveId, $body, $context, $enquiryId);
            return;
        case 'welcome':
        default:
            waBotSendWelcomeMenu($conn, $companyId, $from, $enquiryId);
            updateWhatsAppBotSession($conn, $companyId, $from, 'welcome_sent', []);
            return;
    }
}

// Sends the reply, and if it actually went out, logs it for whatsapp-chats.php.
function waBotSendAndLog(mysqli $conn, int $companyId, string $from, ?int $enquiryId, bool $sent, string $logSummary): void {
    if ($sent) {
        logWhatsAppBotReply($conn, $companyId, $from, $logSummary, $enquiryId);
    }
}

function waBotSendWelcomeMenu(mysqli $conn, int $companyId, string $from, ?int $enquiryId): void {
    $sections = [[
        'title' => null,
        'rows' => [
            ['id' => 'menu:industry', 'title' => 'Explore by Industry'],
            ['id' => 'menu:service', 'title' => 'Explore by Service'],
            ['id' => 'menu:estimate', 'title' => 'Get Instant Estimate'],
            ['id' => 'menu:sitevisit', 'title' => 'Book Free Site Visit'],
            ['id' => 'menu:projects', 'title' => 'Previous Projects'],
            ['id' => 'menu:ai_expert', 'title' => 'Talk to AI Expert'],
            ['id' => 'menu:human_expert', 'title' => 'Talk to Human Expert'],
        ],
    ]];
    $sent = sendWhatsAppBotList($from, WA_BOT_WELCOME_BODY, WA_BOT_MENU_BUTTON, $sections);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, WA_BOT_WELCOME_BODY);
}

function waBotRouteWelcomeSent(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, ?int $enquiryId): void {
    switch ($interactiveId) {
        case 'menu:industry':
            waBotSendIndustryList($conn, $companyId, $from, $enquiryId, 1);
            updateWhatsAppBotSession($conn, $companyId, $from, 'industry_list', ['page' => 1]);
            return;
        case 'menu:service':
            waBotSendServiceCategory($conn, $companyId, $from, $enquiryId, 1, null);
            updateWhatsAppBotSession($conn, $companyId, $from, 'service_list', ['page' => 1, 'entry_industry' => null]);
            return;
        case 'menu:estimate':
            captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Instant Estimate');
            // Gives a rough, terms-and-conditions-qualified ballpark (from
            // WA_BOT_REPLY_PRICING, same text as the "Price" button/keyword,
            // kept in sync deliberately) rather than a bare "team will reach
            // out" — the real estimate ENGINE (stage 6) is still out of
            // scope, this is just enough to give the customer an idea.
            $reply = WA_BOT_REPLY_PRICING . "\n\nOur team will prepare your detailed instant estimate and reach out to you shortly. 🙂\n\nReply with another option any time.";
            waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
            updateWhatsAppBotSession($conn, $companyId, $from, 'welcome_sent', []);
            return;
        case 'menu:sitevisit':
            captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Free Site Visit');
            $reply = "Great! Our team will contact you shortly to schedule your FREE site visit. 🙂\n\nReply with another option any time.";
            waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
            updateWhatsAppBotSession($conn, $companyId, $from, 'welcome_sent', []);
            return;
        case 'menu:projects':
            $reply = "Check out our completed projects here: https://coatcraftsolutions.com/gallery.html 📸\n\nReply with another option any time.";
            waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
            updateWhatsAppBotSession($conn, $companyId, $from, 'welcome_sent', []);
            return;
        case 'menu:ai_expert':
            captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: started AI Expert consultation');
            $reply = "Hi! I'm CoatCraft's AI flooring assistant 🤖 Ask me anything about our services, pricing, or which flooring suits your needs.\n\nType *human* anytime to talk to our team instead.";
            waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
            updateWhatsAppBotSession($conn, $companyId, $from, 'ai_consult', ['ai_history' => []]);
            return;
        case 'menu:human_expert':
            captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Human Expert');
            $reply = "Connecting you to our team — they'll be with you shortly. 🙂";
            waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
            pauseWhatsAppBot($conn, $companyId, $from, 'bot:human_handoff');
            updateWhatsAppBotSession($conn, $companyId, $from, 'human_handoff', []);
            return;
        default:
            // Unrecognized text (or a stray interactive id) — never dead-end,
            // re-send the menu the same way the original keyword bot always
            // answered something.
            waBotSendWelcomeMenu($conn, $companyId, $from, $enquiryId);
            updateWhatsAppBotSession($conn, $companyId, $from, 'welcome_sent', []);
    }
}

// --- Stage 2: Industry select -------------------------------------------

function waBotSendIndustryList(mysqli $conn, int $companyId, string $from, ?int $enquiryId, int $page): void {
    $keys = array_keys(WA_INDUSTRIES);
    if ($page === 1) {
        $pageKeys = array_slice($keys, 0, 8);
        $rows = array_map(fn($k) => ['id' => 'industry:' . $k, 'title' => WA_INDUSTRIES[$k]['label']], $pageKeys);
        $rows[] = ['id' => 'industry:more', 'title' => 'Show more industries'];
    } else {
        $pageKeys = array_slice($keys, 8);
        $rows = array_map(fn($k) => ['id' => 'industry:' . $k, 'title' => WA_INDUSTRIES[$k]['label']], $pageKeys);
    }
    $body = "Please choose your industry:";
    $sent = sendWhatsAppBotList($from, $body, 'Select Industry', [['title' => null, 'rows' => $rows]]);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, $body);
}

function waBotRouteIndustryList(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, array $context, ?int $enquiryId): void {
    if ($interactiveId === null) {
        waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
        return;
    }
    if ($interactiveId === 'industry:more') {
        waBotSendIndustryList($conn, $companyId, $from, $enquiryId, 2);
        updateWhatsAppBotSession($conn, $companyId, $from, 'industry_list', ['page' => 2]);
        return;
    }
    $key = substr($interactiveId, strlen('industry:'));
    if (!isset(WA_INDUSTRIES[$key])) {
        waBotSendIndustryList($conn, $companyId, $from, $enquiryId, 1);
        updateWhatsAppBotSession($conn, $companyId, $from, 'industry_list', ['page' => 1]);
        return;
    }
    $industry = WA_INDUSTRIES[$key];
    $recommendedLabels = array_map(fn($svcKey) => WA_SERVICES[$svcKey]['label'], $industry['recommended_services']);
    $bodyText = $industry['blurb'] . "\n\nRecommended: " . implode(', ', $recommendedLabels);
    $sent = sendWhatsAppBotButtons($from, $bodyText, [
        ['id' => 'svcmenu:price:' . $key, 'title' => 'Price'],
        ['id' => 'svcmenu:learnmore:' . $key, 'title' => 'Learn More'],
        ['id' => 'svcmenu:visit:' . $key, 'title' => 'Site Visit'],
    ]);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, $bodyText);
    updateWhatsAppBotSession($conn, $companyId, $from, 'industry_detail', ['industry' => $key]);
}

function waBotRouteIndustryDetail(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, array $context, ?int $enquiryId): void {
    if ($interactiveId === null) {
        waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
        return;
    }
    $industryKey = $context['industry'] ?? null;
    if (str_starts_with($interactiveId, 'svcmenu:price:')) {
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, WA_BOT_REPLY_PRICING), WA_BOT_REPLY_PRICING);
        return; // stays in industry_detail
    }
    if (str_starts_with($interactiveId, 'svcmenu:visit:')) {
        captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Site Visit for industry "' . $industryKey . '"');
        $reply = "Great! Our team will contact you shortly to schedule your FREE site visit. 🙂";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        return; // stays in industry_detail
    }
    if (str_starts_with($interactiveId, 'svcmenu:learnmore:') && $industryKey !== null && isset(WA_INDUSTRIES[$industryKey])) {
        $recommended = WA_INDUSTRIES[$industryKey]['recommended_services'];
        waBotSendFilteredServiceList($conn, $companyId, $from, $enquiryId, $recommended);
        updateWhatsAppBotSession($conn, $companyId, $from, 'service_list', ['page' => null, 'entry_industry' => $industryKey]);
        return;
    }
    // Unrecognized — re-send this industry's sub-menu is unnecessary detail;
    // fall back to the keyword bot so the conversation still gets an answer.
    waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
}

// --- Stage 3: Service select ---------------------------------------------

// Groups WA_SERVICES by category, preserving first-seen category order, so
// Stage 3 can paginate one category per list message (Meta caps interactive
// lists at 10 rows total).
function waBotServiceCategories(): array {
    $categories = [];
    foreach (WA_SERVICES as $key => $svc) {
        $categories[$svc['category']][] = $key;
    }
    return $categories;
}

function waBotServiceRow(string $key): array {
    $svc = WA_SERVICES[$key];
    return ['id' => 'svc:' . $key, 'title' => $svc['short_label'] ?? $svc['label']];
}

function waBotSendServiceCategory(mysqli $conn, int $companyId, string $from, ?int $enquiryId, int $page, ?string $entryIndustry): void {
    $categories = array_values(waBotServiceCategories());
    $categoryNames = array_keys(waBotServiceCategories());
    $pageIndex = $page - 1;
    $keysThisPage = $categories[$pageIndex] ?? $categories[0];
    $categoryName = $categoryNames[$pageIndex] ?? $categoryNames[0];
    $rows = array_map('waBotServiceRow', $keysThisPage);
    if ($page < count($categories)) {
        $rows[] = ['id' => 'svc:more', 'title' => 'Show more services'];
    }
    $body = "Please choose a service" . ($categoryName ? " ({$categoryName}):" : ':');
    $sent = sendWhatsAppBotList($from, $body, 'Select Service', [['title' => $categoryName, 'rows' => $rows]]);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, $body);
}

function waBotSendFilteredServiceList(mysqli $conn, int $companyId, string $from, ?int $enquiryId, array $serviceKeys): void {
    $rows = array_map('waBotServiceRow', $serviceKeys);
    $body = "Recommended services for you:";
    $sent = sendWhatsAppBotList($from, $body, 'Select Service', [['title' => null, 'rows' => $rows]]);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, $body);
}

function waBotRouteServiceList(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, array $context, ?int $enquiryId): void {
    if ($interactiveId === null) {
        waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
        return;
    }
    if ($interactiveId === 'svc:more') {
        $nextPage = (int)($context['page'] ?? 1) + 1;
        waBotSendServiceCategory($conn, $companyId, $from, $enquiryId, $nextPage, $context['entry_industry'] ?? null);
        updateWhatsAppBotSession($conn, $companyId, $from, 'service_list', ['page' => $nextPage, 'entry_industry' => $context['entry_industry'] ?? null]);
        return;
    }
    $key = substr($interactiveId, strlen('svc:'));
    if (!isset(WA_SERVICES[$key])) {
        waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
        return;
    }
    waBotSendServiceDetail($conn, $companyId, $from, $enquiryId, $key);
    updateWhatsAppBotSession($conn, $companyId, $from, 'service_detail', ['service' => $key]);
}

// --- Stage 4: Service details ---------------------------------------------

function waBotSendServiceDetail(mysqli $conn, int $companyId, string $from, ?int $enquiryId, string $key): void {
    $svc = WA_SERVICES[$key];
    $detailText =
        "*{$svc['label']}*\n\n" .
        "{$svc['description']}\n\n" .
        "✔ Benefits: {$svc['benefits']}\n" .
        "✔ Applications: {$svc['applications']}\n" .
        "✔ Recommended Thickness: {$svc['thickness']}\n" .
        "✔ Expected Life: {$svc['expected_life']}\n" .
        "✔ Installation Time: {$svc['install_time']}\n" .
        "✔ Maintenance: {$svc['maintenance']}\n" .
        "✔ Warranty: {$svc['warranty']}\n" .
        "✔ Suited for: {$svc['industries']}";
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $detailText), $detailText);

    $submenuBody = "Would you like:";
    $rows = [
        ['id' => 'svcdetail:price:' . $key, 'title' => 'Price'],
        ['id' => 'svcdetail:estimate:' . $key, 'title' => 'Estimate'],
        ['id' => 'svcdetail:quotation:' . $key, 'title' => 'Quotation'],
        ['id' => 'svcdetail:visit:' . $key, 'title' => 'Site Visit'],
    ];
    $sent = sendWhatsAppBotList($from, $submenuBody, WA_BOT_MENU_BUTTON, [['title' => null, 'rows' => $rows]]);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, $sent, $submenuBody);
}

function waBotRouteServiceDetail(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, array $context, ?int $enquiryId): void {
    if ($interactiveId === null) {
        waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
        return;
    }
    $serviceKey = $context['service'] ?? null;
    $serviceLabel = ($serviceKey !== null && isset(WA_SERVICES[$serviceKey])) ? WA_SERVICES[$serviceKey]['label'] : $serviceKey;

    if (str_starts_with($interactiveId, 'svcdetail:price:')) {
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, WA_BOT_REPLY_PRICING), WA_BOT_REPLY_PRICING);
        return; // stays in service_detail
    }
    if (str_starts_with($interactiveId, 'svcdetail:estimate:')) {
        captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Estimate for "' . $serviceLabel . '"');
        $reply = WA_BOT_REPLY_PRICING . "\n\nOur team will prepare your detailed estimate for {$serviceLabel} and reach out shortly. 🙂";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        return;
    }
    if (str_starts_with($interactiveId, 'svcdetail:quotation:')) {
        captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Quotation for "' . $serviceLabel . '"');
        $reply = "Thanks! Our sales team will prepare your quotation for {$serviceLabel} and reach out shortly. 🙂";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        return;
    }
    if (str_starts_with($interactiveId, 'svcdetail:visit:')) {
        captureWhatsAppBotInterest($conn, $companyId, $from, $enquiryId, 'WhatsApp bot: requested Site Visit for "' . $serviceLabel . '"');
        $reply = "Great! Our team will contact you shortly to schedule your FREE site visit. 🙂";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        return;
    }
    waBotFallbackToKeywordReply($conn, $companyId, $from, $body, $enquiryId);
}

// --- Stage 5: AI Expert consultation (Gemini) -----------------------------

// Any inbound message while stage is 'ai_consult'. A plain "human" (or
// similar) request, or a Gemini call failure, both hand off to a human the
// same way menu:human_expert does — the customer is never left stuck with
// an AI that can't help and no visible way out.
function waBotRouteAiConsult(mysqli $conn, int $companyId, string $from, ?string $interactiveId, string $body, array $context, ?int $enquiryId): void {
    $trimmed = trim($body);

    if (preg_match('/\b(human|agent|representative|staff|real person|talk to (a )?person|call me)\b/i', $trimmed)) {
        $reply = "Sure! Connecting you to our team — they'll be with you shortly. 🙂";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        pauseWhatsAppBot($conn, $companyId, $from, 'bot:ai_expert_handoff');
        updateWhatsAppBotSession($conn, $companyId, $from, 'human_handoff', []);
        return;
    }

    $history = $context['ai_history'] ?? [];
    $aiReply = callGeminiConsult($history, $trimmed);

    if ($aiReply === null) {
        $reply = "Sorry, I'm having trouble right now 🙏 Connecting you to our team instead.";
        waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
        pauseWhatsAppBot($conn, $companyId, $from, 'bot:ai_expert_failure');
        updateWhatsAppBotSession($conn, $companyId, $from, 'human_handoff', []);
        return;
    }

    waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $aiReply), $aiReply);

    $history[] = ['role' => 'user', 'text' => $trimmed];
    $history[] = ['role' => 'model', 'text' => $aiReply];
    if (count($history) > WA_BOT_AI_MAX_HISTORY) {
        $history = array_slice($history, -WA_BOT_AI_MAX_HISTORY);
    }
    updateWhatsAppBotSession($conn, $companyId, $from, 'ai_consult', ['ai_history' => $history]);
}

// Shared fallback for free-text input received while inside the menu tree
// (not stage welcome/welcome_sent) — preserves the original keyword bot's
// behavior (pricing/services/warranty/fallback) so ignoring the menu never
// dead-ends the conversation. Stage is left unchanged.
function waBotFallbackToKeywordReply(mysqli $conn, int $companyId, string $from, string $body, ?int $enquiryId): void {
    $reply = resolveWhatsAppBotReply($body);
    waBotSendAndLog($conn, $companyId, $from, $enquiryId, sendWhatsAppBotReply($from, $reply), $reply);
}
