<?php

function logAdApiEvent(string $event, array $context = []): void
{
    if (function_exists('apiLog')) {
        apiLog($event, $context);
    }
}

function getAdSetting(PDO $db, string $key): string
{
    $statement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->execute([$key]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function setAdSetting(PDO $db, string $key, string $value): void
{
    $statement = $db->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
    ');
    $statement->execute([$key, $value]);
}

function getAdSourceMode(PDO $db, ?int $accountId = null): string
{
    if ($accountId !== null && $accountId > 0) {
        $accountMode = getAdSetting($db, 'ads_source_mode_user_' . $accountId);
        if (in_array($accountMode, ['manual', 'tmdb'], true)) {
            return $accountMode;
        }
    }
    return getAdSetting($db, 'ads_source_mode') === 'tmdb' ? 'tmdb' : 'manual';
}

function getAccountAdSourceMode(PDO $db, int $accountId): ?string
{
    $mode = getAdSetting($db, 'ads_source_mode_user_' . $accountId);
    return in_array($mode, ['manual', 'tmdb'], true) ? $mode : null;
}

function setAdSourceMode(PDO $db, string $mode, ?int $accountId = null): void
{
    if (!in_array($mode, ['manual', 'tmdb'], true)) {
        throw new InvalidArgumentException('Selecione um modo de anúncios válido.');
    }
    $settingKey = $accountId !== null && $accountId > 0
        ? 'ads_source_mode_user_' . $accountId
        : 'ads_source_mode';
    setAdSetting($db, $settingKey, $mode);
}

function clearAccountAdSourceMode(PDO $db, int $accountId): void
{
    $statement = $db->prepare('DELETE FROM app_settings WHERE setting_key = ?');
    $statement->execute(['ads_source_mode_user_' . $accountId]);
}

function getTmdbApiKey(PDO $db): string
{
    $environmentKey = getenv('TMDB_API_KEY');
    if (is_string($environmentKey) && trim($environmentKey) !== '') {
        return trim($environmentKey);
    }

    return getAdSetting($db, 'tmdb_api_key');
}

function saveTmdbApiKey(PDO $db, string $apiKey): void
{
    if (strlen($apiKey) > 200) {
        throw new InvalidArgumentException('A chave da API do TMDB é inválida.');
    }
    setAdSetting($db, 'tmdb_api_key', $apiKey);
    setAdSetting($db, 'tmdb_ads_cache', '');
    setAdSetting($db, 'tmdb_ads_cache_time', '0');
}

function getTmdbAds(PDO $db): array
{
    $cache = getAdSetting($db, 'tmdb_ads_cache');
    $cachedAt = (int) getAdSetting($db, 'tmdb_ads_cache_time');
    $cachedAds = $cache === '' ? null : json_decode($cache, true);
    if ($cache !== '' && $cachedAt > 0 && time() - $cachedAt < 21600) {
        if (is_array($cachedAds)) {
            logAdApiEvent('tmdb_ads_cache_hit', ['item_count' => count($cachedAds)]);
            return $cachedAds;
        }
    }

    logAdApiEvent('tmdb_ads_cache_refresh_started');
    try {
        $ads = refreshTmdbAds($db);
        logAdApiEvent('tmdb_ads_cache_refresh_completed', ['item_count' => count($ads)]);
        return $ads;
    } catch (Throwable $exception) {
        if (is_array($cachedAds) && $cachedAds !== []) {
            error_log('TMDB refresh failed; serving cached ad images: ' . $exception->getMessage());
            logAdApiEvent('tmdb_ads_cache_refresh_failed_using_cache', [
                'exception' => get_class($exception),
                'cached_item_count' => count($cachedAds),
            ]);
            return $cachedAds;
        }
        logAdApiEvent('tmdb_ads_cache_refresh_failed', [
            'exception' => get_class($exception),
        ]);
        throw $exception;
    }
}

function refreshTmdbAds(PDO $db): array
{
    $apiKey = getTmdbApiKey($db);
    if ($apiKey === '') {
        throw new RuntimeException('Configure a chave da API do TMDB no painel administrativo.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
    }

    $movies = fetchTmdbAds('movie/now_playing', $apiKey, 'movie');
    $series = fetchTmdbAds('tv/on_the_air', $apiKey, 'tv');
    $ads = [];
    $maxItems = max(count($movies), count($series));
    for ($index = 0; $index < $maxItems && count($ads) < 20; $index++) {
        if (isset($movies[$index])) {
            $ads[] = $movies[$index];
        }
        if (isset($series[$index]) && count($ads) < 20) {
            $ads[] = $series[$index];
        }
    }

    if ($ads === []) {
        throw new RuntimeException('O TMDB não retornou lançamentos com imagens disponíveis.');
    }

    setAdSetting($db, 'tmdb_ads_cache', json_encode($ads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    setAdSetting($db, 'tmdb_ads_cache_time', (string) time());
    return $ads;
}

function fetchTmdbAds(string $endpoint, string $apiKey, string $mediaType): array
{
    logAdApiEvent('tmdb_request_started', [
        'media_type' => $mediaType,
        'endpoint' => $endpoint,
    ]);
    $url = 'https://api.themoviedb.org/3/' . $endpoint . '?' . http_build_query([
        'api_key' => $apiKey,
        'language' => 'pt-BR',
        'region' => 'BR',
        'page' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($body)) {
        logAdApiEvent('tmdb_request_failed', [
            'media_type' => $mediaType,
            'endpoint' => $endpoint,
            'failure' => 'curl_error',
        ]);
        throw new RuntimeException('Não foi possível consultar os lançamentos no TMDB: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        logAdApiEvent('tmdb_request_failed', [
            'media_type' => $mediaType,
            'endpoint' => $endpoint,
            'http_status' => $status,
        ]);
        throw new RuntimeException('O TMDB respondeu com HTTP ' . $status . '.');
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($payload['results']) || !is_array($payload['results'])) {
        throw new RuntimeException('A resposta de lançamentos do TMDB está em formato inválido.');
    }

    $ads = [];
    foreach ($payload['results'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $imagePath = (string) ($item['backdrop_path'] ?? $item['poster_path'] ?? '');
        if ($imagePath === '' || $imagePath[0] !== '/') {
            continue;
        }
        $releaseDate = (string) ($item[$mediaType === 'movie' ? 'release_date' : 'first_air_date'] ?? '');
        $ads[] = [
            'title' => (string) ($item[$mediaType === 'movie' ? 'title' : 'name'] ?? 'Lançamento'),
            'image' => 'https://image.tmdb.org/t/p/original' . $imagePath,
            'overview' => (string) ($item['overview'] ?? ''),
            'year' => preg_match('/^\d{4}/', $releaseDate, $matches) === 1 ? $matches[0] : '',
            'type' => $mediaType === 'movie' ? 'Filme' : 'Série',
        ];
    }

    logAdApiEvent('tmdb_request_completed', [
        'media_type' => $mediaType,
        'endpoint' => $endpoint,
        'http_status' => $status,
        'item_count' => count($ads),
    ]);
    return $ads;
}

function getAdDisplayUrl(?string $mac = null): string
{
    $url = getPublicBaseUrl() . '/api/allads.php';
    if ($mac !== null && $mac !== '') {
        $url .= '?' . http_build_query(['mac' => $mac], '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function getAdApiResponse(PDO $db, ?int $accountId, ?string $mac = null): array
{
    $mode = getAdSourceMode($db, $accountId);
    $displayUrl = getAdDisplayUrl($mac);
    if ($mode === 'tmdb') {
        $items = [];
        foreach (getTmdbAds($db) as $ad) {
            $imageUrl = trim((string) ($ad['image'] ?? ''));
            if ($imageUrl === '') {
                continue;
            }
            $items[] = [
                'AdName' => (string) ($ad['title'] ?? 'Lançamento'),
                'AdUrl' => $displayUrl,
                'AdImage' => $imageUrl,
                'AdDisplayUrl' => $displayUrl,
                'AdType' => (string) ($ad['type'] ?? ''),
                'AdYear' => (string) ($ad['year'] ?? ''),
                'AdDescription' => (string) ($ad['overview'] ?? ''),
            ];
        }
        logAdApiEvent('ads_response_built', [
            'source' => $mode,
            'item_count' => count($items),
        ]);
        return $items;
    }

    $items = [];
    foreach (getAds($db, $accountId) as $ad) {
        $imageUrl = trim((string) ($ad['url'] ?? ''));
        if (filter_var($imageUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            continue;
        }
        $items[] = [
            'AdName' => (string) ($ad['title'] ?? 'Anúncio'),
            'AdUrl' => $displayUrl,
            'AdImage' => $imageUrl,
            'AdDisplayUrl' => $displayUrl,
        ];
    }

    logAdApiEvent('ads_response_built', [
        'source' => $mode,
        'item_count' => count($items),
    ]);
    return $items;
}
