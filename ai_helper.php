<?php
// ai_helper.php — NEXFEEDAI
// Helper to call the local Flask food freshness API.
// Include this in upload_food.php.

if (defined('AI_HELPER_LOADED')) return;
define('AI_HELPER_LOADED', true);

// ── Config ────────────────────────────────────────────────────
define('AI_API_URL',     'http://localhost:5000/predict');
define('AI_STATUS_URL',  'http://localhost:5000/status');
define('AI_TIMEOUT_SEC', 8);   // seconds before giving up


/**
 * Check if the Flask AI API is running.
 * Returns true/false.
 */
function ai_is_running(): bool {
    $ch = curl_init(AI_STATUS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return false;
    $data = json_decode($resp, true);
    return !empty($data['alive']) && !empty($data['model_loaded']);
}


/**
 * Send food image to Flask API and get prediction.
 *
 * @param string $imagePath  Full disk path to the uploaded image file
 * @return array {
 *   'called'      => bool,        // was API called?
 *   'prediction'  => string,      // "fresh" | "rotten" | "unknown"
 *   'confidence'  => float,       // 0–100
 *   'label'       => string,      // "✅ Fresh" | "❌ Rotten"
 *   'message'     => string,
 *   'safe'        => bool,
 *   'error'       => string|null
 * }
 */
function ai_predict_food(string $imagePath): array {
    $default = [
        'called'     => false,
        'prediction' => 'unknown',
        'confidence' => 0.0,
        'label'      => '❓ Unknown',
        'message'    => 'AI check skipped.',
        'safe'       => true,    // default to safe if AI unavailable
        'error'      => null,
    ];

    if (!file_exists($imagePath)) {
        $default['error'] = 'Image file not found for AI check.';
        return $default;
    }

    // Check API is alive first (fast 2s timeout)
    if (!ai_is_running()) {
        $default['error'] = 'AI API not running. Start food_api.py.';
        return $default;
    }

    // Send image to Flask API
    $ch = curl_init(AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_TIMEOUT_SEC,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file' => new CURLFile($imagePath),
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200 || !$resp) {
        $default['error'] = "AI API call failed (HTTP $code): $err";
        return $default;
    }

    $data = json_decode($resp, true);
    if (!$data || isset($data['error'])) {
        $default['error'] = 'AI returned invalid response: ' . ($data['error'] ?? 'unknown');
        return $default;
    }

    return [
        'called'     => true,
        'prediction' => $data['prediction']  ?? 'unknown',
        'confidence' => (float)($data['confidence'] ?? 0),
        'label'      => $data['label']       ?? '❓',
        'message'    => $data['message']     ?? '',
        'safe'       => (bool)($data['safe'] ?? true),
        'error'      => null,
    ];
}
