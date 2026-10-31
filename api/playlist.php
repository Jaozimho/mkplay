<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/includes/api_log.php';
require_once __DIR__ . '/includes/db.php';
apiRegisterRuntimeLogging();

$macInput = $_REQUEST['mac'] ?? $_REQUEST['m'] ?? '';
$requestBody = '';
$requestData = null;
if ($macInput === '') {
    $requestBody = file_get_contents('php://input');
    if ($requestBody !== false && $requestBody !== '') {
        $requestData = json_decode($requestBody, true);
        if (is_array($requestData)) {
            if (isset($requestData['data']) && is_string($requestData['data'])) {
                $macInput = decodeAppDeviceMac($requestData['data']) ?? '';
            } else {
                $macInput = $requestData['mac']
                    ?? $requestData['m']
                    ?? $requestData['mac_address']
                    ?? '';
                if ($macInput === '' && isset($requestData['app_device_id']) && is_string($requestData['app_device_id'])) {
                    $decodedMac = base64_decode($requestData['app_device_id'], true);
                    $macInput = $decodedMac === false ? '' : $decodedMac;
                }
            }
        }
    }
}
apiLog('request_received', [
    'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'body_bytes' => is_string($requestBody) ? strlen($requestBody) : 0,
    'mac_parameter' => isset($_REQUEST['mac']) ? 'mac' : (isset($_REQUEST['m']) ? 'm' : 'body'),
    'request_fields' => $requestData !== null
        ? apiPayloadFieldNames($requestData)
        : apiPayloadFieldNames($_REQUEST),
    'outer_json_valid' => $requestData !== null || $requestBody === '',
]);
if (!is_string($macInput) || $macInput === '') {
    http_response_code(400);
    apiLog('request_rejected', ['reason' => 'missing_mac']);
    $output = '[]';
    apiLog('response_sent', ['response_status' => 400, 'response_bytes' => strlen($output), 'playlist_count' => 0]);
    echo $output;
    exit;
}

$mac = normalizeDeviceMac($macInput);
if ($mac === null) {
    http_response_code(400);
    apiLog('request_rejected', ['reason' => 'invalid_mac']);
    $output = '[]';
    apiLog('response_sent', ['response_status' => 400, 'response_bytes' => strlen($output), 'playlist_count' => 0]);
    echo $output;
    exit;
}
apiLog('request_decoded', ['mac' => apiMaskedMac($mac)]);

try {
    $db = getDB();
    $device = findDeviceByMac($db, $mac);
    if ($device === null) {
        apiLog('request_processed', [
            'mac' => apiMaskedMac($mac),
            'device_found' => false,
            'playlist_count' => 0,
        ]);
        $output = '[]';
        apiLog('response_sent', [
            'response_status' => 200,
            'response_bytes' => strlen($output),
            'response_fields' => '[]',
            'playlist_count' => 0,
        ]);
        echo $output;
        exit;
    }

    if (!isDeviceRequestAuthorized($device)) {
        http_response_code(401);
        apiLog('request_rejected', [
            'reason' => 'invalid_device_key',
            'mac' => apiMaskedMac($mac),
        ]);
        $output = json_encode(
            ['error' => 'Autenticação do dispositivo necessária.'],
            JSON_UNESCAPED_UNICODE
        );
        apiLog('response_sent', [
            'response_status' => 401,
            'response_bytes' => strlen($output),
            'response_fields' => 'error',
            'playlist_count' => 0,
        ]);
        echo $output;
        exit;
    }

    $deviceResponse = getDeviceAppResponse($db, $device, $mac);
    $mainPlaylist = $deviceResponse['urls'][0] ?? [];
    $response = [];

    if (($mainPlaylist['url'] ?? '') !== 'http://blocked.local/') {
        $response[] = [
            'name' => $mainPlaylist['name'] ?? 'Main Playlist',
            'url' => $mainPlaylist['url'],
            'playlist_type' => $mainPlaylist['playlist_type'] ?? 'general',
            'is_protected' => (int) ($mainPlaylist['is_protected'] ?? 0),
            'pin' => (string) ($mainPlaylist['pin'] ?? ''),
            'id' => (string) ($mainPlaylist['id'] ?? ''),
        ];
    }

    $deviceAllowed = (int) ($device['active'] ?? 0) === 1
        && !isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null);
    if ($deviceAllowed) {
        $normalized = str_replace(':', '', $mac);
        $legacyCompact = str_starts_with($normalized, '0') ? substr($normalized, 1) : $normalized;
        $playlists = $db->prepare('
            SELECT id, name, url, type, is_protected, pin
            FROM playlist
            WHERE upper(replace(replace(mac_address, char(58), ""), char(45), "")) IN (?, ?)
            ORDER BY id ASC
        ');
        $playlists->execute([$normalized, $legacyCompact]);

        foreach ($playlists->fetchAll() as $playlist) {
            $response[] = [
                'name' => $playlist['name'],
                'url' => $playlist['url'],
                'playlist_type' => (string) $playlist['type'] === 'xc' ? 'xc' : 'general',
                'is_protected' => (int) $playlist['is_protected'],
                'pin' => (string) $playlist['pin'],
                'id' => (string) $playlist['id'],
            ];
        }
    }

    $deviceStatus = (int) ($device['active'] ?? 0) !== 1
        ? 'blocked'
        : (isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null) ? 'expired' : 'active');
    apiLog('request_processed', [
        'mac' => apiMaskedMac($mac),
        'device_found' => true,
        'playlist_count' => count($response),
        'playlist_configured' => count($response) > 0,
        'device_active' => (bool) ($device['active'] ?? false),
        'device_blocked' => $deviceStatus === 'blocked',
        'device_status' => $deviceStatus,
        'device_expire_date' => (string) ($device['expire_date'] ?? ''),
    ]);
    $output = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $playlistHosts = array_map(
        static fn(array $playlist): string => apiSafeUrlInfo((string) ($playlist['url'] ?? '')),
        $response
    );
    apiLog('response_sent', [
        'response_status' => 200,
        'response_bytes' => strlen($output),
        'response_fields' => 'name,url,playlist_type,is_protected,pin,id',
        'playlist_count' => count($response),
        'playlist_names' => implode('|', array_column($response, 'name')),
        'playlist_urls' => implode('|', $playlistHosts),
    ]);
    echo $output;
} catch (Throwable $e) {
    error_log('Playlist API request failed: ' . $e->getMessage());
    apiLog('request_failed', [
        'mac' => apiMaskedMac($mac),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    http_response_code(500);
    $output = json_encode(['error' => 'Não foi possível carregar as playlists.'], JSON_UNESCAPED_UNICODE);
    apiLog('response_sent', [
        'response_status' => 500,
        'response_bytes' => strlen($output),
        'response_fields' => 'error',
    ]);
    echo $output;
}
