<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ads.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$db = getDB();
$macParameter = $_GET['mac'] ?? '';
if (!is_string($macParameter)) {
    http_response_code(400);
    apiLog('ad_display_rejected', ['reason' => 'invalid_mac_type']);
    $items = [];
    $errorMessage = 'Endereço do dispositivo inválido.';
} else {
    $mac = trim($macParameter);
    $device = null;
    if ($mac !== '') {
        $normalizedMac = normalizeDeviceMac($mac);
        if ($normalizedMac === null) {
            http_response_code(400);
            apiLog('ad_display_rejected', ['reason' => 'invalid_mac']);
            $errorMessage = 'Endereço do dispositivo inválido.';
        } else {
            $device = findDeviceByMac($db, $normalizedMac);
            if ($device === null) {
                http_response_code(404);
                apiLog('ad_display_rejected', [
                    'reason' => 'device_not_found',
                    'mac' => apiMaskedMac($normalizedMac),
                ]);
                $errorMessage = 'Dispositivo não encontrado.';
            }
        }
    }
}

$items = [];
$sourceMode = 'manual';
if (!isset($errorMessage)) {
    try {
        $accountId = isset($device['id_user']) && (int) $device['id_user'] > 0
            ? (int) $device['id_user']
            : null;
        $sourceMode = getAdSourceMode($db, $accountId);
        if ($sourceMode === 'tmdb') {
            $items = getTmdbAds($db);
        } else {
            foreach (getAds($db, $accountId) as $ad) {
                $url = trim((string) ($ad['url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    continue;
                }
                $items[] = [
                    'title' => (string) ($ad['title'] ?? ''),
                    'image' => $url,
                    'overview' => '',
                    'year' => '',
                    'type' => 'Anúncio',
                ];
            }
        }
        apiLog('ad_display_items_ready', [
            'source' => $sourceMode,
            'item_count' => count($items),
            'item_titles' => implode(' | ', array_map(
                static fn(array $item): string => (string) ($item['title'] ?? ''),
                array_slice($items, 0, 20)
            )),
            'rotation_interval_seconds' => 7,
            'mac' => apiMaskedMac($mac ?? null),
        ]);
    } catch (Throwable $exception) {
        error_log('Ad display failed: ' . $exception->getMessage());
        http_response_code(503);
        apiLog('ad_display_failed', ['exception' => get_class($exception)]);
        $errorMessage = 'Não foi possível carregar as imagens dos anúncios. Tente novamente mais tarde.';
    }
}

$encodedItems = json_encode(
    $items,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$itemsVersion = hash(
    'sha256',
    json_encode(
        ['source' => $sourceMode, 'items' => $items],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    )
);
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if (isset($errorMessage)) {
        echo json_encode(['error' => $errorMessage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode([
            'source' => $sourceMode,
            'version' => $itemsVersion,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    exit;
}
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anúncios</title>
    <style>
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; background: #101828; color: #fff; font: 16px Arial, sans-serif; }
        #display { position: relative; width: 100%; height: 100%; display: grid; place-items: center; }
        #image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        #image[hidden], #shade[hidden], #caption[hidden], #message[hidden] { display: none; }
        #shade { position: absolute; inset: 35% 0 0; background: linear-gradient(transparent, rgba(0, 0, 0, .86)); }
        #caption { position: absolute; right: 5%; bottom: 6%; left: 5%; text-shadow: 0 2px 8px #000; }
        #title { margin: 0 0 8px; font-size: clamp(22px, 4vw, 42px); }
        #meta { margin-bottom: 8px; color: #facc15; font-weight: 700; }
        #overview { max-width: 900px; margin: 0; font-size: clamp(13px, 1.5vw, 18px); line-height: 1.45; }
        #message { position: relative; padding: 24px; text-align: center; color: #e4e7ec; }
        @media (max-width: 600px) { #caption { right: 4%; bottom: 4%; left: 4%; } }
    </style>
</head>
<body>
<main id="display">
    <img id="image" alt="" hidden>
    <div id="shade" aria-hidden="true" hidden></div>
    <section id="caption" hidden>
        <div id="meta"></div>
        <h1 id="title"></h1>
        <p id="overview"></p>
    </section>
    <p id="message" role="status"><?= $escape($errorMessage ?? 'Nenhum anúncio ou lançamento disponível no momento.') ?></p>
    <script>
        let items = <?= $encodedItems ?>;
        let currentIndex = 0;
        let version = <?= json_encode($itemsVersion, JSON_THROW_ON_ERROR) ?>;
        const image = document.getElementById('image');
        const shade = document.getElementById('shade');
        const caption = document.getElementById('caption');
        const message = document.getElementById('message');
        const title = document.getElementById('title');
        const meta = document.getElementById('meta');
        const overview = document.getElementById('overview');

        image.addEventListener('error', () => {
            title.textContent = 'Imagem indisponível';
            meta.textContent = 'Confira a URL cadastrada';
            overview.textContent = '';
        });

        function showNext() {
            if (items.length === 0) {
                image.hidden = true;
                shade.hidden = true;
                caption.hidden = true;
                message.hidden = false;
                message.textContent = 'Nenhum anúncio ou lançamento disponível no momento.';
                return;
            }
            const item = items[currentIndex];
            image.src = item.image;
            image.alt = item.title;
            title.textContent = item.title;
            meta.textContent = [item.type, item.year].filter(Boolean).join(' · ');
            overview.textContent = item.overview;
            image.hidden = false;
            shade.hidden = false;
            caption.hidden = false;
            message.hidden = true;
            currentIndex = (currentIndex + 1) % items.length;
        }

        async function refreshAds() {
            try {
                const refreshUrl = new URL(window.location.href);
                refreshUrl.searchParams.set('format', 'json');
                refreshUrl.searchParams.set('_', String(Date.now()));
                const response = await fetch(refreshUrl, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                const payload = await response.json();
                if (Array.isArray(payload.items) && payload.version !== version) {
                    items = payload.items;
                    version = payload.version;
                    currentIndex = 0;
                    showNext();
                }
            } catch (error) {
                console.error('Não foi possível atualizar os anúncios:', error);
            }
        }

        showNext();
        window.setInterval(showNext, 7000);
        window.setInterval(refreshAds, 10000);
    </script>
</main>
</body>
</html>
