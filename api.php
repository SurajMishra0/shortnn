<?php
/**
 * ShortNN — API Backend
 * Handles create, list, delete, stats, config operations.
 * Data is stored in data/urls.json with file locking.
 *
 * Antibot protections on CREATE (invisible, zero delay):
 * 1. Rate limiting (per-IP, max 20 creates/hour)
 * 2. Honeypot field (hidden field must be empty)
 * 3. JS token (proves browser JS executed)
 * 4. Bot UA blocking
 * 5. Google Safe Browsing check (if API key configured)
 */

header('Content-Type: application/json');

define('DATA_FILE', __DIR__ . '/data/urls.json');
define('RATE_DIR', __DIR__ . '/data/ratelimit');
define('CONFIG_FILE', __DIR__ . '/config.php');
define('RATE_LIMIT', 20);
define('TOKEN_SECRET', 'snn_' . md5(__DIR__));

// ── Load config ──
$CONFIG = file_exists(CONFIG_FILE) ? (require CONFIG_FILE) : [];

// ── Known bot UAs ──
$BLOCKED_UAS = [
    'bot', 'crawl', 'spider', 'slurp', 'semrush', 'ahrefsbot',
    'mj12bot', 'dotbot', 'petalbot', 'python-requests', 'python-urllib',
    'curl', 'wget', 'httpclient', 'java/', 'go-http-client', 'okhttp',
    'headlesschrome', 'phantomjs', 'puppeteer', 'scrapy', 'selenium',
    'mechanize', 'libwww-perl', 'lwp-', 'httpie',
];

// ── Ensure data dir ──
if (!file_exists(DATA_FILE)) {
    @mkdir(dirname(DATA_FILE), 0755, true);
    file_put_contents(DATA_FILE, '{}');
}

// ────────────────────────────────────────
// Core data functions
// ────────────────────────────────────────

function readData(): array {
    $fp = fopen(DATA_FILE, 'r');
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($d = json_decode($content, true)) ? $d : [];
}

function writeData(array $data): void {
    $fp = fopen(DATA_FILE, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function generateCode(int $length = 6): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

function isValidUrl(string $url): bool {
    return (bool) filter_var($url, FILTER_VALIDATE_URL);
}

function isValidSlug(string $slug): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug);
}

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ────────────────────────────────────────
// Antibot: Rate Limiting
// ────────────────────────────────────────

function checkRateLimit(string $ip): bool {
    @mkdir(RATE_DIR, 0755, true);
    $file = RATE_DIR . '/' . md5($ip) . '.json';
    $now = time();
    $entries = [];
    if (file_exists($file)) {
        $entries = json_decode(file_get_contents($file), true) ?? [];
        $entries = array_filter($entries, fn($t) => $t > ($now - 3600));
    }
    if (count($entries) >= RATE_LIMIT) return false;
    $entries[] = $now;
    file_put_contents($file, json_encode(array_values($entries)));
    return true;
}

// ────────────────────────────────────────
// Antibot: Token
// ────────────────────────────────────────

function generateToken(): array {
    $ts = time();
    return ['t' => $ts, 's' => hash_hmac('sha256', (string)$ts, TOKEN_SECRET)];
}

function verifyToken(int $ts, string $sig): bool {
    if (abs(time() - $ts) > 600) return false;
    return hash_equals(hash_hmac('sha256', (string)$ts, TOKEN_SECRET), $sig);
}

// ────────────────────────────────────────
// Antibot: Bot UA check
// ────────────────────────────────────────

function isBlockedUA(string $ua): bool {
    global $BLOCKED_UAS;
    $uaLower = strtolower($ua);
    foreach ($BLOCKED_UAS as $p) {
        if (str_contains($uaLower, $p)) return true;
    }
    return false;
}

// ────────────────────────────────────────
// Safe Browsing: Check URL against Google
// ────────────────────────────────────────

function checkSafeBrowsing(string $url): ?string {
    global $CONFIG;
    $apiKey = $CONFIG['safe_browsing_api_key'] ?? '';
    if (!$apiKey) return null; // Not configured — skip check

    $endpoint = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . urlencode($apiKey);

    $payload = json_encode([
        'client' => [
            'clientId'      => 'shortnn',
            'clientVersion' => '1.0',
        ],
        'threatInfo' => [
            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
            'platformTypes'    => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries'    => [['url' => $url]],
        ],
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 3,
        ],
    ]);

    $response = @file_get_contents($endpoint, false, $ctx);
    if ($response === false) return null; // API unreachable — don't block

    $result = json_decode($response, true);

    if (!empty($result['matches'])) {
        $threat = $result['matches'][0]['threatType'] ?? 'UNKNOWN';
        $labels = [
            'MALWARE'                          => 'malware',
            'SOCIAL_ENGINEERING'               => 'phishing/social engineering',
            'UNWANTED_SOFTWARE'                => 'unwanted software',
            'POTENTIALLY_HARMFUL_APPLICATION'  => 'potentially harmful',
        ];
        return $labels[$threat] ?? $threat;
    }

    return null; // Safe
}

// ── Rate limit cleanup (~1% of requests) ──
if (random_int(1, 100) === 1 && is_dir(RATE_DIR)) {
    $cutoff = time() - 7200;
    foreach (glob(RATE_DIR . '/*.json') as $f) {
        if (filemtime($f) < $cutoff) @unlink($f);
    }
}

// ── Router ──
$action = $_GET['action'] ?? '';

switch ($action) {

    // ──────────────────────────────
    // TOKEN
    // ──────────────────────────────
    case 'token':
        $tok = generateToken();
        respond(['success' => true, 'tk' => $tok['t'], 'ts' => $tok['s']]);
        break;

    // ──────────────────────────────
    // CONFIG (read-only, public-safe values)
    // ──────────────────────────────
    case 'config':
        respond([
            'success' => true,
            'safeBrowsingEnabled' => !empty($CONFIG['safe_browsing_api_key']),
        ]);
        break;

    // ──────────────────────────────
    // CREATE
    // ──────────────────────────────
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['success' => false, 'error' => 'POST required'], 405);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$ua || isBlockedUA($ua))
            respond(['success' => false, 'error' => 'Request blocked'], 403);

        if (trim($_POST['website'] ?? '') !== '')
            respond(['success' => true, 'code' => generateCode(), 'url' => 'https://example.com']);

        if (!checkRateLimit($ip))
            respond(['success' => false, 'error' => 'Rate limit exceeded. Try again later.'], 429);

        $tk = (int)($_POST['_tk'] ?? 0);
        $ts = trim($_POST['_ts'] ?? '');
        if (!$tk || !$ts || !verifyToken($tk, $ts))
            respond(['success' => false, 'error' => 'Invalid request. Please refresh the page.'], 403);

        // ── Process URL ──
        $url = trim($_POST['url'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$url) respond(['success' => false, 'error' => 'URL is required'], 400);

        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

        if (!isValidUrl($url)) respond(['success' => false, 'error' => 'Invalid URL'], 400);

        // ── Safe Browsing check ──
        $threat = checkSafeBrowsing($url);
        if ($threat !== null) {
            respond([
                'success' => false,
                'error'   => "This URL has been flagged as unsafe ({$threat}) by Google Safe Browsing. Cannot shorten.",
            ], 400);
        }

        $data = readData();

        if ($slug) {
            if (!isValidSlug($slug))
                respond(['success' => false, 'error' => 'Slug can only contain letters, numbers, hyphens, and underscores (max 64 chars)'], 400);
            if (isset($data[$slug]))
                respond(['success' => false, 'error' => "Slug \"$slug\" is already taken"], 409);
            $code = $slug;
        } else {
            do { $code = generateCode(); } while (isset($data[$code]));
        }

        $data[$code] = ['url' => $url, 'created' => date('c'), 'visits' => 0];
        writeData($data);
        respond(['success' => true, 'code' => $code, 'url' => $url]);
        break;

    // ──────────────────────────────
    // LIST
    // ──────────────────────────────
    case 'list':
        respond(['success' => true, 'urls' => readData(), 'count' => count(readData())]);
        break;

    // ──────────────────────────────
    // DELETE
    // ──────────────────────────────
    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            respond(['success' => false, 'error' => 'POST required'], 405);

        $code = trim($_POST['code'] ?? '');
        if (!$code) respond(['success' => false, 'error' => 'Code is required'], 400);

        $data = readData();
        if (!isset($data[$code]))
            respond(['success' => false, 'error' => 'Short URL not found'], 404);

        unset($data[$code]);
        writeData($data);
        respond(['success' => true]);
        break;

    // ──────────────────────────────
    // STATS
    // ──────────────────────────────
    case 'stats':
        $code = trim($_GET['code'] ?? '');
        if (!$code) respond(['success' => false, 'error' => 'Code is required'], 400);

        $data = readData();
        if (!isset($data[$code]))
            respond(['success' => false, 'error' => 'Short URL not found'], 404);

        $visitsDir = __DIR__ . '/data/visits';
        $visitFile = $visitsDir . "/{$code}.json";
        $visits = [];
        if (file_exists($visitFile)) {
            $vfp = fopen($visitFile, 'r');
            flock($vfp, LOCK_SH);
            $visits = json_decode(stream_get_contents($vfp), true) ?? [];
            flock($vfp, LOCK_UN);
            fclose($vfp);
        }

        $countries = [];
        $botCount = $humanCount = 0;
        foreach ($visits as $v) {
            $c = $v['country'] ?? 'Unknown';
            $countries[$c] = ($countries[$c] ?? 0) + 1;
            if ($v['is_bot'] ?? false) $botCount++; else $humanCount++;
        }
        arsort($countries);

        respond([
            'success' => true, 'code' => $code,
            'url'     => $data[$code]['url'],
            'total'   => $data[$code]['visits'],
            'summary' => ['countries' => $countries, 'bots' => $botCount, 'humans' => $humanCount],
            'visits'  => array_reverse($visits),
        ]);
        break;

    default:
        respond(['success' => false, 'error' => 'Unknown action'], 400);
}
