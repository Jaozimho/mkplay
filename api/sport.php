<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();

$timezone = new DateTimeZone('America/Sao_Paulo');
$today = new DateTimeImmutable('now', $timezone);
$config = [];
$fixtures = [];
$loadError = '';

try {
    require_once __DIR__ . '/includes/db.php';
    $db = getDB();
    $config = $db->query('SELECT title, c1, c2, c3 FROM sportconfira ORDER BY id ASC LIMIT 1')->fetch() ?: [];

    $apiKey = getenv('API_SPORTS_KEY');
    if (!is_string($apiKey) || trim($apiKey) === '') {
        $keyQuery = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'api_sports_key'");
        $keyQuery->execute();
        $apiKey = $keyQuery->fetchColumn();
    }
    if (!is_string($apiKey) || trim($apiKey) === '') {
        throw new RuntimeException('A chave da API-Sports não está configurada no servidor.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
    }

    $query = http_build_query([
        'date' => $today->format('Y-m-d'),
        'timezone' => $timezone->getName(),
    ]);
    $handle = curl_init('https://v3.football.api-sports.io/fixtures?' . $query);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['x-apisports-key: ' . trim($apiKey)],
    ]);
    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($body)) {
        throw new RuntimeException('Falha ao consultar a API-Sports: ' . $curlError);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('A API-Sports respondeu com HTTP ' . $httpStatus . '.');
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !isset($payload['response']) || !is_array($payload['response'])) {
        throw new RuntimeException('A resposta da API-Sports está em formato inválido.');
    }
    if (!empty($payload['errors'])) {
        throw new RuntimeException('A API-Sports retornou um erro na consulta de partidas.');
    }

    $fixtures = $payload['response'];
} catch (Throwable $e) {
    error_log('Sports fixtures request failed: ' . $e->getMessage());
    apiLog('sports_fixtures_failed', [
        'error' => $e->getMessage(),
        'date' => $today->format('Y-m-d'),
        'timezone' => $timezone->getName(),
    ]);
    http_response_code(502);
    $loadError = 'Não foi possível carregar os jogos de hoje. Tente novamente mais tarde.';
}

$safeColor = static function ($color, string $default): string {
    return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1
        ? $color
        : $default;
};
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$title = $escape($config['title'] ?? 'Jogos de hoje');
$borderColor = $safeColor($config['c1'] ?? null, '#1d23dd');
$backgroundColor = $safeColor($config['c2'] ?? null, '#ffffff');
$textColor = $safeColor($config['c3'] ?? null, '#000000');
$statusLabels = [
    'NS' => 'A iniciar',
    '1H' => '1º tempo',
    'HT' => 'Intervalo',
    '2H' => '2º tempo',
    'ET' => 'Prorrogação',
    'P' => 'Pênaltis',
    'FT' => 'Encerrado',
    'AET' => 'Encerrado após prorrogação',
    'PEN' => 'Encerrado nos pênaltis',
    'PST' => 'Adiado',
    'CANC' => 'Cancelado',
    'SUSP' => 'Suspenso',
    'INT' => 'Interrompido',
    'ABD' => 'Abandonado',
    'LIVE' => 'Ao vivo',
];
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; background: <?= $backgroundColor ?>; color: <?= $textColor ?>; font: 14px Arial, sans-serif; }
        main { min-height: 100vh; padding: 12px; border: 2px solid <?= $borderColor ?>; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .date { margin: 0 0 14px; opacity: .7; }
        .match { display: grid; grid-template-columns: 54px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 12px 8px; border-top: 1px solid <?= $borderColor ?>; }
        .time { text-align: center; font-weight: 700; }
        .league, .status { display: block; margin-bottom: 5px; font-size: 11px; opacity: .72; }
        .teams { display: grid; gap: 5px; }
        .team { display: flex; min-width: 0; align-items: center; gap: 6px; }
        .team img { width: 18px; height: 18px; object-fit: contain; }
        .team-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .score { min-width: 42px; text-align: center; font-size: 17px; font-weight: 700; }
        .message { padding: 20px 8px; text-align: center; }
    </style>
</head>
<body>
<main>
    <h1><?= $title ?></h1>
    <p class="date"><?= $today->format('d/m/Y') ?></p>
    <?php if ($loadError !== ''): ?>
        <p class="message"><?= $escape($loadError) ?></p>
    <?php elseif ($fixtures === []): ?>
        <p class="message">Não há jogos programados para hoje.</p>
    <?php else: ?>
        <?php foreach ($fixtures as $fixture): ?>
            <?php
            $matchDate = isset($fixture['fixture']['date'])
                ? new DateTimeImmutable((string) $fixture['fixture']['date'])
                : null;
            $statusCode = (string) ($fixture['fixture']['status']['short'] ?? '');
            $statusLabel = $statusLabels[$statusCode] ?? $statusCode;
            $homeLogo = (string) ($fixture['teams']['home']['logo'] ?? '');
            $awayLogo = (string) ($fixture['teams']['away']['logo'] ?? '');
            $homeScore = $fixture['goals']['home'] ?? '-';
            $awayScore = $fixture['goals']['away'] ?? '-';
            ?>
            <article class="match">
                <div class="time"><?= $matchDate ? $matchDate->setTimezone($timezone)->format('H:i') : '--:--' ?></div>
                <div>
                    <span class="league"><?= $escape($fixture['league']['name'] ?? '') ?> · <?= $escape($statusLabel) ?></span>
                    <div class="teams">
                        <div class="team">
                            <?php if (filter_var($homeLogo, FILTER_VALIDATE_URL) && parse_url($homeLogo, PHP_URL_SCHEME) === 'https'): ?>
                                <img src="<?= $escape($homeLogo) ?>" alt="">
                            <?php endif; ?>
                            <span class="team-name"><?= $escape($fixture['teams']['home']['name'] ?? 'Mandante') ?></span>
                        </div>
                        <div class="team">
                            <?php if (filter_var($awayLogo, FILTER_VALIDATE_URL) && parse_url($awayLogo, PHP_URL_SCHEME) === 'https'): ?>
                                <img src="<?= $escape($awayLogo) ?>" alt="">
                            <?php endif; ?>
                            <span class="team-name"><?= $escape($fixture['teams']['away']['name'] ?? 'Visitante') ?></span>
                        </div>
                    </div>
                </div>
                <div class="score"><?= $escape($homeScore) ?> - <?= $escape($awayScore) ?></div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
