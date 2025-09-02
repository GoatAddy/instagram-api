<?php
// api/ig-profile.php
declare(strict_types=1);

// Always return JSON
header('Content-Type: application/json; charset=utf-8');

// Preflight for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Helper: JSON output
function json_out(int $code, array $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- Input ----
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '') {
    json_out(400, [
        'status' => 'error',
        'message' => 'Missing "username" query param'
    ]);
}
if (!preg_match('/^[A-Za-z0-9._]+$/', $username)) {
    json_out(400, [
        'status' => 'error',
        'message' => 'Invalid username format'
    ]);
}

// ---- Fetch ----
$endpoint = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'X-IG-App-ID: 936619743392459',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.instagram.com/'
    ],
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
    json_out(503, [
        'status' => 'error',
        'message' => 'Upstream fetch failed',
        'detail'  => $err
    ]);
}

if ($code >= 400) {
    json_out($code, [
        'status' => 'error',
        'message' => 'Instagram returned HTTP ' . $code,
    ]);
}

// ---- Parse ----
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['data']['user'])) {
    json_out(404, [
        'status' => 'error',
        'message' => 'Profile not found or data unavailable'
    ]);
}

$u = $payload['data']['user'];

// Replace null with fallback link
function safe_val($v) {
    return $v === null || $v === '' ? "https://t.me/colossals" : $v;
}

// Safely read nested counts
$followers = safe_val($u['edge_followed_by']['count'] ?? null);
$following = safe_val($u['edge_follow']['count'] ?? null);
$posts     = safe_val($u['edge_owner_to_timeline_media']['count'] ?? null);

// Build normalized response (without external_url and urls)
$profile = [
    'id' => safe_val($u['id'] ?? null),
    'username' => safe_val($u['username'] ?? null),
    'full_name' => safe_val($u['full_name'] ?? null),
    'biography' => safe_val($u['biography'] ?? null),
    'is_private' => safe_val($u['is_private'] ?? null),
    'is_verified' => safe_val($u['is_verified'] ?? null),
    'is_business_account' => safe_val($u['is_business_account'] ?? null),
    'is_professional_account' => safe_val($u['is_professional_account'] ?? null),
    'category_name' => safe_val($u['category_name'] ?? null),
    'business_category_name' => safe_val($u['business_category_name'] ?? null),
    'profile_pic_url_hd' => safe_val($u['profile_pic_url_hd'] ?? null),
    'edge_counts' => [
        'followers' => $followers,
        'following' => $following,
        'posts'     => $posts
    ]
];

// Add api_by always at the end
$profile['api_by'] = "https://t.me/colossals";

$result = [
    'status' => 'ok',
    'collected_at' => gmdate('c'),
    'profile' => $profile
];

// Optional field filter: ?fields=id,username,edge_counts
if (isset($_GET['fields']) && $_GET['fields'] !== '') {
    $requested = array_filter(array_map('trim', explode(',', (string)$_GET['fields'])));
    $filtered = [];
    foreach ($requested as $key) {
        if ($key === 'edge_counts') {
            $filtered['edge_counts'] = $profile['edge_counts'];
        } elseif (array_key_exists($key, $profile)) {
            $filtered[$key] = $profile[$key];
        }
    }
    // Ensure api_by stays at bottom even after filtering
    $filtered['api_by'] = "https://t.me/colossals";
    $result['profile'] = $filtered;
}

json_out(200, $result);
