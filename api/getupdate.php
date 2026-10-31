<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/api_log.php';
apiRegisterRuntimeLogging();
apiStartRequestLogging();

$response = [
    "app_info" => [
        "android_version_code" => "2.9",
        "apk_url" => "https://ksg.jogai.shop/api/app-release.apk",
        "app_version" => "2.9"
    ]
];
$output = json_encode($response, JSON_UNESCAPED_SLASHES);
apiLog('request_received', [
    'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'query_fields' => apiPayloadFieldNames($_GET),
]);
apiLog('response_sent', [
    'response_status' => http_response_code() ?: 200,
    'response_bytes' => strlen($output),
    'app_version' => $response['app_info']['app_version'],
    'apk_url' => apiSafeUrlInfo($response['app_info']['apk_url']),
]);
echo $output;
?>