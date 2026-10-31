<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/ads.php';

$db = getDB();
$error = '';
$success = '';
$resellerId = (int) $reseller['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($action === 'save_mode') {
                $mode = (string) ($_POST['ads_mode'] ?? '');
                if (!in_array($mode, ['inherit', 'manual', 'tmdb'], true)) {
                    throw new InvalidArgumentException('Selecione uma fonte de anúncios válida.');
                }
                if ($mode === 'inherit') {
                    clearAccountAdSourceMode($db, $resellerId);
                    $success = 'A fonte dos anúncios agora segue a configuração do administrador.';
                } else {
                    if ($mode === 'tmdb') {
                        if (getTmdbApiKey($db) === '') {
                            throw new InvalidArgumentException('O administrador precisa configurar a chave da API do TMDB.');
                        }
                        getTmdbAds($db);
                    }
                    setAdSourceMode($db, $mode, $resellerId);
                    $success = $mode === 'tmdb'
                        ? 'Modo automático ativado para os dispositivos da sua conta.'
                        : 'Modo manual ativado para os dispositivos da sua conta.';
                }
            } elseif ($action === 'create' || $action === 'update') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $url = trim((string) ($_POST['url'] ?? ''));
                if ($title === '' || strlen($title) > 255 || $url === '') {
                    throw new InvalidArgumentException('Informe um título e uma URL válida.');
                }
                if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Informe uma URL HTTP ou HTTPS válida.');
                }

                if ($action === 'create') {
                    createAd($db, $title, $url, $resellerId);
                    $success = 'Anúncio adicionado à sua conta.';
                } elseif ($id !== false && $id !== null && $id > 0) {
                    updateAd($db, $id, $title, $url, $resellerId);
                    $success = 'Anúncio atualizado.';
                } else {
                    throw new InvalidArgumentException('Anúncio inválido.');
                }
            } elseif ($action === 'delete' && $id !== false && $id !== null && $id > 0) {
                deleteAd($db, $id, $resellerId);
                $success = 'Anúncio removido.';
            } else {
                throw new InvalidArgumentException('Ação ou anúncio inválido.');
            }
            $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Reseller ad operation failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Não foi possível salvar o anúncio.';
        }
    }
}

$ads = getAds($db, $resellerId);
$adsModeOverride = getAccountAdSourceMode($db, $resellerId);
$adsMode = getAdSourceMode($db, $resellerId);
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meus anúncios | Área da revenda</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 1100px; margin: auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { margin-bottom: 18px; padding: 20px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        p { color: #475467; line-height: 1.55; }
        label { display: block; margin: 12px 0; color: #475467; }
        input { display: block; width: 100%; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        .message { padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .ad { padding: 14px 0; border-top: 1px solid #eaecf0; }
        .ad:first-child { border-top: 0; }
        .ad form { margin-top: 8px; }
        .ad-preview { display: block; width: min(100%, 360px); max-height: 200px; border: 1px solid #eaecf0; border-radius: 6px; object-fit: contain; }
        .empty { color: #667085; }
        @media (max-width: 560px) { body { padding: 18px 10px; } header { align-items: flex-start; flex-direction: column; } .panel { padding: 15px; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css?v=reseller-ads-compact-20261025-1">
</head>
<body class="reseller-page reseller-ads-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="ads-heading">
        <h1>Meus anúncios</h1>
        <div class="ads-header-actions">
            <a class="button" href="credits.php">Comprar créditos</a>
            <a href="index.php">Voltar ao painel</a>
        </div>
    </header>
    <?php if ($error !== ''): ?><p class="message error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p class="message success" role="status"><?= $escape($success) ?></p><?php endif; ?>
    <section class="panel">
        <h2>Exibição atual</h2>
        <p>Esta escolha vale para os dispositivos da sua conta e aparece em telas de anúncios abertas em até 10 segundos. Sub-revendas podem definir sua própria fonte; sem uma escolha própria, seguem a fonte do administrador.</p>
        <p>Fonte efetiva: <strong><?= $adsMode === 'tmdb' ? 'Automático — lançamentos de filmes e séries' : 'Manual — banners cadastrados' ?></strong><?= $adsModeOverride === null ? ' (seguindo o administrador)' : ' (definida por esta conta)' ?>.</p>
        <form class="ads-mode-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
            <input type="hidden" name="action" value="save_mode">
            <label>Fonte dos anúncios
                <select name="ads_mode">
                    <option value="inherit" <?= $adsModeOverride === null ? 'selected' : '' ?>>Seguir configuração do administrador</option>
                    <option value="manual" <?= $adsModeOverride === 'manual' ? 'selected' : '' ?>>Manual — meus banners</option>
                    <option value="tmdb" <?= $adsModeOverride === 'tmdb' ? 'selected' : '' ?>>Automático — lançamentos de filmes e séries</option>
                </select>
            </label>
            <button type="submit">Salvar fonte</button>
        </form>
    </section>
    <section class="panel">
        <h2>Adicionar anúncio à sua conta</h2>
        <p>Estes banners são exibidos somente nos dispositivos vinculados à sua revenda ou sub-revenda quando o modo manual está ativo. Use uma imagem horizontal pública de <strong>1920 × 1080 px (16:9)</strong>, preferencialmente JPG ou WebP com até 2 MB; PNG também é aceito. Cole o link direto do arquivo de imagem, não o endereço de uma página ou de um modelo do Canva.</p>
        <form class="ads-create-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
            <input type="hidden" name="action" value="create">
            <label>Título<input name="title" maxlength="255" required></label>
            <label>Link direto da imagem<input name="url" type="url" maxlength="2048" placeholder="https://site.com/imagens/banner.jpg" required></label>
            <button type="submit">Adicionar anúncio</button>
        </form>
    </section>
    <section class="panel">
        <h2>Anúncios da minha conta</h2>
        <?php if ($ads === []): ?>
            <p class="empty">Você ainda não cadastrou anúncios. Os anúncios do administrador não são alterados.</p>
        <?php else: ?>
            <div class="ads-list">
            <?php foreach ($ads as $ad): ?>
            <article class="ad">
                <div class="ad-preview-wrap">
                    <img class="ad-preview" src="<?= $escape($ad['url'] ?? '') ?>" alt="Prévia: <?= $escape($ad['title'] ?? 'anúncio') ?>" loading="lazy" referrerpolicy="no-referrer">
                    <span class="ad-preview-error">A imagem não carregou. Confira se o endereço aponta diretamente para um arquivo público.</span>
                </div>
                <form class="ad-edit-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                    <label>Título<input name="title" maxlength="255" value="<?= $escape($ad['title'] ?? '') ?>" required></label>
                    <label>Link direto da imagem<input name="url" type="url" maxlength="2048" value="<?= $escape($ad['url'] ?? '') ?>" required></label>
                    <button type="submit">Salvar alterações</button>
                </form>
                <form class="ad-delete-form" method="post" onsubmit="return confirm('Remover este anúncio da sua conta?');">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                    <button class="danger" type="submit">Excluir anúncio</button>
                </form>
            </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
    document.querySelectorAll('.ad-preview').forEach(function (image) {
        image.addEventListener('error', function () {
            image.closest('.ad').classList.add('is-image-broken');
        });
    });
</script>
</body>
</html>
