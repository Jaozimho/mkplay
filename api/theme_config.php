<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/branding.php';

    $macParameter = $_GET['mac'] ?? '';
    if (!is_string($macParameter) || trim($macParameter) === '') {
        http_response_code(400);
        apiLog('theme_config_rejected', ['reason' => 'missing_mac']);
        echo json_encode(['error' => 'Informe um MAC válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mac = normalizeDeviceMac(trim($macParameter));
    if ($mac === null) {
        http_response_code(400);
        apiLog('theme_config_rejected', ['reason' => 'invalid_mac']);
        echo json_encode(['error' => 'Informe um MAC válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    apiLog('theme_config_requested', ['mac' => apiMaskedMac($mac)]);

    $db = getDB();
    $device = findDeviceByMac($db, $mac);
    if ($device === null) {
        http_response_code(404);
        apiLog('theme_config_rejected', [
            'reason' => 'device_not_found',
            'mac' => apiMaskedMac($mac),
        ]);
        echo json_encode(['error' => 'Dispositivo não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $accountId = isset($device['id_user']) && (int) $device['id_user'] > 0
        ? (int) $device['id_user']
        : null;
    $logoUrl = getAccountLogoUrl($db, $accountId);
    $configuration = [
        'home_theme' => getAccountHomeTheme($db, $accountId),
        'logo' => $logoUrl,
        'logo_url' => $logoUrl,
        'background_url' => getAccountBackgroundUrl($db, $accountId),
        'branding_name' => getAccountBrandingName($db, $accountId),
    ];
    $configuration['version'] = hash(
        'sha256',
        json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    apiLog('theme_config_response_ready', [
        'mac' => apiMaskedMac($mac),
        'home_theme' => (string) $configuration['home_theme'],
        'version' => $configuration['version'],
        'logo_available' => $configuration['logo'] !== '',
        'background_available' => $configuration['background_url'] !== '',
    ]);
    echo json_encode(
        $configuration,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log('Theme configuration API failed: ' . $exception->getMessage());
    http_response_code(500);
    apiLog('theme_config_failed', ['exception' => get_class($exception)]);
    echo json_encode(['error' => 'Não foi possível carregar a configuração do tema.'], JSON_UNESCAPED_UNICODE);
}
