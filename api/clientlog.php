<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/api_log.php';
require_once __DIR__ . '/includes/db.php';
apiRegisterRuntimeLogging();
apiStartRequestLogging();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    apiLog('client_log_rejected', ['reason' => 'method_not_allowed']);
    echo json_encode(['error' => 'Use POST para enviar logs.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 16384) {
    http_response_code(413);
    apiLog('client_log_rejected', ['reason' => 'body_too_large_or_unreadable']);
    echo json_encode(['error' => 'O log excede o limite permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    apiLog('client_log_rejected', [
        'reason' => 'invalid_json',
        'body_bytes' => strlen($body),
    ]);
    echo json_encode(['error' => 'Envie um objeto JSON válido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$macValue = $payload['mac_address'] ?? $payload['mac'] ?? '';
$mac = is_string($macValue) ? normalizeDeviceMac($macValue) : null;
$eventValue = $payload['event'] ?? '';
$messageValue = $payload['message'] ?? '';
$severityValue = strtolower((string) ($payload['severity'] ?? 'error'));

if ($mac === null || !is_string($eventValue) || !is_string($messageValue)) {
    http_response_code(400);
    apiLog('client_log_rejected', [
        'reason' => 'required_fields_invalid',
        'payload_fields' => apiPayloadFieldNames($payload),
        'mac' => apiMaskedMac(is_string($macValue) ? $macValue : null),
    ]);
    echo json_encode(['error' => 'Informe mac_address, event e message válidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedSeverities = ['debug', 'info', 'warning', 'error', 'fatal'];
if (!in_array($severityValue, $allowedSeverities, true)) {
    $severityValue = 'error';
}

$event = apiRedactClientLog(trim($eventValue));
$message = apiRedactClientLog(trim($messageValue));
if ($event === '' || $message === '') {
    http_response_code(400);
    apiLog('client_log_rejected', [
        'reason' => 'empty_event_or_message',
        'mac' => apiMaskedMac($mac),
    ]);
    echo json_encode(['error' => 'event e message não podem ficar vazios.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stackValue = $payload['stack'] ?? '';
$stack = is_string($stackValue) ? apiRedactClientLog($stackValue) : '';
$versionValue = $payload['app_version'] ?? '';
$screenValue = $payload['screen'] ?? '';
$appVersion = is_string($versionValue) ? substr(apiRedactClientLog($versionValue), 0, 80) : '';
$screen = is_string($screenValue) ? substr(apiRedactClientLog($screenValue), 0, 120) : '';

try {
    $db = getDB();
    $device = findDeviceByMac($db, $mac);
    if ($device === null) {
        http_response_code(403);
        apiLog('client_log_rejected', [
            'reason' => 'unregistered_device',
            'mac' => apiMaskedMac($mac),
        ]);
        echo json_encode(['error' => 'Dispositivo não registrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    apiLog('client_log_received', [
        'severity' => $severityValue,
        'client_event' => $event,
        'client_message' => $message,
        'client_stack' => $stack,
        'client_screen' => $screen,
        'app_version' => $appVersion,
        'mac' => apiMaskedMac($mac),
        'device_id' => (int) $device['id'],
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    apiLog('client_log_failed', [
        'mac' => apiMaskedMac($mac),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    echo json_encode(['error' => 'Não foi possível registrar o log.'], JSON_UNESCAPED_UNICODE);
}
