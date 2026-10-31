<?php
require_once __DIR__ . '/require_admin.php';

$logDirectory = __DIR__ . '/../api/logs';
$streams = [
    'all' => ['label' => 'Todos os eventos', 'file' => 'api.log'],
    'requests' => ['label' => 'Chamadas recebidas', 'file' => 'requests.log'],
    'actions' => ['label' => 'Ações e processamento', 'file' => 'actions.log'],
    'responses' => ['label' => 'Respostas enviadas', 'file' => 'responses.log'],
    'errors' => ['label' => 'Erros e rejeições', 'file' => 'errors.log'],
    'apk' => ['label' => 'Comunicação com o APK', 'file' => 'apk.log'],
    'admin' => ['label' => 'Ações do painel', 'file' => 'actions.log'],
];
$streamFilter = (string) ($_GET['stream'] ?? 'all');
if (!isset($streams[$streamFilter])) {
    $streamFilter = 'all';
}
$selectedStream = $streams[$streamFilter];
$logFiles = array_filter([
    $logDirectory . '/' . $selectedStream['file'],
    $logDirectory . '/' . $selectedStream['file'] . '.1',
], 'is_file');

if ($streamFilter !== 'all') {
    $logFiles = array_merge($logFiles, array_filter([
        $logDirectory . '/api.log',
        $logDirectory . '/api.log.1',
    ], 'is_file'));
}

function apiLogMatchesStream(array $entry, string $stream): bool
{
    $event = strtolower((string) ($entry['event'] ?? ''));
    if ($stream === 'requests') {
        return $event === 'api_request_received';
    }
    if ($stream === 'responses') {
        return in_array($event, ['api_response_sent', 'response_sent'], true);
    }
    if ($stream === 'errors') {
        return preg_match('/error|failed|fatal|rejected/', $event) === 1;
    }
    if ($stream === 'apk') {
        return in_array((string) ($entry['endpoint'] ?? ''), [
            'getappuser.php',
            'getplaylist.php',
            'getupdate.php',
            'clientlog.php',
            'ads.php',
            'allads.php',
            'theme_config.php',
            'portals.php',
            'themes.php',
            'sport.php',
            'intro.php',
        ], true);
    }
    if ($stream === 'admin') {
        return str_starts_with((string) ($entry['event'] ?? ''), 'admin_');
    }

    return $stream === 'all' || (
        $event !== 'api_request_received'
        && !in_array($event, ['api_response_sent', 'response_sent'], true)
        && preg_match('/error|failed|fatal|rejected/', $event) !== 1
    );
}

$entriesByHash = [];
foreach ($logFiles as $logFile) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log('Admin API log viewer could not read ' . basename($logFile) . '.');
        continue;
    }

    foreach (array_slice($lines, -3000) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && apiLogMatchesStream($entry, $streamFilter)) {
            $entriesByHash[hash('sha256', $line)] = $entry;
        }
    }
}
$entries = array_values($entriesByHash);
usort($entries, static fn(array $left, array $right): int =>
    strcmp((string) ($right['time'] ?? ''), (string) ($left['time'] ?? ''))
);

$endpointFilter = substr(trim((string) ($_GET['endpoint'] ?? '')), 0, 80);
$searchFilter = substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$filteredEntries = array_values(array_filter($entries, static function (array $entry) use ($endpointFilter, $searchFilter): bool {
    if ($endpointFilter !== '' && stripos((string) ($entry['endpoint'] ?? ''), $endpointFilter) === false) {
        return false;
    }

    return $searchFilter === '' || stripos(
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        $searchFilter
    ) !== false;
}));
$shownEntries = array_slice($filteredEntries, 0, 200);
$endpoints = array_values(array_unique(array_filter(array_map(
    static fn(array $entry): string => (string) ($entry['endpoint'] ?? ''),
    $entries
))));
sort($endpoints);

function apiLogHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>Logs da API | Admin</title>
    <link rel="stylesheet" href="assets/panel.css">
    <style>
        * { box-sizing: border-box; }
        body { color: #1d2939; font: 13px "Segoe UI", Arial, sans-serif; }
        .page-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        h1 { margin: 0; color: #101828; font-size: 22px; }
        .muted { color: #667085; }
        .page-head p { max-width: 760px; margin: 4px 0 0; font-size: 11px; line-height: 1.4; }
        .page-head > .muted { flex: 0 0 auto; font-size: 11px; text-align: right; }
        .panel { overflow: hidden; border: 1px solid #eaecf0; border-radius: 9px; background: #fff; }
        .filters { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; padding: 9px 10px; border-bottom: 1px solid #eaecf0; }
        input, select { min-height: 32px; padding: 5px 8px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; font-size: 11px; }
        input[name="q"] { flex: 1 1 220px; min-width: 180px; }
        button { min-height: 32px; padding: 5px 10px; border: 0; border-radius: 6px; background: #3157d5; color: white; font: inherit; font-size: 11px; cursor: pointer; }
        .stream-note { margin: 0; padding: 5px 10px; font-size: 10px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 5px 7px; border-bottom: 1px solid #eaecf0; text-align: left; vertical-align: middle; overflow-wrap: anywhere; }
        th { background: #fcfcfd; color: #667085; font-size: 9px; text-transform: uppercase; white-space: nowrap; }
        td { font-size: 10px; line-height: 1.3; }
        tbody tr:hover { background: #fafbff; }
        .event { font-weight: 600; }
        .status { font-variant-numeric: tabular-nums; }
        details { min-width: 0; }
        summary { color: #3157d5; cursor: pointer; font-size: 10px; }
        pre { max-width: 600px; max-height: 240px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; color: #344054; font-size: 10px; }
        .empty { padding: 16px; color: #667085; text-align: center; }
        @media (max-width: 1300px) {
            .table-wrap { overflow: visible; }
            thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); clip-path: inset(50%); }
            table, tbody { display: block; width: 100%; }
            tbody { display: grid; gap: 6px; padding: 7px; }
            tbody tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 10px; padding: 4px 7px; border: 1px solid #eaecf0; border-radius: 7px; }
            tbody td { display: grid; grid-template-columns: max-content minmax(0, 1fr); align-items: baseline; gap: 6px; min-width: 0; padding: 4px 0; border: 0; border-bottom: 1px solid #f0f1f3; font-size: 10px; }
            tbody td::before { color: #667085; font-size: 8px; font-weight: 700; content: attr(data-label); }
            tbody td:last-child { grid-column: 1 / -1; }
            tbody td[colspan] { display: block; grid-column: 1 / -1; border: 0; }
            tbody td[colspan]::before { content: none; }
        }
        @media (max-width: 480px) {
            .page-head { align-items: flex-start; flex-direction: column; gap: 5px; }
            .page-head > .muted { text-align: left; }
            .filters { align-items: stretch; }
            .filters select { flex: 1 1 calc(50% - 7px); min-width: 0; }
            .filters input[name="q"] { flex: 1 1 70%; min-width: 0; }
            .filters button { flex: 1 1 auto; }
            tbody tr { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            body.admin-page.api-logs-page .table-wrap tbody td { display: block; }
            body.admin-page.api-logs-page .table-wrap tbody td::before { display: block; margin-bottom: 2px; }
            body.admin-page.api-logs-page .table-wrap tbody td:last-child { grid-column: 1 / -1; }
            body.admin-page.api-logs-page .table-wrap tbody td {
                padding: 3px 5px;
                font-size: 10px;
                line-height: 1.2;
            }
        }
    </style>
</head>
<body class="admin-page api-logs-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="page-head">
        <div>
            <h1>Logs da API</h1>
            <p class="muted">Eventos separados por etapa. As chamadas do APK só aparecem quando o aplicativo ou dispositivo efetivamente acessa a API. Atualiza a cada 15 segundos.</p>
        </div>
        <span class="muted"><?= count($filteredEntries) ?> registros encontrados · mostrando <?= count($shownEntries) ?></span>
    </header>
    <section class="panel" aria-label="Registros da API">
        <form class="filters" method="get">
            <select name="stream" aria-label="Categoria dos logs">
                <?php foreach ($streams as $streamKey => $stream): ?>
                    <option value="<?= apiLogHtml($streamKey) ?>"<?= $streamFilter === $streamKey ? ' selected' : '' ?>>
                        <?= apiLogHtml($stream['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="endpoint" aria-label="Filtrar endpoint">
                <option value="">Todos os endpoints</option>
                <?php foreach ($endpoints as $endpoint): ?>
                    <option value="<?= apiLogHtml($endpoint) ?>"<?= $endpointFilter === $endpoint ? ' selected' : '' ?>>
                        <?= apiLogHtml($endpoint) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="q" value="<?= apiLogHtml($searchFilter) ?>" placeholder="Buscar evento, MAC final, request ID..." aria-label="Buscar nos logs">
            <button type="submit">Filtrar</button>
        </form>
        <p class="muted stream-note">Exibindo: <strong><?= apiLogHtml($selectedStream['label']) ?></strong> · arquivo <code><?= apiLogHtml($selectedStream['file']) ?></code></p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Horário</th><th>Endpoint / método</th><th>Status</th><th>Evento</th><th>Request ID</th><th>Detalhes</th></tr>
                </thead>
                <tbody>
                <?php if ($shownEntries === []): ?>
                    <tr><td class="empty" colspan="6">Nenhum registro encontrado. A API registrará chamadas assim que os dispositivos fizerem novas requisições.</td></tr>
                <?php else: ?>
                    <?php foreach ($shownEntries as $entry): ?>
                        <tr>
                            <td data-label="Horário"><?= apiLogHtml($entry['time'] ?? '') ?></td>
                            <td data-label="Endpoint / método"><?= apiLogHtml($entry['endpoint'] ?? '') ?><br><span class="muted"><?= apiLogHtml($entry['method'] ?? '') ?></span></td>
                            <td class="status" data-label="Status"><?= apiLogHtml($entry['response_status'] ?? $entry['status'] ?? '') ?></td>
                            <td class="event" data-label="Evento"><?= apiLogHtml($entry['event'] ?? '') ?></td>
                            <td data-label="Request ID"><?= apiLogHtml($entry['request_id'] ?? '') ?></td>
                            <td data-label="Detalhes"><details><summary>Ver dados registrados</summary><pre><?= apiLogHtml(json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') ?></pre></details></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
