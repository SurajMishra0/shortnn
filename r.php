<?php
/**
 * ShortNN — Redirect Handler
 * Usage: r.php?c=<code>  (or via .htaccess: /slug)
 *
 * 3-tier visitor classification:
 *   "bot"        — definite bot (known UA patterns)
 *   "suspicious" — heuristic flags (datacenter IP, weird UA, rapid visits, etc.)
 *   "human"      — passed all checks
 *
 * Each visit gets a suspicion_score (0-100) and flags array.
 */

define('DATA_FILE', __DIR__ . '/data/urls.json');
define('VISITS_DIR', __DIR__ . '/data/visits');

// ────────────────────────────────────────
// Known bot UA patterns (definitive match = "bot")
// ────────────────────────────────────────
$BOT_PATTERNS = [
    'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'adsbot',
    'bingpreview', 'facebookexternalhit', 'facebot', 'twitterbot',
    'linkedinbot', 'whatsapp', 'telegrambot', 'discordbot', 'slack',
    'semrush', 'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot',
    'yandexbot', 'baiduspider', 'duckduckbot', 'applebot',
    'python-requests', 'python-urllib', 'curl/', 'wget/', 'httpclient',
    'java/', 'go-http-client', 'okhttp', 'headlesschrome', 'phantomjs',
    'puppeteer', 'scrapy', 'archive.org', 'uptimerobot',
    'pingdom', 'statuscake', 'monitor', 'check_http', 'dataprovider',
    'censysinspect', 'netcraft', 'nmap', 'masscan', 'zgrab',
];

// ────────────────────────────────────────
// Datacenter / hosting ISP keywords (suspicious signal)
// ────────────────────────────────────────
$DATACENTER_ISPS = [
    'amazon', 'aws', 'google cloud', 'google llc', 'microsoft azure',
    'microsoft corporation', 'digitalocean', 'linode', 'akamai',
    'vultr', 'ovh', 'hetzner', 'contabo', 'scaleway', 'upcloud',
    'clouvider', 'cloudflare', 'fastly', 'leaseweb', 'rackspace',
    'alibaba', 'tencent cloud', 'oracle cloud', 'ibm cloud',
    'choopa', 'hostwinds', 'kamatera', 'hosting', 'datacenter',
    'data center', 'server', 'vps', 'dedicated', 'colocation',
    'cloud computing', 'hostinger', 'godaddy', 'namecheap',
    'bluehost', 'siteground', 'ionos', 'interserver', 'psychz',
    'm247', 'cogent', 'quadranet', 'servermania', 'buyvm',
];

// ────────────────────────────────────────
// Real browser tokens (if none present → suspicious)
// ────────────────────────────────────────
$BROWSER_TOKENS = ['chrome', 'firefox', 'safari', 'edge', 'opera', 'vivaldi', 'brave', 'samsung'];

// ────────────────────────────────────────
// Classify visitor: returns [type, score, flags]
// ────────────────────────────────────────
function classifyVisitor(string $ua, string $ip, string $isp, string $code, array $existingVisits): array {
    global $BOT_PATTERNS, $DATACENTER_ISPS, $BROWSER_TOKENS;

    $flags = [];
    $score = 0;
    $uaLower = strtolower($ua);
    $ispLower = strtolower($isp);

    // ── 1. Definitive bot check ──
    foreach ($BOT_PATTERNS as $pattern) {
        if (str_contains($uaLower, $pattern)) {
            return ['bot', 100, ["Known bot pattern: {$pattern}"]];
        }
    }

    // ── 2. Empty or missing UA ──
    if (empty(trim($ua)) || $ua === 'Unknown') {
        $flags[] = 'Missing user agent';
        $score += 40;
    }

    // ── 3. Very short UA (< 30 chars is suspicious) ──
    if (strlen($ua) > 0 && strlen($ua) < 30) {
        $flags[] = 'Unusually short user agent (' . strlen($ua) . ' chars)';
        $score += 20;
    }

    // ── 4. No real browser token in UA ──
    if (!empty(trim($ua))) {
        $hasBrowser = false;
        foreach ($BROWSER_TOKENS as $token) {
            if (str_contains($uaLower, $token)) { $hasBrowser = true; break; }
        }
        if (!$hasBrowser) {
            $flags[] = 'No known browser signature in UA';
            $score += 25;
        }
    }

    // ── 5. Datacenter / hosting ISP ──
    if ($ispLower) {
        foreach ($DATACENTER_ISPS as $dc) {
            if (str_contains($ispLower, $dc)) {
                $flags[] = "Datacenter/hosting ISP: {$isp}";
                $score += 30;
                break;
            }
        }
    }

    // ── 6. Missing common browser headers ──
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $flags[] = 'Missing Accept-Language header';
        $score += 15;
    }
    if (empty($_SERVER['HTTP_ACCEPT'])) {
        $flags[] = 'Missing Accept header';
        $score += 10;
    }

    // ── 7. Rapid visits from same IP (>5 visits in last 60s) ──
    $now = time();
    $recentSameIp = 0;
    foreach ($existingVisits as $v) {
        if (($v['ip'] ?? '') === $ip) {
            $visitTime = strtotime($v['timestamp'] ?? '');
            if ($visitTime && ($now - $visitTime) < 60) {
                $recentSameIp++;
            }
        }
    }
    if ($recentSameIp >= 5) {
        $flags[] = "Rapid visits from same IP ({$recentSameIp} in last 60s)";
        $score += 25;
    } elseif ($recentSameIp >= 3) {
        $flags[] = "Frequent visits from same IP ({$recentSameIp} in last 60s)";
        $score += 10;
    }

    // ── 8. Same IP subnet flooding (same /24, different IPs, >10 visits) ──
    $ipParts = explode('.', $ip);
    if (count($ipParts) === 4) {
        $subnet = "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}";
        $subnetHits = 0;
        foreach ($existingVisits as $v) {
            if (str_starts_with($v['ip'] ?? '', $subnet . '.')) {
                $subnetHits++;
            }
        }
        if ($subnetHits >= 10) {
            $flags[] = "IP subnet cluster: {$subnet}.x ({$subnetHits} visits)";
            $score += 20;
        }
    }

    // ── 9. Suspicious UA fragments (automation tools not in bot list) ──
    $suspiciousFragments = [
        'selenium', 'webdriver', 'cypress', 'playwright', 'mechanize',
        'nightmare', 'casperjs', 'slimerjs', 'requestcatcher',
        'postman', 'insomnia', 'httpie', 'axios/', 'node-fetch',
        'undici', 'got/', 'superagent',
    ];
    foreach ($suspiciousFragments as $frag) {
        if (str_contains($uaLower, $frag)) {
            $flags[] = "Automation tool detected: {$frag}";
            $score += 35;
            break;
        }
    }

    // ── Clamp score ──
    $score = min(100, max(0, $score));

    // ── Determine type ──
    if ($score >= 50) {
        $type = 'suspicious';
    } else {
        $type = 'human';
    }

    return [$type, $score, $flags];
}

// ────────────────────────────────────────
// Main redirect logic
// ────────────────────────────────────────

$code = trim($_GET['c'] ?? '');

if (!$code) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Bad Request</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;background:#0e0e12;color:#e4e4e8;"><h1>400</h1><p>Missing short code.</p></body></html>';
    exit;
}

if (!file_exists(DATA_FILE)) show404();

$fp = fopen(DATA_FILE, 'c+');
flock($fp, LOCK_EX);

$content = stream_get_contents($fp);
$data = json_decode($content, true);

if (!is_array($data) || !isset($data[$code])) {
    flock($fp, LOCK_UN);
    fclose($fp);
    show404();
}

$data[$code]['visits'] = ($data[$code]['visits'] ?? 0) + 1;
$targetUrl = $data[$code]['url'];

fseek($fp, 0);
ftruncate($fp, 0);
fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// ── Collect visitor info ──
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Geolocation
$country = 'Unknown';
$countryCode = 'XX';
$city = '';
$isp = '';
$org = '';

if (!in_array($ip, ['127.0.0.1', '::1']) && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)) {
    $geoCtx = stream_context_create(['http' => ['timeout' => 2]]);
    $geoJson = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,isp,org", false, $geoCtx);
    if ($geoJson) {
        $geo = json_decode($geoJson, true);
        if (($geo['status'] ?? '') === 'success') {
            $country = $geo['country'] ?? 'Unknown';
            $countryCode = $geo['countryCode'] ?? 'XX';
            $city = $geo['city'] ?? '';
            $isp = $geo['isp'] ?? '';
            $org = $geo['org'] ?? '';
        }
    }
}

// ── Load existing visits for classification context ──
@mkdir(VISITS_DIR, 0755, true);
$visitFile = VISITS_DIR . "/{$code}.json";

$vfp = fopen($visitFile, 'c+');
flock($vfp, LOCK_EX);
$existingVisits = json_decode(stream_get_contents($vfp), true);
if (!is_array($existingVisits)) $existingVisits = [];

// ── Classify ──
[$type, $suspicionScore, $flags] = classifyVisitor($ua, $ip, $isp, $code, $existingVisits);

// ── Build visit entry ──
$visitEntry = [
    'timestamp'        => date('c'),
    'ip'               => $ip,
    'ua'               => $ua,
    'referer'          => $referer,
    'country'          => $country,
    'country_code'     => $countryCode,
    'city'             => $city,
    'isp'              => $isp,
    'org'              => $org,
    'type'             => $type,         // "bot", "suspicious", "human"
    'suspicion_score'  => $suspicionScore,
    'flags'            => $flags,
    // Keep legacy field for backward compat
    'is_bot'           => ($type === 'bot'),
];

$existingVisits[] = $visitEntry;

fseek($vfp, 0);
ftruncate($vfp, 0);
fwrite($vfp, json_encode($existingVisits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($vfp);
flock($vfp, LOCK_UN);
fclose($vfp);

header('Location: ' . $targetUrl, true, 302);
exit;

function show404(): void {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;background:#0e0e12;color:#e4e4e8;">
    <h1 style="font-size:4rem;margin-bottom:0.5rem;color:#6c5ce7;">404</h1>
    <p style="color:#8a8a9a;">This short link doesn\'t exist or has been deleted.</p>
    </body></html>';
    exit;
}
