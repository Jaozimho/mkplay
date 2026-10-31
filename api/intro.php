<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
require_once __DIR__ . '/includes/db.php';

try {
    $url = getIntroUrl(getDB());
} catch (PDOException $e) {
    error_log('Intro API database error: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível carregar a introdução.');
}

if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
    http_response_code(404);
    exit('URL de introdução não configurada.');
}

header('Location: ' . $url, true, 302);
exit;
