<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
require_once __DIR__ . '/includes/db.php';

try {
    getDB();
    echo 'Banco central verificado e estrutura atualizada em api/db/.ansdb.db';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erro ao preparar o banco central: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
