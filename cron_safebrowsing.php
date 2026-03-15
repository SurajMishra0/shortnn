<?php
/**
 * ShortNN — Safe Browsing Cron Job
 * Checks the shortener's own domain + all destination URLs against Google Safe Browsing.
 *
 * Can be run two ways:
 * 1. Standalone via cron: php cron_safebrowsing.php
 * 2. Included from api.php (auto-triggered when status is stale)
 *
 * Results are saved to data/safety_status.json — read by the dashboard.
 * This NEVER blocks URLs from working — only flags them for display.
 */
// Cron setup:  crontab -e → add:  */3 * * * * php /path/to/cron_safebrowsing.php

function runSafeBrowsingScan(): array {
    $dataFile   = __DIR__ . '/data/urls.json';
    $configFile = __DIR__ . '/config.php';
    $statusFile = __DIR__ . '/data/safety_status.json';

    $config = file_exists($configFile) ? (require $configFile) : [];
    $apiKey = $config['safe_browsing_api_key'] ?? '';

    if (!$apiKey) {
        $result = ['error' => 'No API key configured', 'checked_at' => date('c'), 'flagged' => [], 'all_safe' => true, 'total_checked' => 0];
        saveStatusFile($statusFile, $result);
        return $result;
    }

    // Collect all URLs to check
    $checkUrls = [];
    $ownDomain = $config['shortener_url'] ?? '';
    if ($ownDomain) $checkUrls['_self'] = $ownDomain;

    if (file_exists($dataFile)) {
        $data = json_decode(file_get_contents($dataFile), true) ?? [];
        foreach ($data as $code => $entry) {
            $checkUrls[$code] = $entry['url'];
        }
    }

    if (empty($checkUrls)) {
        $result = ['checked_at' => date('c'), 'total_checked' => 0, 'flagged' => [], 'all_safe' => true, 'error' => null, 'own_domain' => $ownDomain ?: '(not configured)'];
        saveStatusFile($statusFile, $result);
        return $result;
    }

    // Batch check via Safe Browsing API (max 500 per request)
    $flagged = [];
    $error = null;
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
            $labels = [
                'MALWARE'                         => 'Malware',
                'SOCIAL_ENGINEERING'              => 'Phishing',
                'UNWANTED_SOFTWARE'               => 'Unwanted Software',
                'POTENTIALLY_HARMFUL_APPLICATION' => 'Potentially Harmful',
            ];

            foreach ($result['matches'] as $match) {
                $matchedUrl = $match['threat']['url'] ?? '';
                $threatType = $match['threatType'] ?? 'UNKNOWN';

                $matchedCode = null;
                foreach ($checkUrls as $code => $url) {
                    if ($url === $matchedUrl) { $matchedCode = $code; break; }
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

    $status = [
        'checked_at'    => date('c'),
        'total_checked' => count($checkUrls),
        'flagged'       => $flagged,
        'all_safe'      => empty($flagged),
        'error'         => $error,
        'own_domain'    => $ownDomain ?: '(not configured)',
    ];

    saveStatusFile($statusFile, $status);
    return $status;
}

function saveStatusFile(string $file, array $status): void {
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── Run standalone if called directly ──
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $result = runSafeBrowsingScan();
    echo "ShortNN Safe Browsing check complete. {$result['total_checked']} URLs checked, " . count($result['flagged'] ?? []) . " flagged.\n";
}
