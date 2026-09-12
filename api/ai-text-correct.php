<?php
/**
 * Marg ERP CRM - AI QuillBot-Style Text Correction & Grammar Assistant API
 * Supports Google Gemini 1.5/2.0 Flash, LanguageTool Public NLP API, and Marg ERP Domain Correction.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// Basic auth check
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId && !isset($_SESSION['user_name'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$rawInput = file_get_contents('php://input');
$postData = json_decode($rawInput, true) ?: $_POST;

$text = trim($postData['text'] ?? '');
$mode = trim($postData['mode'] ?? 'grammar'); // 'grammar', 'professional', 'paraphrase', 'technical_steps'

if (empty($text)) {
    echo json_encode(['success' => false, 'message' => 'No text provided for AI correction']);
    exit;
}

// -------------------------------------------------------------
// 1. Check Gemini API (Google AI)
// -------------------------------------------------------------
$geminiKey = getenv('GEMINI_API_KEY');
if (!$geminiKey && function_exists('getSystemSetting')) {
    $geminiKey = getSystemSetting('gemini_api_key', '');
}

if (!empty($geminiKey)) {
    try {
        $modeInstructions = [
            'grammar' => 'Correct all spelling, grammar, punctuation, and capitalization errors. Keep the meaning exact and natural.',
            'professional' => 'Rewrite the employee notes into highly professional, polite, and clear technical English suitable for an official support ticket resolution report.',
            'paraphrase' => 'Paraphrase and rephrase like QuillBot: improve vocabulary, sentence structure, and clarity while maintaining original technical details.',
            'technical_steps' => 'Organize the notes into clear, numbered step-by-step technical actions taken by the support executive to resolve the issue.'
        ];

        $instruction = $modeInstructions[$mode] ?? $modeInstructions['grammar'];

        $sysPrompt = "You are an expert AI writing assistant, grammar checker, and paraphraser like QuillBot and Grammarly, specifically specialized in IT Support, computer software troubleshooting, and Marg ERP client resolution notes.
Task: {$instruction}
Translate any informal Hindi/Hinglish technician slang into clean professional English.
CRITICAL CONSTRAINT: Return ONLY a valid JSON object without markdown code blocks. The JSON must contain:
{
  \"corrected\": \"The corrected/enhanced text string\",
  \"explanation\": \"Short 1-sentence explanation of what was corrected or improved\",
  \"changes_count\": number_of_corrections_made
}";

        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($geminiKey);
        $postFields = [
            'contents' => [
                ['parts' => [['text' => $sysPrompt . "\n\nTechnician Raw Input: " . $text]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.3
            ]
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $raw) {
            $res = json_decode($raw, true);
            $outText = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = json_decode($outText, true);
            if ($parsed && !empty($parsed['corrected'])) {
                echo json_encode([
                    'success' => true,
                    'engine' => 'gemini_ai',
                    'original' => $text,
                    'corrected' => trim($parsed['corrected']),
                    'explanation' => $parsed['explanation'] ?? 'Grammar, vocabulary, and sentence structure enhanced.',
                    'changes_count' => (int)($parsed['changes_count'] ?? 1)
                ]);
                exit;
            }
        }
    } catch (\Throwable $e) {}
}

// -------------------------------------------------------------
// 2. Pre-process Hinglish & Technical Jargon
// -------------------------------------------------------------
function preProcessHinglish($str) {
    $hinglishMap = [
        '/\b(client\s+ka|customer\s+ka)\b/i' => 'client\'s',
        '/\b(bill\s+format\s+change\s+kr\s+dia|bill\s+format\s+change\s+kardiya|bill\s+format\s+change\s+kar\s+diya)\b/i' => 'updated invoice format layout',
        '/\b(bill\s+format)\b/i' => 'invoice format',
        '/\b(thermal\s+printer\s+me)\b/i' => 'on thermal printer',
        '/\b(com\s+port\s*(\d+)?\s*(set\s+kiya|set\s+kardiya|set\s+kr\s+dia))\b/i' => 'configured COM port $2',
        '/\b(ab\s+print\s+sahi\s+ho\s+gya|ab\s+print\s+thik\s+aa\s+rha|ab\s+print\s+sahi\s+chal\s+rha)\b/i' => 'print test successfully verified and working properly',
        '/\b(thik\s+ho\s+gya|theek\s+ho\s+gaya|sahi\s+ho\s+gya|sahi\s+ho\s+gaya|proper\s+ho\s+gya)\b/i' => 'resolved and working properly',
        '/\b(chal\s+rha|chal\s+raha)\b/i' => 'functioning correctly',
        '/\b(kam\s+nhi\s+kr\s+rha|kaam\s+nahi\s+kar\s+raha|chal\s+nhi\s+rha)\b/i' => 'was not functioning',
        '/\b(anydesk\s+pe\s+lia|anydesk\s+pe\s+liya|anydesk\s+lia|anydesk\s+liya|desk\s+connect\s+kiya)\b/i' => 'connected remotely via AnyDesk',
        '/\b(kr\s+dia|kardiya|kar\s+diya|krlia|kar\s+liya)\b/i' => 'completed',
        '/\b(re\s*indexing\s+kr\s+dia|reindexing\s+kardiya)\b/i' => 'performed data re-indexing',
        '/\b(backup\s+restore\s+kr\s+dia)\b/i' => 'restored database backup',
        '/\b(password\s+reset\s+kr\s+dia)\b/i' => 'reset user credentials',
        '/\b(fy\s+change\s+kr\s+dia)\b/i' => 'carried forward new financial year data',
        '/\b(instaled|instal)\b/i' => 'installed',
        '/\b(seting|setings)\b/i' => 'settings',
        '/\b(prnt|prntr|printr)\b/i' => 'printer'
    ];

    foreach ($hinglishMap as $pattern => $rep) {
        $str = preg_replace($pattern, $rep, $str);
    }
    return $str;
}

$isHinglish = (bool)preg_match('/\b(kr|dia|diya|kardiya|kardia|gya|gaya|rha|raha|nhi|nahi|hai|tha|thi|me|pe|se|ka|ki|ke|ab|thik|theek|sahi|kuch|bhi|aur|par|kro|karo|bheja|aaya|liya|lia)\b/i', $text);
$processedInput = $isHinglish ? preProcessHinglish($text) : $text;

// -------------------------------------------------------------
// 3. LanguageTool NLP Grammar & Spell Engine (Free Public API)
// -------------------------------------------------------------
$ltSuccess = false;
$ltCorrected = $processedInput;
$ltChanges = 0;
$ltNotes = [];

try {
    $ch = curl_init('https://api.languagetool.org/v2/check');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'text' => $processedInput,
        'language' => 'en-US'
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MargCRM-AI-Assistant/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawLt = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $rawLt) {
        $ltData = json_decode($rawLt, true);
        $wordSuggestions = [];
        if (!empty($ltData['matches'])) {
            $ltSuccess = true;
            $matches = $ltData['matches'];

            foreach ($matches as $m) {
                $wrong = substr($processedInput, $m['offset'], $m['length']);
                $reps = [];
                if (!empty($m['replacements'])) {
                    foreach (array_slice($m['replacements'], 0, 4) as $repObj) {
                        if (!empty($repObj['value'])) {
                            $reps[] = $repObj['value'];
                        }
                    }
                }
                if (!empty($reps) && !empty($wrong)) {
                    $wordSuggestions[] = [
                        'word' => $wrong,
                        'offset' => (int)$m['offset'],
                        'length' => (int)$m['length'],
                        'suggestions' => $reps,
                        'message' => $m['message'] ?? 'Spelling / grammar suggestion'
                    ];
                }
            }

            // Apply replacements in reverse offset order to keep indices valid
            usort($matches, function($a, $b) {
                return $b['offset'] <=> $a['offset'];
            });

            foreach ($matches as $m) {
                if (!empty($m['replacements']) && isset($m['replacements'][0]['value'])) {
                    $rep = $m['replacements'][0]['value'];
                    $offset = $m['offset'];
                    $len = $m['length'];
                    $ltCorrected = substr_replace($ltCorrected, $rep, $offset, $len);
                    $ltChanges++;
                    if (count($ltNotes) < 3 && !empty($m['message'])) {
                        $ltNotes[] = $m['message'];
                    }
                }
            }
        }
    }
} catch (\Throwable $eLt) {}

// -------------------------------------------------------------
// 3. Domain & Hinglish Technical Post-Processor & Paraphraser
// -------------------------------------------------------------
function applyDomainPolishing($str, $targetMode) {
    // 1. Common Hinglish / colloquial tech replacements
    $techReplacements = [
        '/\b(krlia|kardia|kar diya|kr dia|kardiya|kr diya|ho gya|ho gaya)\b/i' => 'completed',
        '/\b(kro|karo|krna h|karna hai)\b/i' => 'resolve',
        '/\b(thik|theek|sahi|proper)\s+(ho\s+gya|ho\s+gaya|chal\s+rha|chal\s+raha)\b/i' => 'verified working properly',
        '/\b(not\s+work|not\s+working|kam\s+nhi\s+kr\s+rha|kaam\s+nahi\s+kar\s+raha)\b/i' => 'was malfunctioning',
        '/\b(remoted\s+desk|desk\s+pe\s+connect|anydesk\s+lia|anydesk\s+liya)\b/i' => 'connected via AnyDesk remote desktop',
        '/\b(instaled|instal)\b/i' => 'installed',
        '/\b(seting|setings)\b/i' => 'settings',
        '/\b(prnt|prntr|printr)\b/i' => 'printer',
        '/\b(bill\s+format)\b/i' => 'invoice format',
        '/\b(re\s*indexing|reindexing)\b/i' => 'data re-indexing',
        '/\b(driver\s+update)\b/i' => 'updated device drivers',
        '/\b(port\s+set)\b/i' => 'configured communication port',
        '/\b(password\s+reset)\b/i' => 'reset administrative credentials',
        '/\b(fy\s+change|financial\s+year\s+change)\b/i' => 'carried forward new financial year data',
        '/\b(ok|done|tested)\b/i' => 'successfully verified'
    ];

    $working = $str;
    foreach ($techReplacements as $pattern => $rep) {
        $working = preg_replace($pattern, $rep, $working);
    }

    // Capitalize sentences
    $working = preg_replace_callback('/(^|[.!?]\s+)([a-z])/', function($m) {
        return $m[1] . strtoupper($m[2]);
    }, trim($working));

    // Ensure terminal punctuation
    if (!empty($working) && !in_array(substr($working, -1), ['.', '!', '?'])) {
        $working .= '.';
    }

    // Mode-specific formatting
    if ($targetMode === 'technical_steps') {
        $sentences = preg_split('/(?<=[.!?])\s+/', $working, -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) > 1) {
            $stepped = [];
            foreach ($sentences as $idx => $s) {
                $stepped[] = ($idx + 1) . ". " . trim($s);
            }
            return implode("\n", $stepped);
        } else {
            return "1. Connected to client system and diagnosed issue.\n2. " . $working . "\n3. Tested and confirmed resolution with customer.";
        }
    } elseif ($targetMode === 'professional') {
        if (!preg_match('/^(connected|resolved|configured|assisted|updated)/i', $working)) {
            $working = "Resolved client query: " . lcfirst($working);
        }
    }

    return $working;
}

$polished = applyDomainPolishing($ltSuccess ? $ltCorrected : $text, $mode);
$explanation = $ltSuccess
    ? ("Corrected " . $ltChanges . " grammar/spelling errors" . (!empty($ltNotes) ? " (" . implode('; ', array_slice($ltNotes, 0, 2)) . ")" : "") . " and polished technical phrasing.")
    : "Polished technical phrasing, capitalization, and sentence structure.";

echo json_encode([
    'success' => true,
    'engine' => $ltSuccess ? 'languagetool_nlp' : 'domain_synthesizer',
    'original' => $text,
    'corrected' => $polished,
    'explanation' => $explanation,
    'changes_count' => max(1, $ltChanges),
    'word_matches' => $wordSuggestions ?? []
]);
