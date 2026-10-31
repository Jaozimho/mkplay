<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/includes/db.php';

$macInput = $_GET['m'] ?? '';
if (!is_string($macInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'MAC inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mac = normalizeDeviceMac(str_ireplace('/android', '', $macInput));
if ($mac === null) {
    http_response_code(400);
    echo json_encode(['error' => 'MAC inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDB();
    $device = findDeviceByMac($db, $mac);
    if ($device === null || !isDeviceRequestAuthorized($device)) {
        http_response_code(401);
        echo json_encode(['error' => 'Autenticação do dispositivo necessária.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response = getDeviceAppResponse($db, $device, $mac);
    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (PDOException | JsonException $e) {
    error_log('Get playlist API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível carregar os dados do dispositivo.'], JSON_UNESCAPED_UNICODE);
}
