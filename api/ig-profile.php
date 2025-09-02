<?php
// api/ig-profile.php
declare(strict_types=1);

// ---- Configuration ----
/**
 * Yahan apna Instagram ka poora cookie string paste karein.
 * Yeh zaroori hai jab public API rate-limit ho jaaye.
 * Example: 'sessionid=...; csrftoken=...; ds_user_id=...; ig_did=...'
 */
$sessionCookie = 'sessionid=77092081202%3AwEvEAwKyiMUsFs%3A20%3AAYdVy9ADzLky88EQZh0zZGAacud2L9Wpb5x413YK-Q; csrftoken=G8m03XzWMhx1Ji34aGQ6Tg; ds_user_id=77092081202; ig_did=26E4F7C8-BCA4-47AD-B905-917F2CF04C18';


// ---- Core Logic ----

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

// Helper: cURL executor
function execute_curl(string $endpoint, ?string $cookie = null): array {
    $ch = curl_init($endpoint);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'X-IG-App-ID: 936619743392459',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.instagram.com/'
    ];

    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($cookie) {
        $curl_opts[CURLOPT_COOKIE] = $cookie;
    }

    curl_setopt_array($ch, $curl_opts);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['raw' => $raw, 'code' => $code, 'error' => $err];
}

// ---- Input Validation ----
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '') {
    json_out(400, ['status' => 'error', 'message' => 'Missing "username" query parameter']);
}
if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $username)) {
    json_out(400, ['status' => 'error', 'message' => 'Invalid username format']);
}

// ---- Fetching Logic ----

// Step 1: Try public (anonymous) fetch first
$endpoint_web_profile = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);
$response = execute_curl($endpoint_web_profile);
$fetch_method = 'public_api';

// Step 2: If public fetch fails with a client error (like 401/429), fallback to authenticated fetch
if ($response['code'] >= 400 && !empty($sessionCookie)) {
    $response = execute_curl($endpoint_web_profile, $sessionCookie);
    $fetch_method = 'authenticated_api_fallback';
}

// Handle cURL-level errors
if ($response['raw'] === false) {
    json_out(503, ['status' => 'error', 'message' => 'Upstream fetch failed', 'detail' => $response['error']]);
}

// Handle HTTP errors from Instagram
if ($response['code'] >= 400) {
    json_out($response['code'], ['status' => 'error', 'message' => 'Instagram API returned an error', 'http_code' => $response['code']]);
}

// ---- Parsing Logic ----
$payload = json_decode($response['raw'], true);
if (!is_array($payload) || empty($payload['data']['user'])) {
    json_out(404, ['status' => 'error', 'message' => 'Profile not found or data is unavailable']);
}

$u = $payload['data']['user'];
$user_pk = $u['id'] ?? null;
$created_at_timestamp = null;

// Step 3: If we have the user ID (pk) and a session, fetch additional data like creation date
if ($user_pk && !empty($sessionCookie)) {
    $endpoint_user_info = 'https://i.instagram.com/api/v1/users/' . $user_pk . '/info/';
    $info_response = execute_curl($endpoint_user_info, $sessionCookie);

    if ($info_response['code'] === 200) {
        $info_payload = json_decode($info_response['raw'], true);
        // The pk_create timestamp is often available in this endpoint's user object.
        if (!empty($info_payload['user']['pk_create_date'])) {
             $created_at_timestamp = $info_payload['user']['pk_create_date'];
             $fetch_method .= '_with_creation_date';
        }
    }
}


// ---- Normalization & Output ----

$result = [
    'status' => 'ok',
    'collected_at' => gmdate('c'),
    'fetch_method' => $fetch_method,
    'profile' => [
        'id' => $u['id'] ?? null,
        'username' => $u['username'] ?? null,
        'full_name' => $u['full_name'] ?? null,
        'biography' => $u['biography'] ?? null,
        'external_url' => $u['external_url'] ?? null,
        'is_private' => $u['is_private'] ?? null,
        'is_verified' => $u['is_verified'] ?? null,
        'is_business_account' => $u['is_business_account'] ?? null,
        'is_professional_account' => $u['is_professional_account'] ?? null,
        'category_name' => $u['category_name'] ?? null,
        'business_category_name' => $u['business_category_name'] ?? null,
        'profile_pic_url' => $u['profile_pic_url'] ?? null,
        'profile_pic_url_hd' => $u['profile_pic_url_hd'] ?? null,
        'follower_count' => $u['edge_followed_by']['count'] ?? null,
        'following_count' => $u['edge_follow']['count'] ?? null,
        'post_count' => $u['edge_owner_to_timeline_media']['count'] ?? null,
        'created_at_unix' => $created_at_timestamp,
        'created_at_utc' => $created_at_timestamp ? gmdate('c', $created_at_timestamp) : null,
    ]
];

// Optional field filtering
if (isset($_GET['fields']) && $_GET['fields'] !== '') {
    $requested = array_filter(array_map('trim', explode(',', (string)$_GET['fields'])));
    $filtered = [];
    foreach ($requested as $key) {
        if (array_key_exists($key, $result['profile'])) {
            $filtered[$key] = $result['profile'][$key];
        }
    }
    $result['profile'] = $filtered;
}

json_out(200, $result);
