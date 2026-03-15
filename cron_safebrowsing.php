<?php
/**
 * ShortNN — Safe Browsing Cron Job
 * Checks the shortener's own domain + all destination URLs against Google Safe Browsing.
 *
 * Results are saved to data/safety_status.json — read by the dashboard.
 * This NEVER blocks URLs from working — only flags them for display.
 */
// Cron setup:  crontab -e → add: */3 * * * * php /path/to/cron_safebrowsing.php

define('DATA_FILE', __DIR__ . '/data/urls.json');
define('CONFIG_FILE', __DIR__ . '/config.php');
define('STATUS_FILE', __DIR__ . '/data/safety_status.json');

// ── Load config ──
$CONFIG = file_exists(CONFIG_FILE) ? (require CONFIG_FILE) : [];
$apiKey = $CONFIG['safe_browsing_api_key'] ?? '';

if (!$apiKey) {
    saveStatus(['error' => 'No API key configured', 'checked_at' => date('c'), 'results' => []]);
    exit;
}

// ── Read all URLs ──
$urls = [];
if (file_exists(DATA_FILE)) {
    $data = json_decode(file_get_contents(DATA_FILE), true) ?? [];
    foreach ($data as $code => $entry) {
        $urls[$code] = $entry['url'];
    }
}

// ── Also check shortener's own domain (from config or auto-detect) ──
$ownDomain = $CONFIG['shortener_url'] ?? '';

// ── Collect all URLs to check ──
$checkUrls = [];
if ($ownDomain) {
    $checkUrls['_self'] = $ownDomain;
}
foreach ($urls as $code => $url) {
    $checkUrls[$code] = $url;
}

if (empty($checkUrls)) {
    saveStatus([
        'checked_at' => date('c'),
        'total_checked' => 0,
        'flagged' => [],
        'all_safe' => true,
        'error' => null,
    ]);
    exit;
}

// ── Batch check via Safe Browsing API (max 500 per request) ──
$flagged = [];
$error = null;

// Build threat entries (API supports batch)
$batches = array_chunk($checkUrls, 500, true);

foreach ($batches as $batch) {
    $threatEntries = [];
    foreach ($batch as $url) {
        $threatEntries[] = ['url' => $url];
    }

    $payload = json_encode([
        'client' => ['clientId' => 'shortnn-cron', 'clientVersion' => '1.0'],
        'threatInfo' => [
            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
            'platformTypes'    => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries'    => $threatEntries,
        ],
    ]);

    $endpoint = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . urlencode($apiKey);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);

    $response = @file_get_contents($endpoint, false, $ctx);

    if ($response === false) {
        $error = 'Safe Browsing API unreachable';
        break;
    }

    $result = json_decode($response, true);

    if (isset($result['error'])) {
        $error = 'API error: ' . ($result['error']['message'] ?? 'Unknown');
        break;
    }

    if (!empty($result['matches'])) {
        foreach ($result['matches'] as $match) {
            $matchedUrl = $match['threat']['url'] ?? '';
            $threatType = $match['threatType'] ?? 'UNKNOWN';

            $labels = [
                'MALWARE'                         => 'Malware',
                'SOCIAL_ENGINEERING'              => 'Phishing',
                'UNWANTED_SOFTWARE'               => 'Unwanted Software',
                'POTENTIALLY_HARMFUL_APPLICATION' => 'Potentially Harmful',
            ];

            // Find which code this URL belongs to
            $matchedCode = null;
            foreach ($checkUrls as $code => $url) {
                if ($url === $matchedUrl) {
                    $matchedCode = $code;
                    break;
                }
            }

            $flagged[] = [
                'code'    => $matchedCode ?? '?',
                'url'     => $matchedUrl,
                'threat'  => $labels[$threatType] ?? $threatType,
                'is_self' => ($matchedCode === '_self'),
            ];
        }
    }
}

// ── Save result ──
saveStatus([
    'checked_at'    => date('c'),
    'total_checked' => count($checkUrls),
    'flagged'       => $flagged,
    'all_safe'      => empty($flagged),
    'error'         => $error,
    'own_domain'    => $ownDomain ?: '(not configured)',
]);

function saveStatus(array $status): void {
    @mkdir(dirname(STATUS_FILE), 0755, true);
    file_put_contents(STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo "ShortNN Safe Browsing check complete. " . count($checkUrls) . " URLs checked, " . count($flagged) . " flagged.\n";
