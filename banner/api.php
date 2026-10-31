<?php
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';
require_once __DIR__ . '/../api/includes/ads.php';

function bannerTmdbRequest(string $endpoint, array $parameters, string $apiKey, string $language = 'pt-BR'): array
{
    $url = 'https://api.themoviedb.org/3/' . $endpoint . '?' . http_build_query(
        array_merge($parameters, [
            'api_key' => $apiKey,
            'language' => $language,
        ]),
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($body)) {
        throw new RuntimeException('Não foi possível consultar os lançamentos semanais do TMDB: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('O TMDB respondeu com HTTP ' . $status . ' ao buscar lançamentos semanais.');
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('O TMDB retornou uma resposta inválida.');
    }
    return $payload;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = getDB();
$accountId = null;

$sessionAdminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!empty($_SESSION['logged_in']) && $sessionAdminId !== false && $sessionAdminId !== null) {
    $adminCheck = $db->prepare('SELECT id FROM users WHERE id = ? AND COALESCE(admin, 0) = 1');
    $adminCheck->execute([$sessionAdminId]);
    if ($adminCheck->fetchColumn() !== false) {
        $accountId = (int) $sessionAdminId;
    }
}

if ($accountId === null) {
    $sessionResellerId = filter_var($_SESSION['reseller_user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($sessionResellerId !== false && $sessionResellerId !== null) {
        $resellerCheck = $db->prepare('SELECT id FROM users WHERE id = ? AND COALESCE(admin, 0) <> 1');
        $resellerCheck->execute([$sessionResellerId]);
        if ($resellerCheck->fetchColumn() !== false) {
            $accountId = (int) $sessionResellerId;
        }
    }
}

if ($accountId === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Entre na sua conta para usar o gerador.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['image'])) {
    $imageUrl = filter_var($_GET['image'], FILTER_VALIDATE_URL);
    $parts = is_string($imageUrl) ? parse_url($imageUrl) : false;
    $imageHost = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $imagePath = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $isTmdbImage = $imageHost === 'image.tmdb.org' && str_starts_with($imagePath, '/t/p/');
    $isTeamLogo = $imageHost === 'media.api-sports.io'
        && preg_match('~^/football/teams/[0-9]+\.png$~D', $imagePath) === 1;
    $isLeagueLogo = $imageHost === 'media.api-sports.io'
        && preg_match('~^/football/leagues/[0-9]+\.png$~D', $imagePath) === 1;
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || (!$isTmdbImage && !$isTeamLogo && !$isLeagueLogo)
        || array_key_exists('user', $parts)
        || array_key_exists('pass', $parts)
        || array_key_exists('query', $parts)
        || array_key_exists('fragment', $parts)) {
        http_response_code(400);
        exit('Imagem inválida.');
    }
    if (!function_exists('curl_init')) {
        http_response_code(503);
        exit('A extensão cURL do PHP não está habilitada.');
    }

    $handle = curl_init($imageUrl);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg'],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($handle);

    if (!is_string($body) || $status < 200 || $status >= 300
        || strlen($body) > 5 * 1024 * 1024
        || !in_array($contentType, ['image/avif', 'image/webp', 'image/png', 'image/jpeg'], true)) {
        http_response_code(502);
        exit('Não foi possível carregar a imagem do catálogo.');
    }

    header('Content-Type: ' . $contentType);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string) ($_POST['action'] ?? ''), ['save_background', 'remove_background'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken)
        || empty($_SESSION['banner_background_csrf'])
        || !hash_equals($_SESSION['banner_background_csrf'], $csrfToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Sessão expirada. Atualize a página e tente novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($_POST['action'] === 'save_background') {
            $backgroundPath = saveAccountBackground($_FILES['background_image'] ?? [], $accountId);
            $saveBackground = $db->prepare('UPDATE users SET banner_background_image = ? WHERE id = ?');
            $saveBackground->execute([$backgroundPath, $accountId]);
        } else {
            $removeBackground = $db->prepare('UPDATE users SET banner_background_image = NULL WHERE id = ?');
            $removeBackground->execute([$accountId]);
        }

        $_SESSION['banner_background_csrf'] = bin2hex(random_bytes(32));
        echo json_encode([
            'background' => getAccountBannerBackgroundUrl($db, $accountId),
            'csrfToken' => $_SESSION['banner_background_csrf'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Banner background update failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o fundo do banner.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$input = json_decode((string) file_get_contents('php://input'), true);
$action = is_array($input) ? (string) ($input['action'] ?? '') : '';

try {
    if ($action === 'football') {
        $date = (string) ($input['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('Selecione uma data válida.');
        }

        $cacheKey = 'banner_generator_fixtures_v6_' . $date;
        $cacheTimeKey = $cacheKey . '_time';
        $cacheStatement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $cacheStatement->execute([$cacheKey]);
        $cachedJson = (string) ($cacheStatement->fetchColumn() ?: '');
        $cacheStatement->execute([$cacheTimeKey]);
        $cachedAt = (int) ($cacheStatement->fetchColumn() ?: 0);
        $fixtures = $cachedJson !== '' && $cachedAt > 0 && time() - $cachedAt < 300
            ? json_decode($cachedJson, true, 512, JSON_THROW_ON_ERROR)
            : null;

        if (!is_array($fixtures)) {
            $apiKey = trim((string) (getenv('API_SPORTS_KEY') ?: ''));
            if ($apiKey === '') {
                $keyQuery = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'api_sports_key'");
                $keyQuery->execute();
                $apiKey = trim((string) ($keyQuery->fetchColumn() ?: ''));
            }
            if ($apiKey === '') {
                throw new RuntimeException('A chave da API-Sports ainda não foi configurada pelo administrador.');
            }
            if (!function_exists('curl_init')) {
                throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
            }

            $timezone = new DateTimeZone('America/Sao_Paulo');
            $fixtures = [];
            $page = 1;
            $totalPages = 1;
            while ($page <= $totalPages) {
                $parameters = [
                    'date' => $date,
                    'timezone' => $timezone->getName(),
                ];
                if ($page > 1) {
                    $parameters['page'] = $page;
                }
                $query = http_build_query($parameters);
                $handle = curl_init('https://v3.football.api-sports.io/fixtures?' . $query);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER => ['x-apisports-key: ' . $apiKey, 'Accept: application/json'],
                ]);
                $body = curl_exec($handle);
                $curlError = curl_error($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                curl_close($handle);

                if (!is_string($body)) {
                    throw new RuntimeException('Não foi possível consultar a API-Sports: ' . $curlError);
                }
                if ($status < 200 || $status >= 300) {
                    throw new RuntimeException('A API-Sports respondeu com HTTP ' . $status . ' ao buscar a página ' . $page . '.');
                }

                $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('A API-Sports retornou uma resposta inválida na página ' . $page . '.');
                }
                if (!empty($payload['errors'])) {
                    $apiErrors = is_array($payload['errors'])
                        ? array_filter(array_map('strval', array_values($payload['errors'])))
                        : [(string) $payload['errors']];
                    throw new RuntimeException(
                        'A API-Sports não conseguiu carregar a página ' . $page . ': '
                        . implode('; ', $apiErrors)
                    );
                }
                if (!isset($payload['response']) || !is_array($payload['response'])) {
                    throw new RuntimeException('A API-Sports retornou uma resposta inválida na página ' . $page . '.');
                }
                if ($page === 1 && isset($payload['paging']['total']) && is_numeric($payload['paging']['total'])) {
                    $totalPages = max(1, (int) $payload['paging']['total']);
                }

                foreach ($payload['response'] as $fixture) {
                    if (!is_array($fixture)) {
                        continue;
                    }
                    $leagueCountry = trim((string) ($fixture['league']['country'] ?? ''));
                    if (strcasecmp($leagueCountry, 'Brazil') !== 0) {
                        continue;
                    }
                    $home = trim((string) ($fixture['teams']['home']['name'] ?? ''));
                    $away = trim((string) ($fixture['teams']['away']['name'] ?? ''));
                    if ($home === '' || $away === '') {
                        continue;
                    }
                    $kickoff = null;
                    try {
                        if (!empty($fixture['fixture']['date'])) {
                            $kickoff = (new DateTimeImmutable((string) $fixture['fixture']['date']))
                                ->setTimezone($timezone)
                                ->format('H:i');
                        }
                    } catch (Throwable) {
                        $kickoff = null;
                    }
                    $fixtures[] = [
                        'id' => (int) ($fixture['fixture']['id'] ?? count($fixtures) + 1),
                        'league' => trim((string) ($fixture['league']['name'] ?? '')),
                        'league_logo' => trim((string) ($fixture['league']['logo'] ?? '')),
                        'home' => $home,
                        'away' => $away,
                        'home_logo' => trim((string) ($fixture['teams']['home']['logo'] ?? '')),
                        'away_logo' => trim((string) ($fixture['teams']['away']['logo'] ?? '')),
                        'time' => $kickoff ?? '--:--',
                        'status' => trim((string) ($fixture['fixture']['status']['short'] ?? 'NS')),
                        'home_score' => $fixture['goals']['home'] ?? null,
                        'away_score' => $fixture['goals']['away'] ?? null,
                    ];
                }
                $page++;
            }
            $saveCache = $db->prepare('
                INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
            ');
            $saveCache->execute([$cacheKey, json_encode($fixtures, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $saveCache->execute([$cacheTimeKey, (string) time()]);
        }

        foreach ($fixtures as &$fixture) {
            foreach (['league_logo', 'home_logo', 'away_logo'] as $logoKey) {
                $logoUrl = trim((string) ($fixture[$logoKey] ?? ''));
                $logoParts = filter_var($logoUrl, FILTER_VALIDATE_URL) ? parse_url($logoUrl) : false;
                $logoPath = is_array($logoParts) ? (string) ($logoParts['path'] ?? '') : '';
                $logoHost = is_array($logoParts) ? strtolower((string) ($logoParts['host'] ?? '')) : '';
                $isValidSportsLogo = $logoHost === 'media.api-sports.io'
                    && preg_match('~^/football/(?:teams|leagues)/[0-9]+\.png$~D', $logoPath) === 1;
                if (is_array($logoParts)
                    && strtolower((string) ($logoParts['scheme'] ?? '')) === 'https'
                    && $isValidSportsLogo
                    && !isset($logoParts['user'], $logoParts['pass'], $logoParts['query'], $logoParts['fragment'])) {
                    $fixture[$logoKey] = getPublicBaseUrl() . '/banner/api.php?' . http_build_query(
                        ['image' => $logoUrl],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                } else {
                    $fixture[$logoKey] = '';
                }
            }
        }
        unset($fixture);

        echo json_encode([
            'fixtures' => $fixtures,
            'date' => $date,
            'logo' => getAccountLogoUrl($db, $accountId),
            'account_name' => $isAdmin ? 'Administrador' : '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'catalog') {
        $mediaType = (string) ($input['type'] ?? '');
        if (!in_array($mediaType, ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('Selecione filmes ou séries.');
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $today = new DateTimeImmutable('today', $timezone);
        $daysSinceMonday = (int) $today->format('N') - 1;
        $weekStart = $today->modify('-' . $daysSinceMonday . ' days');
        $weekEnd = $weekStart->modify('+6 days');
        $startDate = $weekStart->format('Y-m-d');
        $endDate = $weekEnd->format('Y-m-d');
        $cacheKey = 'banner_weekly_tmdb_v4_' . $mediaType . '_' . $startDate;
        $cacheTimeKey = $cacheKey . '_time';
        $cacheStatement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $cacheStatement->execute([$cacheKey]);
        $cachedJson = (string) ($cacheStatement->fetchColumn() ?: '');
        $cacheStatement->execute([$cacheTimeKey]);
        $cachedAt = (int) ($cacheStatement->fetchColumn() ?: 0);
        $items = $cachedJson !== '' && $cachedAt > 0 && time() - $cachedAt < 21600
            ? json_decode($cachedJson, true, 512, JSON_THROW_ON_ERROR)
            : null;

        if (!is_array($items)) {
            $apiKey = getTmdbApiKey($db);
            if ($apiKey === '') {
                throw new RuntimeException('Configure a chave do TMDB no painel para buscar os lançamentos da semana.');
            }
            if (!function_exists('curl_init')) {
                throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
            }

            $isMovie = $mediaType === 'movie';
            $endpoint = $isMovie ? 'discover/movie' : 'discover/tv';
            $dateField = $isMovie ? 'primary_release_date' : 'first_air_date';
            $tmdbParameters = [
                $dateField . '.gte' => $startDate,
                $dateField . '.lte' => $endDate,
                'sort_by' => $dateField . '.asc',
                'region' => 'BR',
                'page' => 1,
                'include_adult' => 'false',
            ];
            if ($isMovie) {
                $tmdbParameters['with_release_type'] = '2|3';
            }
            $payload = bannerTmdbRequest($endpoint, $tmdbParameters, $apiKey);
            if (!isset($payload['results']) || !is_array($payload['results'])) {
                throw new RuntimeException('O TMDB não retornou os dados esperados dos lançamentos semanais.');
            }
            $genrePayload = bannerTmdbRequest('genre/' . $mediaType . '/list', [], $apiKey);
            if (!isset($genrePayload['genres']) || !is_array($genrePayload['genres'])) {
                throw new RuntimeException('O TMDB não retornou os gêneros dos lançamentos.');
            }
            $genreNames = [];
            foreach ($genrePayload['genres'] as $genre) {
                if (is_array($genre) && isset($genre['id'], $genre['name'])) {
                    $genreNames[(int) $genre['id']] = trim((string) $genre['name']);
                }
            }
            $needsEnglishOverview = false;
            foreach ($payload['results'] as $item) {
                if (is_array($item) && trim((string) ($item['overview'] ?? '')) === '') {
                    $needsEnglishOverview = true;
                    break;
                }
            }
            if ($needsEnglishOverview) {
                $englishPayload = bannerTmdbRequest($endpoint, $tmdbParameters, $apiKey, 'en-US');
                if (!isset($englishPayload['results']) || !is_array($englishPayload['results'])) {
                    throw new RuntimeException('O TMDB não retornou as sinopses alternativas esperadas.');
                }
                $englishOverviews = [];
                foreach ($englishPayload['results'] as $englishItem) {
                    if (is_array($englishItem) && isset($englishItem['id'])) {
                        $englishOverviews[(int) $englishItem['id']] = trim((string) ($englishItem['overview'] ?? ''));
                    }
                }
                foreach ($payload['results'] as &$item) {
                    if (!is_array($item) || trim((string) ($item['overview'] ?? '')) !== '') {
                        continue;
                    }
                    $item['overview'] = $englishOverviews[(int) ($item['id'] ?? 0)] ?? '';
                    if ($item['overview'] !== '') {
                        $item['overview_language'] = 'en-US';
                    }
                }
                unset($item);
            }

            $items = [];
            foreach ($payload['results'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $releaseDate = trim((string) ($item[$isMovie ? 'release_date' : 'first_air_date'] ?? ''));
                if ($releaseDate < $startDate || $releaseDate > $endDate
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate) !== 1) {
                    continue;
                }
                $posterPath = trim((string) ($item['poster_path'] ?? ''));
                if ($posterPath === '' || !str_starts_with($posterPath, '/')) {
                    continue;
                }
                $overview = trim((string) ($item['overview'] ?? ''));
                if ($overview === '') {
                    continue;
                }
                $genres = [];
                foreach ((array) ($item['genre_ids'] ?? []) as $genreId) {
                    $genreName = $genreNames[(int) $genreId] ?? '';
                    if ($genreName !== '') {
                        $genres[] = $genreName;
                    }
                }
                $backdropPath = trim((string) ($item['backdrop_path'] ?? ''));
                $items[] = [
                    'id' => (int) ($item['id'] ?? 0),
                    'title' => trim((string) ($item[$isMovie ? 'title' : 'name'] ?? 'Lançamento')),
                    'year' => substr($releaseDate, 0, 4),
                    'release_date' => $releaseDate,
                    'overview' => $overview,
                    'overview_language' => (string) ($item['overview_language'] ?? 'pt-BR'),
                    'genres' => array_slice($genres, 0, 3),
                    'poster' => getPublicBaseUrl() . '/banner/api.php?' . http_build_query(
                        ['image' => 'https://image.tmdb.org/t/p/w500' . $posterPath],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    ),
                    'backdrop' => $backdropPath !== '' && str_starts_with($backdropPath, '/')
                        ? getPublicBaseUrl() . '/banner/api.php?' . http_build_query(
                            ['image' => 'https://image.tmdb.org/t/p/w1280' . $backdropPath],
                            '',
                            '&',
                            PHP_QUERY_RFC3986
                        )
                        : '',
                    'type' => $mediaType,
                ];
            }
            $saveCache = $db->prepare('
                INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
            ');
            $saveCache->execute([$cacheKey, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $saveCache->execute([$cacheTimeKey, (string) time()]);
        }

        $items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && trim((string) ($item['overview'] ?? '')) !== ''
                && trim((string) ($item['poster'] ?? '')) !== '';
        }));
        foreach ($items as &$item) {
            $item['image'] = $item['poster'];
        }
        unset($item);

        echo json_encode([
            'items' => $items,
            'logo' => getAccountLogoUrl($db, $accountId),
            'type' => $mediaType,
            'week_start' => $startDate,
            'week_end' => $endDate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(422);
    echo json_encode(['error' => 'Ação de carregamento inválida.'], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Banner generator data load failed: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
