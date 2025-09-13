<?php
// api/ig-metadata.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Preflight for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: *");
    http_response_code(204);
    exit;
}

// Helper: JSON output
function json_out(int $code, array $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Safe fallback
function safe_val($v) {
    return ($v === null || $v === '') ? "https://t.me/colossals" : $v;
}

// Validate input
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '' || !preg_match('/^[A-Za-z0-9._]+$/', $username)) {
    json_out(400, ['status' => 'error', 'message' => 'Invalid or missing username']);
}

// Curl GET
function http_get(string $url, array $headers = [], int $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $err, $code];
}

// Guess creation year
function year_from_uid(string $uid_str) {
    $uid = (float)$uid_str;
    $ranges = [
        [1279000, 2010],[17750000, 2011],[279760000, 2012],
        [900990000, 2013],[1629010000, 2014],[2500000000, 2015],
        [3713668786, 2016],[5699785217, 2017],[8597939245, 2018],
        [21254029834, 2019],[33254029834, 2020],[43254029834, 2021],
        [51254029834, 2022],[57254029834, 2023],[62254029834, 2024],
        [66254029834, 2025],
    ];
    foreach ($ranges as [$limit, $yr]) {
        if ($uid <= $limit) return $yr;
    }
    return null;
}

// Step 1: fetch profile info
$endpoint = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    'X-IG-App-ID: 936619743392459',
    'Accept: application/json',
    'Referer: https://www.instagram.com/'
];

list($raw, $err, $code) = http_get($endpoint, $headers, 10);
if ($raw === false || $code >= 400) {
    json_out(503, ['status' => 'error', 'message' => 'Instagram fetch failed', 'detail' => $err ?: $raw]);
}

$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['data']['user'])) {
    json_out(404, ['status' => 'error', 'message' => 'Profile not found']);
}

$u = $payload['data']['user'];
$uid = (string)($u['id'] ?? '');
$year = year_from_uid($uid);

// Build clean profile JSON
$profile = [
    'id' => safe_val($uid),
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
    'external_url' => safe_val($u['external_url'] ?? null),
    'followers' => safe_val($u['edge_followed_by']['count'] ?? null),
    'following' => safe_val($u['edge_follow']['count'] ?? null),
    'posts' => safe_val($u['edge_owner_to_timeline_media']['count'] ?? null),
    'account_creation_year' => safe_val($year)
];
$profile['api_by'] = "https://t.me/colossals";

$result = [
    'status' => 'ok',
    'collected_at' => gmdate('c'),
    'profile' => $profile
];

json_out(200, $result);
