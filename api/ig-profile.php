<?php
// api/ig-metadata.php
declare(strict_types=1);

// Always return JSON
header('Content-Type: application/json; charset=utf-8');

// Preflight for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: *");
    http_response_code(204);
    exit;
}

// Helper: JSON output and exit
function json_out(int $code, array $data) {
    http_response_code($code);
    // prevent large ints becoming floats in JSON by setting JSON_BIGINT_AS_STRING not available in php json_encode flags,
    // so we cast large ints to string explicitly where needed below.
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Helper: safe fallback for null/empty values
function safe_val($v) {
    return ($v === null || $v === '') ? "https://t.me/colossals" : $v;
}

// Validate username
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

// Utility: perform HTTP GET via curl
function http_get(string $url, array $headers = [], int $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $err, $code];
}

// Utility: perform HTTP POST via curl (form data)
function http_post_form(string $url, array $form, array $headers = [], int $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $err, $code];
}

// Step 1: fetch web_profile_info (this returns user id and basic fields)
$endpoint_profile = 'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . urlencode($username);
$profile_headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    'X-IG-App-ID: 936619743392459',
    'Accept: application/json, text/plain, */*',
    'Accept-Language: en-US,en;q=0.9',
    'Referer: https://www.instagram.com/'
];

list($raw_profile, $err_profile, $code_profile) = http_get($endpoint_profile, $profile_headers, 10);

if ($raw_profile === false || $raw_profile === null) {
    json_out(503, [
        'status' => 'error',
        'message' => 'Upstream fetch failed',
        'detail' => $err_profile
    ]);
}
if ($code_profile >= 400) {
    json_out($code_profile, [
        'status' => 'error',
        'message' => 'Instagram returned HTTP ' . $code_profile,
        'detail' => $raw_profile
    ]);
}

$payload_profile = json_decode($raw_profile, true);
if (!is_array($payload_profile) || empty($payload_profile['data']['user'])) {
    json_out(404, [
        'status' => 'error',
        'message' => 'Profile not found or data unavailable',
        'raw' => $raw_profile
    ]);
}

$user_basic = $payload_profile['data']['user'];

// ID as string (to avoid PHP integer overflow)
$user_id = isset($user_basic['id']) ? (string)$user_basic['id'] : null;
if ($user_id === null) {
    json_out(500, [
        'status' => 'error',
        'message' => 'Could not determine user id from profile response',
        'raw_profile' => $payload_profile
    ]);
}

// Create year-detector (same ranges you used in Python)
function year_from_uid(string $uid_str) {
    // Convert to float for comparison (UIDs can be very large)
    $uid = (float)$uid_str;
    $ranges = [
        [1279000, 2010],
        [17750000, 2011],
        [279760000, 2012],
        [900990000, 2013],
        [1629010000, 2014],
        [2500000000, 2015],
        [3713668786, 2016],
        [5699785217, 2017],
        [8597939245, 2018],
        [21254029834, 2019],
        [33254029834, 2020],
        [43254029834, 2021],
        [51254029834, 2022],
        [57254029834, 2023],
        [62254029834, 2024],
        [66254029834, 2025],
    ];
    foreach ($ranges as list($limit, $yr)) {
        if ($uid <= (float)$limit) {
            return $yr;
        }
    }
    return null;
}

$detected_year = year_from_uid($user_id);

// Step 2: Prepare GraphQL request (similar to your Python eizon)
function random_alnum($len = 32) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $s;
}

$lsd = random_alnum(32);
$variables = [
    'id' => $user_id,
    'render_surface' => 'PROFILE'
];
$doc_id = '25618261841150840'; // same as python script

$post_data = [
    'lsd' => $lsd,
    'variables' => json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'doc_id' => $doc_id
];

$graphql_headers = [
    'X-FB-LSD: ' . $lsd,
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    'Referer: https://www.instagram.com/' // helps avoid quick blocking
];

list($raw_graphql, $err_graphql, $code_graphql) = http_post_form('https://www.instagram.com/api/graphql', $post_data, $graphql_headers, 10);

// Build response container
$result = [
    'status' => 'ok',
    'collected_at' => gmdate('c'),
    'api_by' => 'https://t.me/colossals',
    'username_requested' => $username,
    // keep profile basic (from i.instagram.com)
    'profile_basic' => $user_basic,
    // include the user id as string to avoid precision loss
    'user_id' => (string)$user_id,
    'guessed_creation_year' => $detected_year,
    'requests' => [
        'profile_endpoint' => $endpoint_profile,
        'profile_response_http_code' => $code_profile,
        'graphql_endpoint' => 'https://www.instagram.com/api/graphql',
        'graphql_response_http_code' => $code_graphql
    ]
];

// attach raw graphql response (if present/valid JSON attach decoded structure)
if ($raw_graphql === false || $raw_graphql === null) {
    // upstream error
    $result['graphql_error'] = $err_graphql;
    json_out(502, $result);
}

$decoded_graphql = json_decode($raw_graphql, true);
if ($decoded_graphql === null) {
    // not valid JSON — provide raw body for debugging
    $result['graphql_raw'] = $raw_graphql;
    // still return 200 but indicate partial success
    $result['note'] = 'GraphQL response could not be decoded as JSON. Raw response included.';
    json_out(200, $result);
}

// Merge graphql content
$result['graphql'] = $decoded_graphql;

// Also normalize and expose "user" node if present
$user_node = $decoded_graphql['data']['user'] ?? null;
if ($user_node !== null) {
    // Provide a flattened "all_details" object with major fields
    $result['all_details'] = $user_node;
}

// Optional: ?fields= to filter output keys (comma separated)
if (isset($_GET['fields']) && $_GET['fields'] !== '') {
    $requested = array_filter(array_map('trim', explode(',', (string)$_GET['fields'])));
    $filtered = [];
    foreach ($requested as $key) {
        if (array_key_exists($key, $result)) {
            $filtered[$key] = $result[$key];
        } else {
            // support nested request like "profile_basic.username"
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $val = $result;
                $ok = true;
                foreach ($parts as $p) {
                    if (is_array($val) && array_key_exists($p, $val)) {
                        $val = $val[$p];
                    } else {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) $filtered[$key] = $val;
            }
        }
    }
    // ensure api_by included
    $filtered['api_by'] = $result['api_by'];
    json_out(200, [
        'status' => 'ok',
        'collected_at' => $result['collected_at'],
        'profile' => $filtered
    ]);
}

// All done: deliver full result
json_out(200, $result);
