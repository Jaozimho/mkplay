<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/includes/api_log.php';
apiRegisterRuntimeLogging();
apiStartRequestLogging();

function log_debug($msg) {
    error_log((string) $msg);
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    apiLog('request_rejected', ['reason' => 'unsupported_method']);
    echo json_encode(
        ['error' => 'Método não permitido. Use GET com o parâmetro data ou POST com o campo data.'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$outer = null;
if ($requestMethod === 'GET') {
    if (!array_key_exists('data', $_GET)) {
        apiLog('api_usage_requested', ['method' => 'GET']);
        echo json_encode([
            'service' => 'IBO Device API',
            'status' => 'ready',
            'message' => 'Este endpoint recebe os dados do dispositivo. Esta resposta confirma somente que a API está acessível.',
            'methods' => [
                'POST' => ['Content-Type: application/json', 'body' => ['data' => 'dados_codificados_do_dispositivo']],
                'GET' => ['query' => ['data' => 'dados_codificados_do_dispositivo']],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (is_string($_GET['data'])) {
        $outer = ['data' => $_GET['data']];
    }
} else {
    $input = file_get_contents('php://input');
    if (is_string($input) && trim($input) !== '') {
        $decodedInput = json_decode($input, true);
        if (is_array($decodedInput)) {
            $outer = $decodedInput;
        }
    }
    if ($outer === null && isset($_POST['data']) && is_string($_POST['data'])) {
        $outer = ['data' => $_POST['data']];
    }
}

if (!is_array($outer) || !isset($outer['data']) || !is_string($outer['data']) || trim($outer['data']) === '') {
    error_log('App user API request received without a valid data field.');
    http_response_code(400);
    apiLog('request_rejected', ['reason' => 'missing_or_invalid_data_field']);
    echo json_encode(
        ['error' => 'Campo data ausente ou inválido. Envie um JSON com {"data":"..."} por POST.'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

require_once __DIR__ . '/includes/db.php';
$decodeFailure = null;
$mac = decodeAppDeviceMac($outer['data'], $decodeFailure);
if ($mac === null) {
    error_log('App user API request received with an invalid device MAC.');
    http_response_code(400);
    apiLog('request_rejected', ['reason' => $decodeFailure ?? 'invalid_device_data']);
    log_debug('MAC inválido recebido pelo endpoint.');
    echo json_encode(['error' => 'MAC inválido']);
    exit;
}
apiLog('request_decoded', [
    'mac' => apiMaskedMac($mac),
    'decoder' => 'app_device_id',
]);

try {
    $db = getDB();
    $device = findDeviceByMac($db, $mac);
    $created = false;

    if ($device !== null && !isDeviceRequestAuthorized($device)) {
        http_response_code(401);
        apiLog('request_rejected', [
            'reason' => 'invalid_device_key',
            'mac' => apiMaskedMac($mac),
        ]);
        echo json_encode(
            ['error' => 'Autenticação do dispositivo necessária.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if (!$device) {
        $db->beginTransaction();
        $defaultResellerSetting = $db->prepare("
            SELECT setting_value
            FROM app_settings
            WHERE setting_key = 'apk_default_reseller_id'
        ");
        $defaultResellerSetting->execute();
        $defaultResellerValue = (string) ($defaultResellerSetting->fetchColumn() ?: '');
        $defaultReseller = null;
        if ($defaultResellerValue !== '') {
            $defaultResellerId = filter_var($defaultResellerValue, FILTER_VALIDATE_INT);
            if ($defaultResellerId === false || $defaultResellerId < 1) {
                throw new RuntimeException('A revenda padrão configurada para o APK é inválida.');
            }

            $resellerQuery = $db->prepare('
                SELECT id
                FROM users
                WHERE id = ? AND COALESCE(admin, 0) <> 1
            ');
            $resellerQuery->execute([$defaultResellerId]);
            $defaultReseller = $resellerQuery->fetch();
            if ($defaultReseller === false) {
                $db->rollBack();
                throw new RuntimeException('A revenda padrão configurada para o APK não existe.');
            }

            $lockQuota = $db->prepare('UPDATE users SET mac_amount = mac_amount WHERE id = ?');
            $lockQuota->execute([(int) $defaultReseller['id']]);
            $quota = getResellerQuotaSummary($db, (int) $defaultReseller['id']);
            if ($quota['available'] < 1) {
                $db->rollBack();
                http_response_code(403);
                apiLog('request_rejected', [
                    'reason' => 'default_reseller_mac_limit',
                    'mac' => apiMaskedMac($mac),
                    'reseller_id' => (int) $defaultReseller['id'],
                ]);
                echo json_encode(
                    ['error' => 'A revenda padrão não tem MACs disponíveis.'],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }
        }

        $key = (string) random_int(100000, 999999);
        $expireDate = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
        $insert = $db->prepare('
            INSERT INTO ibo (mac_address, "key", title, type, expire_date, active, id_user, created_at)
            VALUES (?, ?, ?, 1, ?, 1, ?, CURRENT_TIMESTAMP)
        ');
        $insert->execute([
            $mac,
            $key,
            'NEW DEVICE',
            $expireDate,
            $defaultReseller === null ? null : (int) $defaultReseller['id'],
        ]);
        $db->commit();
        $device = findDeviceByMac($db, $mac);
        $created = true;
    }

    $response = array_merge(getDeviceAppResponse($db, $device, $mac), [
        'is_google_paid' => true,
        'notification' => (object) [],
        'plan_id' => '03370629',
        'pin' => '0000',
        'price' => '0',
        'app_version' => '2.9',
        'apk_link' => '',
    ]);

    $logoUrl = trim((string) ($response['logo_url'] ?? $response['logo'] ?? ''));
    $logoOwnerId = isset($device['id_user']) && (int) $device['id_user'] > 0
        ? (int) $device['id_user']
        : null;
    apiLog('branding_resolved', [
        'mac' => apiMaskedMac($mac),
        'owner_user_id' => $logoOwnerId,
        'logo_available' => $logoUrl !== '',
        'logo_fields' => implode(',', array_filter(
            ['logo', 'logo_url'],
            static fn(string $field): bool => isset($response[$field])
        )),
        'logo_url' => $logoUrl,
    ]);

    $appResponse = json_encode(
        $response,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $encodedResponse = base64_encode($appResponse) . 'aa';
    $output = json_encode(['data' => $encodedResponse], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $responseStatus = http_response_code() ?: 200;
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('App user API request failed: ' . $e->getMessage());
    http_response_code(500);
    apiLog('request_failed', [
        'mac' => apiMaskedMac($mac),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    echo json_encode(['error' => 'Não foi possível processar o dispositivo.']);
    exit;
}

error_log('App user API request processed; registered=' . ($device !== null ? 'yes' : 'no'));
apiLog('request_processed', [
    'mac' => apiMaskedMac($mac),
    'device_found' => $device !== null,
    'device_created' => $created,
    'mac_registered' => (bool) ($response['mac_registered'] ?? false),
    'device_active' => (bool) ($response['is_active'] ?? false),
    'device_blocked' => (bool) ($response['is_blocked'] ?? false),
    'device_status' => (string) ($response['device_status'] ?? 'unknown'),
    'playlist_configured' => (($response['urls'][0]['url'] ?? '') !== 'http://blocked.local/'),
    'device_blocked' => (bool) ($response['is_blocked'] ?? false),
    'device_status' => (string) ($response['device_status'] ?? 'unregistered'),
    'device_expire_date' => (string) ($device['expire_date'] ?? ''),
    'device_expired' => isDeviceExpired((string) ($device['expire_date'] ?? '')),
    'trial_days' => (int) ($response['trial_days'] ?? 0),
    'app_is_trial_flag' => (bool) ($response['is_trial'] ?? false),
    'activation_qr_available' => (string) ($response['qrcode'] ?? '') !== '',
    'themes_count' => count($response['themes'] ?? []),
    'home_theme' => (string) ($response['home_theme'] ?? ''),
    'ads_count' => count($response['ads'] ?? []),
    'theme_config_available' => (string) ($response['theme_config_url'] ?? '') !== '',
]);
apiLog('device_ads_included', [
    'mac' => apiMaskedMac($mac),
    'ads_count' => count($response['ads'] ?? []),
    'ad_fields' => apiPayloadFieldNames($response['ads'][0] ?? []),
]);
apiLog('response_sent', [
    'response_status' => $responseStatus,
    'response_bytes' => strlen($output),
    'response_fields' => apiPayloadFieldNames($response),
    'playlist_count' => count($response['urls'] ?? []),
    'playlist_name' => (string) ($response['urls'][0]['name'] ?? ''),
    'device_blocked' => (bool) ($response['is_blocked'] ?? false),
    'device_status' => (string) ($response['device_status'] ?? 'unknown'),
    'mac_registered' => (bool) ($response['mac_registered'] ?? false),
    'playlist_url' => apiSafeUrlInfo((string) ($response['urls'][0]['url'] ?? '')),
    'themes_count' => count($response['themes'] ?? []),
    'encoded_response' => true,
]);
echo $output;
log_debug('Sucesso ao registrar/carregar um dispositivo.');