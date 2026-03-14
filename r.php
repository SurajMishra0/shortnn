<?php
/**
 * ShortNN — Redirect Handler
 * Usage: r.php?c=<code>
 *
 * Logs visitor details (IP, UA, country, bot flag), increments visit count,
 * and 302-redirects to the target URL.
 */

define('DATA_FILE', __DIR__ . '/data/urls.json');
define('VISITS_DIR', __DIR__ . '/data/visits');

// ── Known bot patterns ──
$BOT_PATTERNS = [
    'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'adsbot',
    'bingpreview', 'facebookexternalhit', 'facebot', 'twitterbot',
    'linkedinbot', 'whatsapp', 'telegrambot', 'discordbot', 'slack',
    'semrush', 'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot',
    'yandexbot', 'baiduspider', 'duckduckbot', 'applebot',
    'python-requests', 'curl', 'wget', 'httpclient', 'java/',
    'go-http-client', 'okhttp', 'headlesschrome', 'phantomjs',
    'puppeteer', 'scrapy', 'archive.org', 'uptimerobot',
    'pingdom', 'statuscake', 'monitor', 'check_http',
];

$code = trim($_GET['c'] ?? '');

if (!$code) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Bad Request</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;background:#0e0e12;color:#e4e4e8;"><h1>400</h1><p>Missing short code.</p></body></html>';
    exit;
}

// Read data
if (!file_exists(DATA_FILE)) {
    show404();
}

$fp = fopen(DATA_FILE, 'c+');
flock($fp, LOCK_EX);

$content = stream_get_contents($fp);
$data = json_decode($content, true);

if (!is_array($data) || !isset($data[$code])) {
    flock($fp, LOCK_UN);
    fclose($fp);
    show404();
}

// Increment visits
$data[$code]['visits'] = ($data[$code]['visits'] ?? 0) + 1;
$targetUrl = $data[$code]['url'];

// Write back
fseek($fp, 0);
ftruncate($fp, 0);
fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// ── Log visitor details ──
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Detect bot
$isBot = false;
$uaLower = strtolower($ua);
foreach ($BOT_PATTERNS as $pattern) {
    if (str_contains($uaLower, $pattern)) {
        $isBot = true;
        break;
    }
}

// Get country via ip-api.com (free, no key needed, ~45 req/min limit)
$country = 'Unknown';
$countryCode = 'XX';
$city = '';
$isp = '';

// Skip geolocation for localhost/private IPs
if (!in_array($ip, ['127.0.0.1', '::1']) && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)) {
    $geoCtx = stream_context_create(['http' => ['timeout' => 2]]);
    $geoJson = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,isp", false, $geoCtx);
    if ($geoJson) {
        $geo = json_decode($geoJson, true);
        if (($geo['status'] ?? '') === 'success') {
            $country = $geo['country'] ?? 'Unknown';
            $countryCode = $geo['countryCode'] ?? 'XX';
            $city = $geo['city'] ?? '';
            $isp = $geo['isp'] ?? '';
        }
    }
}

// Build visit log entry
$visitEntry = [
    'timestamp'    => date('c'),
    'ip'           => $ip,
    'ua'           => $ua,
    'referer'      => $referer,
    'country'      => $country,
    'country_code' => $countryCode,
    'city'         => $city,
    'isp'          => $isp,
    'is_bot'       => $isBot,
];

// Save to per-code visit log file
@mkdir(VISITS_DIR, 0755, true);
$visitFile = VISITS_DIR . "/{$code}.json";

$vfp = fopen($visitFile, 'c+');
flock($vfp, LOCK_EX);
$existingVisits = json_decode(stream_get_contents($vfp), true);
if (!is_array($existingVisits)) $existingVisits = [];
$existingVisits[] = $visitEntry;
fseek($vfp, 0);
ftruncate($vfp, 0);
fwrite($vfp, json_encode($existingVisits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($vfp);
flock($vfp, LOCK_UN);
fclose($vfp);

// Redirect
header('Location: ' . $targetUrl, true, 302);
exit;

// ── 404 Page ──
function show404(): void {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;background:#0e0e12;color:#e4e4e8;">
    <h1 style="font-size:4rem;margin-bottom:0.5rem;color:#6c5ce7;">404</h1>
    <p style="color:#8a8a9a;">This short link doesn\'t exist or has been deleted.</p>
    </body></html>';
    exit;
}
