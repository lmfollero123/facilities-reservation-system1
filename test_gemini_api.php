<?php
/**
 * Quick test script for Gemini API - run from command line: php test_gemini_api.php
 * Delete this file after debugging (or add to .gitignore)
 */
require_once __DIR__ . '/config/app.php';
$configPath = __DIR__ . '/config/gemini_config.php';
if (!file_exists($configPath)) {
    die("ERROR: config/gemini_config.php not found. Create it from gemini_config.example.php\n");
}
require_once $configPath;

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
    die("ERROR: GEMINI_API_KEY not set in config/gemini_config.php\n");
}

$apiKey = GEMINI_API_KEY;

// Step 1: List available models
echo "=== Step 1: Listing available models ===\n";
$listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;
$ch = curl_init($listUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15
]);
$listRaw = curl_exec($ch);
$listCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $listCode\n";
if ($listCode === 200) {
    $listData = json_decode($listRaw, true);
    $models = $listData['models'] ?? [];
    echo "Available models:\n";
    foreach ($models as $m) {
        $name = $m['name'] ?? $m['displayName'] ?? '?';
        echo "  - $name\n";
    }
} else {
    echo "Error: " . substr($listRaw, 0, 500) . "\n";
}

// Step 2: Try generateContent
echo "\n=== Step 2: Testing generateContent ===\n";
$models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-flash-latest', 'gemini-2.0-flash'];
foreach ($models as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    $payload = [
        'contents' => [['parts' => [['text' => 'Say "Hello" in one word.']]]]
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Model $model: HTTP $code - ";
    if ($code === 200) {
        $data = json_decode($raw, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        echo "SUCCESS! Response: " . trim($text) . "\n";
        break;
    } else {
        echo substr($raw, 0, 200) . "\n";
    }
}
