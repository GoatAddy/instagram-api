<?php
declare(strict_types=1);

$sessionCookie = 'sessionid=77092081202%3AwEvEAwKyiMUsFs%3A20%3AAYdVy9ADzLky88EQZh0zZGAacud2L9Wpb5x413YK-Q; csrftoken=G8m03XzWMhx1Ji34aGQ6Tg; ds_user_id=77092081202; ig_did=26E4F7C8-BCA4-47AD-B905-917F2CF04C18';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out(int $code, array $data) {
    http_response_code($code);
    if ($code === 200) {
        $data['api_by'] = '@colossals';
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function execute_curl(string $endpoint, string $cookie): array {
    $ch = curl_init($endpoint);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'X-IG-App-ID: 936619743392459',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
    ];

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

if (empty($sessionCookie)) {
    json_out(500, ['status' => 'error', 'message' => 'Server Error: Instagram session cookie configure nahi kiya gaya hai.']);
}

$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '') {
    json_out(400, ['status' => 'error', 'message' => 'Error: "username" parameter missing.']);
}
if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $username)) {
    json_out(400, ['status' => 'error', 'message' => 'Error: Invalid username format.']);
}

$endpoint_web_profile = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);
$response = execute_curl($endpoint_web_profile, $sessionCookie);

if ($response['raw'] === false) {
    json_out(503, ['status' => 'error', 'message' => 'Upstream fetch failed', 'detail' => $response['error']]);
}
if ($response['code'] >= 400) {
    $message = 'Instagram API Error. Ho sakta hai aapka session cookie invalid ya expire ho gaya ho.';
    json_out($response['code'], ['status' => 'error', 'message' => $message, 'http_code' => $response['code']]);
}

$payload = json_decode($response['raw'], true);
if (empty($payload['data']['user'])) {
    json_out(404, ['status' => 'error', 'message' => 'Profile not found.']);
}

$u = $payload['data']['user'];
$user_pk = $u['id'] ?? null;
$created_at_timestamp = null;

if ($user_pk) {
    $endpoint_user_info = 'https://i.instagram.com/api/v1/users/' . $user_pk . '/info/';
    $info_response = execute_curl($endpoint_user_info, $sessionCookie);

    if ($info_response['code'] === 200) {
        $info_payload = json_decode($info_response['raw'], true);
        if (!empty($info_payload['user']['pk_create_date'])) {
             $created_at_timestamp = (int)$info_payload['user']['pk_create_date'];
        }
    }
}

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
