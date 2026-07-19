<?php
// Stage 5 (AI Expert) of the WhatsApp bot — real free-form flooring
// consultation via Google's Gemini API (generativelanguage.googleapis.com,
// free tier — see GEMINI_API_KEY/GEMINI_MODEL in config.php). Reached when a
// session's stage is 'ai_consult' (whatsapp-bot-router.php). Kept in its own
// file rather than mailer.php since mailer.php is scoped to "sending", not
// AI text generation.

// Exchanges kept in whatsapp_bot_sessions.context_json['ai_history'] and
// replayed to Gemini each turn, capped to bound prompt size/DB row growth.
const WA_BOT_AI_MAX_HISTORY = 10;

function waBotAiSystemPrompt(): string {
    $serviceLabels = implode(', ', array_map(fn($s) => $s['label'], WA_SERVICES));
    $industryLabels = implode(', ', array_map(fn($i) => $i['label'], WA_INDUSTRIES));
    return
        "You are a friendly flooring consultant chatting with a customer on WhatsApp for CoatCraft Solutions, " .
        "an epoxy/PU industrial and residential flooring company based in Pune, Maharashtra, India.\n\n" .
        "Keep replies short (2-5 sentences), warm, and in plain text — no markdown headers or bullet symbols beyond simple emoji.\n\n" .
        "Services CoatCraft offers: {$serviceLabels}.\n" .
        "Industries served: {$industryLabels}.\n\n" .
        "Only quote this pricing, never invent numbers:\n" . WA_BOT_REPLY_PRICING . "\n\n" .
        "Answer flooring/CoatCraft-related questions helpfully, using the above as ground truth. " .
        "If asked something unrelated to flooring or CoatCraft, politely steer the conversation back. " .
        "If the customer wants to book a firm site visit, get an exact quotation, or explicitly asks for a real person, " .
        "tell them to type 'human' and you'll connect them to the team — don't try to book or quote anything yourself.";
}

/* Calls Gemini's generateContent REST endpoint with the running conversation
   history plus the latest user message. Returns the reply text, or null on
   any failure (missing/invalid key, network error, unexpected response
   shape) so the caller can fall back to human handoff instead of leaving the
   customer without a reply. */
function callGeminiConsult(array $history, string $userMessage): ?string {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'CHANGE_ME') {
        return null;
    }

    $contents = [];
    foreach ($history as $turn) {
        $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $payload = [
        'system_instruction' => ['parts' => [['text' => waBotAiSystemPrompt()]]],
        'contents' => $contents,
        // thinkingBudget: 0 disables Gemini 3.x's extended-reasoning mode —
        // without it, the model spends most of maxOutputTokens on an
        // internal "thought" pass and the visible WhatsApp reply gets cut
        // off mid-sentence. A short FAQ-style consult doesn't need it.
        'generationConfig' => [
            'maxOutputTokens' => 300,
            'temperature' => 0.7,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ],
    ];

    $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash';
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return $text !== null ? trim($text) : null;
    }
    error_log('Gemini AI consult call failed: HTTP ' . $httpCode . ' ' . ($curlError ?: $response));
    return null;
}
