<?php
/**
 * ShortNN — API Backend
 * Handles create, list, delete, stats operations for short URLs.
 * Data is stored in data/urls.json with file locking.
 *
 * Antibot protections on the CREATE endpoint:
 * 1. Rate limiting (per-IP, max 15 creates/hour)
 * 2. Honeypot field (hidden field must be empty)
 * 3. JS proof-of-work challenge (SHA-256 hash verification)
 * 4. Time gate (reject submissions < 2s after token issued)
 * 5. Bot UA blocking (reject known bot user agents)
 */

header('Content-Type: application/json');

define('DATA_FILE', __DIR__ . '/data/urls.json');
define('RATE_DIR', __DIR__ . '/data/ratelimit');
define('RATE_LIMIT', 15);        // Max URL creates per IP per hour
define('TIME_GATE_SEC', 2);      // Minimum seconds before submission allowed
define('CHALLENGE_SECRET', 'shortnn_' . (__DIR__));  // Server-side secret for challenge

// ── Known bot UAs (block on create) ──
$BLOCKED_UAS = [
    'bot', 'crawl', 'spider', 'slurp', 'semrush', 'ahrefsbot',
    'mj12bot', 'dotbot', 'petalbot', 'python-requests', 'python-urllib',
    'curl', 'wget', 'httpclient', 'java/', 'go-http-client', 'okhttp',
    'headlesschrome', 'phantomjs', 'puppeteer', 'scrapy', 'selenium',
    'mechanize', 'libwww-perl', 'lwp-', 'httpie',
];

// ── Ensure data dir exists ──
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
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
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
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
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
// Antibot: Rate Limiting (file-based)
// ────────────────────────────────────────

function checkRateLimit(string $ip): bool {
    @mkdir(RATE_DIR, 0755, true);
    $file = RATE_DIR . '/' . md5($ip) . '.json';

    $now = time();
    $windowStart = $now - 3600; // 1-hour window

    $entries = [];
    if (file_exists($file)) {
        $entries = json_decode(file_get_contents($file), true) ?? [];
        // Prune old entries
        $entries = array_filter($entries, fn($t) => $t > $windowStart);
    }

    if (count($entries) >= RATE_LIMIT) {
        return false; // Rate limited
    }

    $entries[] = $now;
    file_put_contents($file, json_encode(array_values($entries)));
    return true;
}

// ────────────────────────────────────────
// Antibot: Challenge Token
// ────────────────────────────────────────

function generateChallenge(): array {
    $timestamp = time();
    $nonce = bin2hex(random_bytes(8));
    // The challenge: client must find a string X such that
    // SHA256(nonce + X) starts with "00" (2 hex zeros = easy, ~1/256 attempts)
    $token = hash_hmac('sha256', "$nonce:$timestamp", CHALLENGE_SECRET);
    return [
        'nonce'     => $nonce,
        'timestamp' => $timestamp,
        'token'     => $token,
        'difficulty' => 2, // number of leading hex zeros required
    ];
}

function verifyChallenge(string $nonce, int $timestamp, string $token, string $solution, int $difficulty = 2): bool {
    // 1. Verify token authenticity (not forged)
    $expectedToken = hash_hmac('sha256', "$nonce:$timestamp", CHALLENGE_SECRET);
    if (!hash_equals($expectedToken, $token)) {
        return false;
    }

    // 2. Check time gate
    $elapsed = time() - $timestamp;
    if ($elapsed < TIME_GATE_SEC) {
        return false; // Too fast
    }

    // 3. Check token not too old (5 minutes max)
    if ($elapsed > 300) {
        return false;
    }

    // 4. Verify proof-of-work: SHA256(nonce + solution) must start with N zeros
    $hash = hash('sha256', $nonce . $solution);
    $prefix = str_repeat('0', $difficulty);
    return str_starts_with($hash, $prefix);
}

// ────────────────────────────────────────
// Antibot: Bot UA check
// ────────────────────────────────────────

function isBlockedUA(string $ua): bool {
    global $BLOCKED_UAS;
    $uaLower = strtolower($ua);
    foreach ($BLOCKED_UAS as $pattern) {
        if (str_contains($uaLower, $pattern)) {
            return true;
        }
    }
    return false;
}

// ────────────────────────────────────────
// Antibot: Cleanup stale rate limit files
// ────────────────────────────────────────

function cleanupRateLimits(): void {
    if (!is_dir(RATE_DIR)) return;
    $cutoff = time() - 7200; // 2 hours
    foreach (glob(RATE_DIR . '/*.json') as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

// Run cleanup ~1% of requests
if (random_int(1, 100) === 1) {
    cleanupRateLimits();
}

// ── Router ──
$action = $_GET['action'] ?? '';

switch ($action) {

    // ──────────────────────────────
    // CHALLENGE — client requests a PoW challenge before creating
    // ──────────────────────────────
    case 'challenge':
        $challenge = generateChallenge();
        respond(['success' => true, 'challenge' => $challenge]);
        break;

    // ──────────────────────────────
    // CREATE (protected)
    // ──────────────────────────────
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['success' => false, 'error' => 'POST required'], 405);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // ── Protection 1: Block bot UAs ──
        if (!$ua || isBlockedUA($ua)) {
            respond(['success' => false, 'error' => 'Request blocked'], 403);
        }

        // ── Protection 2: Honeypot ──
        $honeypot = trim($_POST['website'] ?? '');
        if ($honeypot !== '') {
            // Bots fill hidden fields — silently reject with fake success
            respond(['success' => true, 'code' => generateCode(), 'url' => 'https://example.com']);
        }

        // ── Protection 3: Rate limiting ──
        if (!checkRateLimit($ip)) {
            respond(['success' => false, 'error' => 'Rate limit exceeded. Try again later.'], 429);
        }

        // ── Protection 4 & 5: JS challenge verification ──
        $chalNonce     = trim($_POST['_cn'] ?? '');
        $chalTimestamp  = (int)($_POST['_ct'] ?? 0);
        $chalToken     = trim($_POST['_ck'] ?? '');
        $chalSolution  = trim($_POST['_cs'] ?? '');

        if (!$chalNonce || !$chalTimestamp || !$chalToken || !$chalSolution) {
            respond(['success' => false, 'error' => 'Security challenge required'], 403);
        }

        if (!verifyChallenge($chalNonce, $chalTimestamp, $chalToken, $chalSolution)) {
            respond(['success' => false, 'error' => 'Security challenge failed. Please try again.'], 403);
        }

        // ── All protections passed — process URL creation ──
        $url = trim($_POST['url'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$url) {
            respond(['success' => false, 'error' => 'URL is required'], 400);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        if (!isValidUrl($url)) {
            respond(['success' => false, 'error' => 'Invalid URL'], 400);
        }

        $data = readData();

        if ($slug) {
            if (!isValidSlug($slug)) {
                respond(['success' => false, 'error' => 'Slug can only contain letters, numbers, hyphens, and underscores (max 64 chars)'], 400);
            }
            if (isset($data[$slug])) {
                respond(['success' => false, 'error' => "Slug \"$slug\" is already taken"], 409);
            }
            $code = $slug;
        } else {
            do {
                $code = generateCode();
            } while (isset($data[$code]));
        }

        $data[$code] = [
            'url'     => $url,
            'created' => date('c'),
            'visits'  => 0,
        ];

        writeData($data);

        respond(['success' => true, 'code' => $code, 'url' => $url]);
        break;

    // ──────────────────────────────
    // LIST
    // ──────────────────────────────
    case 'list':
        $data = readData();
        respond(['success' => true, 'urls' => $data, 'count' => count($data)]);
        break;

    // ──────────────────────────────
    // DELETE
    // ──────────────────────────────
    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['success' => false, 'error' => 'POST required'], 405);
        }

        $code = trim($_POST['code'] ?? '');
        if (!$code) {
            respond(['success' => false, 'error' => 'Code is required'], 400);
        }

        $data = readData();
        if (!isset($data[$code])) {
            respond(['success' => false, 'error' => 'Short URL not found'], 404);
        }

        unset($data[$code]);
        writeData($data);

        respond(['success' => true]);
        break;

    // ──────────────────────────────
    // TRACK (internal — called by r.php)
    // ──────────────────────────────
    case 'track':
        $code = trim($_GET['code'] ?? '');
        if (!$code) {
            respond(['success' => false, 'error' => 'Code is required'], 400);
        }

        $data = readData();
        if (!isset($data[$code])) {
            respond(['success' => false, 'error' => 'Not found'], 404);
        }

        $data[$code]['visits'] = ($data[$code]['visits'] ?? 0) + 1;
        writeData($data);

        respond(['success' => true, 'url' => $data[$code]['url']]);
        break;

    // ──────────────────────────────
    // STATS — detailed visitor analytics
    // ──────────────────────────────
    case 'stats':
        $code = trim($_GET['code'] ?? '');
        if (!$code) {
            respond(['success' => false, 'error' => 'Code is required'], 400);
        }

        $data = readData();
        if (!isset($data[$code])) {
            respond(['success' => false, 'error' => 'Short URL not found'], 404);
        }

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
        $botCount = 0;
        $humanCount = 0;
        foreach ($visits as $v) {
            $c = $v['country'] ?? 'Unknown';
            $countries[$c] = ($countries[$c] ?? 0) + 1;
            if ($v['is_bot'] ?? false) {
                $botCount++;
            } else {
                $humanCount++;
            }
        }
        arsort($countries);

        respond([
            'success' => true,
            'code'    => $code,
            'url'     => $data[$code]['url'],
            'total'   => $data[$code]['visits'],
            'summary' => [
                'countries' => $countries,
                'bots'      => $botCount,
                'humans'    => $humanCount,
            ],
            'visits'  => array_reverse($visits),
        ]);
        break;

    default:
        respond(['success' => false, 'error' => 'Unknown action'], 400);
}
