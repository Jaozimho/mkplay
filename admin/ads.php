<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/ads.php';
$db = getDB();

if (empty($_SESSION['ads_csrf'])) {
    $_SESSION['ads_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['ads_mode_csrf'])) {
    $_SESSION['ads_mode_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $tokenName = $action === 'save_mode' ? 'ads_mode_csrf' : 'ads_csrf';
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION[$tokenName], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($action === 'save_mode') {
        $mode = $_POST['ads_mode'] ?? '';
        $apiKey = $_POST['tmdb_api_key'] ?? '';
        try {
            if (!is_string($mode) || !in_array($mode, ['manual', 'tmdb'], true)) {
                throw new InvalidArgumentException('Selecione uma fonte de anúncios válida.');
            }
            if (!is_string($apiKey)) {
                throw new InvalidArgumentException('A chave da API do TMDB é inválida.');
            }
            if (trim($apiKey) !== '') {
                saveTmdbApiKey($db, trim($apiKey));
            }
            if ($mode === 'tmdb') {
                if (getTmdbApiKey($db) === '') {
                    throw new InvalidArgumentException('Informe uma chave da API do TMDB ou configure TMDB_API_KEY no servidor.');
                }
                getTmdbAds($db);
            }
            setAdSourceMode($db, $mode);
            $_SESSION['ads_mode_csrf'] = bin2hex(random_bytes(32));
            $success = $mode === 'tmdb'
                ? 'Modo automático ativado. O aplicativo exibirá lançamentos de filmes e séries do TMDB.'
                : 'Modo manual ativado. O aplicativo exibirá os anúncios cadastrados.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Admin ad mode update failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = $exception->getMessage();
        }
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if ($action === 'create' || $action === 'update') {
                $title = trim($_POST['title'] ?? '');
                $url = trim($_POST['url'] ?? '');
                if ($title === '' || $url === '') {
                    throw new InvalidArgumentException('Informe o título e a URL.');
                }
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Informe uma URL HTTP ou HTTPS válida.');
                }

                if ($action === 'create') {
                    createAd($db, $title, $url, (int) $_SESSION['user_id']);
                    $success = 'Anúncio adicionado.';
                } elseif ($id !== false && $id !== null && $id > 0) {
                    updateAd($db, $id, $title, $url, (int) $_SESSION['user_id']);
                    $success = 'Anúncio atualizado.';
                } else {
                    throw new InvalidArgumentException('Anúncio inválido.');
                }
            } elseif ($action === 'delete' && $id !== false && $id !== null && $id > 0) {
                deleteAd($db, $id, (int) $_SESSION['user_id']);
                $success = 'Anúncio excluído.';
            } else {
                throw new InvalidArgumentException('Ação ou anúncio inválido.');
            }

            $_SESSION['ads_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            http_response_code(500);
            $error = 'Não foi possível salvar o anúncio no banco.';
        }
    }
}

$ads = getAds($db, (int) $_SESSION['user_id']);
$adsMode = getAdSourceMode($db);
$tmdbKeyConfigured = getTmdbApiKey($db) !== '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anúncios | Admin</title>
    <style>
        .ads-page { display: grid; gap: 14px; }
        .ads-heading { align-items: flex-start !important; }
        .ads-heading p { max-width: 720px; margin: 8px 0 0; }
        .ads-card { overflow: hidden; border: 1px solid #eaecf0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, .04); }
        .ads-card-head { padding: 16px 18px 0; }
        .ads-card-head h2 { margin: 0 0 5px; font-size: 16px; }
        .ads-card-head p { margin: 0; color: #667085; line-height: 1.5; }
        .ads-mode-form { display: grid; grid-template-columns: minmax(220px, .8fr) minmax(250px, 1.2fr) auto; align-items: end; gap: 10px; padding: 14px 18px 16px; }
        .ads-mode-form label { display: block; min-width: 0; margin: 0; color: #344054; font-size: 12px; font-weight: 600; }
        .ads-mode-form input, .ads-mode-form select { display: block; width: 100%; min-width: 0; margin-top: 4px; padding: 8px 9px; }
        .ads-mode-form button { min-height: 36px; white-space: nowrap; }
        .ads-mode-status { margin: 0 18px; padding: 9px 11px; border-radius: 7px; background: #f2f4f7; color: #344054; font-size: 13px; }
        .ads-mode-help { margin: 0 18px 16px; color: #667085; font-size: 12px; line-height: 1.5; }
        .ads-create { display: grid; grid-template-columns: minmax(160px, .75fr) minmax(240px, 1.5fr) auto; align-items: end; gap: 10px; padding: 14px 18px 16px; }
        .ads-create label, .ad-edit label { display: block; min-width: 0; margin: 0; color: #344054; font-size: 12px; font-weight: 600; }
        .ads-create input, .ad-edit input { display: block; width: 100%; min-width: 0; margin-top: 4px; padding: 7px 9px; }
        .ads-create button { min-height: 36px; white-space: nowrap; }
        .image-guide { display: grid; grid-template-columns: auto 1fr; align-items: start; gap: 10px; margin: 0 18px 16px; padding: 10px 12px; border: 1px solid #dbe5ff; border-radius: 9px; background: #f5f8ff; }
        .guide-icon { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 8px; background: #e0eaff; color: #3157d5; font-weight: 700; }
        .image-guide strong { display: block; margin-bottom: 4px; color: #1d2939; }
        .image-guide p { margin: 0; color: #475467; line-height: 1.55; }
        .ads-list-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #eaecf0; }
        .ads-list-head h2 { margin: 0; font-size: 16px; }
        .ads-count { color: #667085; font-size: 12px; }
        .ads-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); gap: 12px; padding: 12px; }
        .ad-item { min-width: 0; overflow: hidden; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .ad-image-wrap { position: relative; display: grid; aspect-ratio: 16 / 9; place-items: center; overflow: hidden; background: #f2f4f7; }
        .ad-preview { display: block; width: 100%; height: 100%; object-fit: contain; }
        .ad-image-fallback { display: none; padding: 16px; color: #b42318; text-align: center; font-size: 12px; }
        .ad-item.is-image-broken .ad-preview { display: none; }
        .ad-item.is-image-broken .ad-image-fallback { display: block; }
        .ad-edit { display: grid; gap: 8px; padding: 10px; }
        .ad-edit input { min-height: 32px !important; font-size: 12px; }
        .ad-actions { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; padding: 0 10px 10px; }
        .ad-actions .delete { margin: 0; }
        .ad-actions button { min-height: 32px; padding: 6px 9px; font-size: 12px; }
        .ads-empty { grid-column: 1 / -1; padding: 32px 16px; color: #667085; text-align: center; }
        @media (max-width: 720px) {
            .ads-create { grid-template-columns: 1fr; align-items: stretch; }
            .ads-mode-form { grid-template-columns: 1fr; align-items: stretch; }
            .ads-create button, .ads-mode-form button { width: 100%; }
            .ads-card-head, .ads-create, .ads-mode-form { padding-right: 14px; padding-left: 14px; }
            .ads-mode-status, .ads-mode-help { margin-right: 14px; margin-left: 14px; }
            .image-guide { margin-right: 14px; margin-left: 14px; }
            .ads-list-head { padding: 12px 14px; }
            .ads-grid { grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); padding: 10px; }
        }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page ads-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main class="ads-page">
    <header class="top ads-heading">
        <div>
            <h1>Anúncios</h1>
            <p>Cadastre banners que serão exibidos nos dispositivos desta conta. Para alternar entre banners no aplicativo, o tema precisa oferecer suporte à lista de anúncios.</p>
        </div>
        <a href="index.php">Voltar ao dashboard</a>
    </header>
    <?php if ($error !== ''): ?><div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <section class="ads-card" aria-labelledby="ads-mode-title">
        <div class="ads-card-head">
            <h2 id="ads-mode-title">Fonte dos anúncios</h2>
            <p>Escolha entre os banners cadastrados ou lançamentos de filmes e séries.</p>
        </div>
        <p class="ads-mode-status">Modo atual: <strong><?= $adsMode === 'tmdb' ? 'Automático — lançamentos do TMDB' : 'Manual — banners cadastrados' ?></strong></p>
        <form class="ads-mode-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ads_mode_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_mode">
            <label for="ads-mode"><span class="field-label">Fonte dos anúncios</span>
                <select id="ads-mode" name="ads_mode">
                    <option value="manual" <?= $adsMode === 'manual' ? 'selected' : '' ?>>Manual — banners cadastrados</option>
                    <option value="tmdb" <?= $adsMode === 'tmdb' ? 'selected' : '' ?>>Automático — lançamentos de filmes e séries</option>
                </select>
            </label>
            <label for="tmdb-api-key"><span class="field-label">Chave da API do TMDB<?= $tmdbKeyConfigured ? ' (já configurada; deixe vazio para manter)' : '' ?></span>
                <input id="tmdb-api-key" name="tmdb_api_key" type="password" maxlength="200" autocomplete="new-password" placeholder="<?= $tmdbKeyConfigured ? 'Chave já salva' : 'Informe sua chave do TMDB' ?>">
            </label>
            <button type="submit">Salvar fonte</button>
        </form>
        <p class="ads-mode-help">A tela de anúncios aberta consulta mudanças automaticamente a cada 10 segundos. A configuração do administrador é o padrão; contas de revenda podem escolher uma fonte própria. Para ativar o modo automático, informe a chave TMDB ou configure <code>TMDB_API_KEY</code> no servidor. Os lançamentos são atualizados a cada seis horas. <a href="../api/allads.php" target="_blank" rel="noopener noreferrer">Abrir prévia</a>.</p>
    </section>
    <section class="ads-card" aria-labelledby="add-ad-title">
        <div class="ads-card-head">
            <h2 id="add-ad-title">Adicionar anúncio</h2>
            <p>Use uma imagem hospedada publicamente e acessível pelo aplicativo.</p>
        </div>
        <form class="ads-create" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ads_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <label><span class="field-label">Título</span><input name="title" maxlength="255" required></label>
                <label><span class="field-label">Link direto da imagem</span><input name="url" type="url" maxlength="2048" placeholder="https://site.com/imagens/banner.jpg" required></label>
                <button type="submit">Adicionar</button>
        </form>
        <aside class="image-guide">
            <span class="guide-icon" aria-hidden="true">i</span>
            <div>
                <strong>Tamanho recomendado: 1920 × 1080 px (16:9)</strong>
                <p>Para telas de TV, prefira JPG ou WebP, com até 2 MB. PNG também é aceito. Use imagem horizontal; mantenha textos e logotipos afastados das bordas. Cole o endereço direto do arquivo de imagem, não o link de uma página ou de um modelo do Canva. Exemplos: <code>.jpg</code>, <code>.png</code> ou <code>.webp</code>.</p>
            </div>
        </aside>
    </section>

    <section class="ads-card" aria-labelledby="ads-list-title">
        <div class="ads-list-head">
            <h2 id="ads-list-title">Anúncios cadastrados</h2>
            <span class="ads-count"><?= count($ads) ?> anúncio(s)</span>
        </div>
        <div class="ads-grid">
            <?php if ($ads === []): ?>
                <div class="ads-empty">Nenhum anúncio cadastrado. Adicione o primeiro usando o formulário acima.</div>
            <?php else: ?>
                <?php foreach ($ads as $ad): ?>
                    <article class="ad-item">
                        <div class="ad-image-wrap">
                            <img class="ad-preview" src="<?= htmlspecialchars((string) ($ad['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="Prévia: <?= htmlspecialchars((string) ($ad['title'] ?? 'anúncio'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" referrerpolicy="no-referrer">
                            <span class="ad-image-fallback">A imagem não carregou. Verifique se o link aponta diretamente para um arquivo de imagem público.</span>
                        </div>
                        <form class="ad-edit" id="ad-edit-<?= (int) $ad['id'] ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ads_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                                    <label>Título<input name="title" maxlength="255" value="<?= htmlspecialchars((string) ($ad['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                                    <label>Link direto da imagem<input name="url" type="url" maxlength="2048" value="<?= htmlspecialchars((string) ($ad['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                        </form>
                        <div class="ad-actions">
                            <button type="submit" form="ad-edit-<?= (int) $ad['id'] ?>">Salvar alterações</button>
                            <form class="delete" method="post" onsubmit="return confirm('Excluir este anúncio?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ads_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                                <button class="danger" type="submit">Excluir</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<script>
    document.querySelectorAll('.ad-item .ad-preview').forEach(function (image) {
        image.addEventListener('error', function () {
            image.closest('.ad-item').classList.add('is-image-broken');
        });
    });
</script>
</body>
</html>
