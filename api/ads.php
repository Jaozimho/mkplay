<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/ads.php';
    $db = getDB();
    $macParameter = $_GET['mac'] ?? '';
    if (!is_string($macParameter)) {
        http_response_code(400);
        apiLog('ads_api_rejected', ['reason' => 'invalid_mac_type']);
        echo json_encode(['error' => 'MAC inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $macInput = trim($macParameter);
    $accountId = null;
    $mac = null;
    if ($macInput === '') {
        $mac = null;
    } else {
        $mac = normalizeDeviceMac($macInput);
        if ($mac === null) {
            http_response_code(400);
            apiLog('ads_api_rejected', ['reason' => 'invalid_mac']);
            echo json_encode(['error' => 'MAC inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $device = findDeviceByMac($db, $mac);
        if ($device === null) {
            http_response_code(404);
            apiLog('ads_api_rejected', [
                'reason' => 'device_not_found',
                'mac' => apiMaskedMac($mac),
            ]);
            echo json_encode(['error' => 'Dispositivo não encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $accountId = isset($device['id_user']) && (int) $device['id_user'] > 0
            ? (int) $device['id_user']
            : null;
    }

    $response = getAdApiResponse($db, $accountId, $mac);
    apiLog('ads_api_response_ready', [
        'source' => getAdSourceMode($db),
        'item_count' => count($response),
        'mac' => apiMaskedMac($mac),
    ]);

    echo json_encode(
        $response,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (PDOException $e) {
    error_log('Ads API database error: ' . $e->getMessage());
    http_response_code(500);
    apiLog('ads_api_failed', ['exception' => get_class($e), 'failure' => 'database']);
    echo json_encode(['error' => 'Não foi possível carregar os anúncios.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Ads API request failed: ' . $e->getMessage());
    http_response_code(500);
    apiLog('ads_api_failed', ['exception' => get_class($e), 'failure' => 'processing']);
    echo json_encode(['error' => 'Não foi possível carregar os anúncios.'], JSON_UNESCAPED_UNICODE);
}
