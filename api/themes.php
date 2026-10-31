<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_once __DIR__ . '/includes/db.php';
    $themes = array_map(
        static fn(array $theme): array => [
            'name' => $theme['name'],
            'url' => $theme['url'],
        ],
        getThemes(getDB())
    );
    echo json_encode($themes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    error_log('Themes API database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível carregar os temas.'], JSON_UNESCAPED_UNICODE);
}
