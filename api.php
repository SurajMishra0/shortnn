<?php
/**
 * ShortNN — API Backend
 * Handles create, list, and delete operations for short URLs.
 * Data is stored in data/urls.json with file locking.
 */

header('Content-Type: application/json');

define('DATA_FILE', __DIR__ . '/data/urls.json');

// ── Ensure data file exists ──
if (!file_exists(DATA_FILE)) {
    @mkdir(dirname(DATA_FILE), 0755, true);
    file_put_contents(DATA_FILE, '{}');
}

// ── Read data (with shared lock) ──
function readData(): array {
    $fp = fopen(DATA_FILE, 'r');
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// ── Write data (with exclusive lock) ──
function writeData(array $data): void {
    $fp = fopen(DATA_FILE, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Generate random code ──
function generateCode(int $length = 6): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

// ── Validate URL ──
function isValidUrl(string $url): bool {
    return (bool) filter_var($url, FILTER_VALIDATE_URL);
}

// ── Validate slug ──
function isValidSlug(string $slug): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug);
}

// ── JSON response ──
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ── Router ──
$action = $_GET['action'] ?? '';

switch ($action) {

    // ──────────────────────────────
    // CREATE
    // ──────────────────────────────
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['success' => false, 'error' => 'POST required'], 405);
        }

        $url = trim($_POST['url'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$url) {
            respond(['success' => false, 'error' => 'URL is required'], 400);
        }

        // Add protocol if missing
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        if (!isValidUrl($url)) {
            respond(['success' => false, 'error' => 'Invalid URL'], 400);
        }

        $data = readData();

        // Custom slug or auto-generate
        if ($slug) {
            if (!isValidSlug($slug)) {
                respond(['success' => false, 'error' => 'Slug can only contain letters, numbers, hyphens, and underscores (max 64 chars)'], 400);
            }
            if (isset($data[$slug])) {
                respond(['success' => false, 'error' => "Slug \"$slug\" is already taken"], 409);
            }
            $code = $slug;
        } else {
            // Generate unique code
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

        // Build summary
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
            'visits'  => array_reverse($visits), // newest first
        ]);
        break;

    default:
        respond(['success' => false, 'error' => 'Unknown action'], 400);
}
