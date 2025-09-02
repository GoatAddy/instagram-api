<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Instagram session cookies (replace with fresh ones if expired)
$sessionCookies = [
    "sessionid=77092081202%3AwEvEAwKyiMUsFs%3A20%3AAYdVy9ADzLky88EQZh0zZGAacud2L9Wpb5x413YK-Q",
    "csrftoken=G8m03XzWMhx1Ji34aGQ6Tg",
    "ds_user_id=77092081202",
    "ig_did=26E4F7C8-BCA4-47AD-B905-917F2CF04C18"
];
$cookieString = implode("; ", $sessionCookies);

// Allow CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Utility: JSON response
function json_out(int $code, array $data): void {
    http_response_code($code);
    if ($code === 200) {
        $data['api_by'] = '@colossals';
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Utility: CURL request
function execute_curl(string $endpoint, string $cookie): array {
    $headers = [
        'User-Agent: Instagram 255.0.0.19.109 (iPhone13,4; iOS 16_0; en_US; en_US; scale=3.00; 1170x2532; 393213155)',
        'X-IG-App-ID: 936619743392459',
        'X-CSRFToken: G8m03XzWMhx1Ji34aGQ6Tg',
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.instagram.com/',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIE         => $cookie,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['raw' => $raw, 'code' => $code, 'error' => $err];
}

// Input validation
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '') {
    json_out(400, ['status' => 'error', 'message' => 'Missing parameter: "username".']);
}
if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $username)) {
    json_out(400, ['status' => 'error', 'message' => 'Invalid username format.']);
}

// Step 1: Get basic profile info
$endpoint_profile = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);
$response = execute_curl($endpoint_profile, $cookieString);

if ($response['raw'] === false) {
    json_out(503, ['status' => 'error', 'message' => 'Failed to reach Instagram.', 'detail' => $response['error']]);
}
if ($response['code'] >= 400) {
    json_out($response['code'], [
        'status' => 'error',
        'message' => 'Instagram API rejected the request. Your session cookie may be invalid or expired.',
        'http_code' => $response['code']
    ]);
}

$payload = json_decode($response['raw'], true);
if (empty($payload['data']['user'])) {
    json_out(404, ['status' => 'error', 'message' => 'Profile not found.']);
}

$u = $payload['data']['user'];
$user_pk = $u['id'] ?? null;
$created_at_timestamp = null;

// Step 2: Try to fetch more details (including account creation approx)
if ($user_pk) {
    $endpoint_user_info = 'https://i.instagram.com/api/v1/users/' . $user_pk . '/info/';
    $info_response = execute_curl($endpoint_user_info, $cookieString);

    if ($info_response['code'] === 200) {
        $info_payload = json_decode($info_response['raw'], true);
        if (!empty($info_payload['user']['pk_id'])) {
            // ⚠️ Instagram no longer exposes exact creation date.
            // Use first post timestamp as fallback.
            if (!empty($u['edge_owner_to_timeline_media']['edges'])) {
                $posts = $u['edge_owner_to_timeline_media']['edges'];
                $oldest = end($posts);
                if (!empty($oldest['node']['taken_at_timestamp'])) {
                    $created_at_timestamp = (int)$oldest['node']['taken_at_timestamp'];
                }
            }
        }
    }
}

// Step 3: Final JSON
$result = [
    'status' => 'ok',
    'collected_at' => gmdate('c'),
    'profile' => [
        'id' => $u['id'] ?? null,
        'username' => $u['username'] ?? null,
        'full_name' => $u['full_name'] ?? null,
        'biography' => $u['biography'] ?? null,
        'external_url' => $u['external_url'] ?? null,
        'is_private' => (bool)($u['is_private'] ?? null),
        'is_verified' => (bool)($u['is_verified'] ?? null),
        'is_business_account' => (bool)($u['is_business_account'] ?? null),
        'profile_pic_url_hd' => $u['profile_pic_url_hd'] ?? null,
        'follower_count' => (int)($u['edge_followed_by']['count'] ?? 0),
        'following_count' => (int)($u['edge_follow']['count'] ?? 0),
        'post_count' => (int)($u['edge_owner_to_timeline_media']['count'] ?? 0),
        'created_at_unix' => $created_at_timestamp,
        'created_at_utc' => $created_at_timestamp ? gmdate('c', $created_at_timestamp) : null,
    ]
];
json_out(200, $result);
