<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';
$db = getDB();
$adminId = (int) $_SESSION['user_id'];

if (empty($_SESSION['themes_csrf'])) {
    $_SESSION['themes_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['themes_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'set_default_layout') {
                $homeTheme = $_POST['home_theme'] ?? '';
                if (!is_string($homeTheme)) {
                    throw new InvalidArgumentException('Selecione um dos temas disponíveis.');
                }
                setDefaultHomeTheme($db, $homeTheme);
                $success = 'Tema padrão do APK atualizado.';
            } elseif ($action === 'save_default_branding_name') {
                $brandingName = $_POST['default_branding_name'] ?? null;
                if (!is_string($brandingName)) {
                    throw new InvalidArgumentException('Informe um nome válido para o aplicativo.');
                }
                setDefaultApkBrandingName($db, $brandingName);
                $success = trim($brandingName) === ''
                    ? 'Nome padrão removido. Será usado o nome personalizado da revenda, se houver.'
                    : 'Nome padrão do aplicativo atualizado. Nomes personalizados das revendas continuam tendo prioridade.';
            } elseif ($action === 'save_default_branding') {
                $logoInput = $_FILES['default_logo'] ?? [];
                $backgroundInput = $_FILES['default_background'] ?? [];
                if (($logoInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    setDefaultApkBrandingPath($db, 'logo', saveAccountLogo($logoInput, $adminId));
                }
                if (($backgroundInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    setDefaultApkBrandingPath($db, 'background_image', saveAccountBackground($backgroundInput, $adminId));
                }
                if (($logoInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                    && ($backgroundInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new InvalidArgumentException('Selecione uma logo ou uma imagem de fundo para atualizar.');
                }
                $success = 'Identidade padrão do APK atualizada. Contas sem imagem própria usarão os novos arquivos.';
            } elseif ($action === 'remove_default_logo') {
                setDefaultApkBrandingPath($db, 'logo', '');
                $success = 'Logo padrão removida.';
            } elseif ($action === 'remove_default_background') {
                setDefaultApkBrandingPath($db, 'background_image', '');
                $success = 'Imagem de fundo padrão removida.';
            } else {
                throw new InvalidArgumentException('Ação inválida.');
            }

            $_SESSION['themes_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('Admin theme management database error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível salvar o tema no banco.';
        } catch (Throwable $e) {
            error_log('Admin default APK branding update failed: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível atualizar a identidade padrão do APK.';
        }
    }
}

$homeThemeOptions = getHomeThemeOptions();
$defaultHomeTheme = getDefaultHomeTheme($db);
$defaultBrandingName = getDefaultApkBrandingName($db);
$defaultLogoUrl = getDefaultApkBrandingPath($db, 'logo') !== ''
    ? getPublicBaseUrl() . getDefaultApkBrandingPath($db, 'logo')
    : '';
$defaultBackgroundUrl = getDefaultApkBrandingPath($db, 'background_image') !== ''
    ? getPublicBaseUrl() . getDefaultApkBrandingPath($db, 'background_image')
    : '';
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Temas | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 1040px; margin: auto; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        .top-copy { display: grid; gap: 6px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        .top p, .section-heading p { margin: 0; color: #667085; line-height: 1.5; }
        h2 { margin: 0; font-size: 17px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { display: grid; gap: 0; overflow: hidden; border: 1px solid #eaecf0; border-radius: 12px; background: #fff; }
        .section { padding: 22px; }
        .section + .section { border-top: 1px solid #eaecf0; }
        .section-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
        .section-heading > div { display: grid; gap: 5px; }
        .count { flex: 0 0 auto; padding: 5px 10px; border-radius: 999px; background: #f2f4f7; color: #475467; font-size: 12px; font-weight: 600; }
        label { display: block; margin: 0; color: #475467; font-size: 12px; font-weight: 600; }
        input { display: block; width: 100%; min-width: 0; margin-top: 6px; padding: 10px 11px; border: 1px solid #d0d5dd; border-radius: 7px; font: inherit; }
        button { min-height: 38px; padding: 9px 13px; border: 0; border-radius: 7px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; font-weight: 600; white-space: nowrap; }
        button.danger { border: 1px solid #fecdca; background: #fff; color: #b42318; }
        button.danger:hover { background: #fef3f2; }
        .layout-themes { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .layout-theme { padding: 10px; border: 1px solid #eaecf0; border-radius: 9px; background: #fff; }
        .layout-theme img { display: block; width: 100%; height: 110px; margin-bottom: 8px; border-radius: 6px; object-fit: cover; }
        .layout-theme label { display: flex; align-items: center; gap: 7px; cursor: pointer; }
        .layout-theme input { width: auto; margin: 0; }
        .layout-actions { margin-top: 14px; }
        .branding-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .branding-item { min-width: 0; padding: 14px; border: 1px solid #eaecf0; border-radius: 9px; }
        .branding-preview { display: block; width: 100%; height: 180px; margin: 0 0 12px; border: 0; border-radius: 0; background: #101828; object-fit: contain; }
        .branding-preview.background { height: 150px; border: 1px solid #eaecf0; border-radius: 7px; background: #f2f4f7; object-fit: cover; }
        .branding-empty { display: grid; height: 150px; margin-bottom: 12px; place-items: center; border: 1px dashed #d0d5dd; border-radius: 7px; color: #667085; text-align: center; }
        .branding-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
        .help { margin: 8px 0 16px; color: #667085; line-height: 1.5; }
        .message { margin-bottom: 15px; padding: 12px; border-radius: 7px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        @media (max-width: 600px) {
            body { padding: 20px 10px; }
            .top { align-items: flex-start; flex-direction: column; }
            .section { padding: 16px; }
            .branding-grid { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page themes-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top">
        <div class="top-copy">
            <h1>Temas</h1>
            <p>Gerencie os temas disponibilizados para os dispositivos.</p>
        </div>
        <a href="index.php">Voltar ao dashboard</a>
    </header>
    <?php if ($error !== ''): ?><div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="panel">
        <section class="section">
            <div class="section-heading">
                <div><h2>Nome padrão do aplicativo</h2><p>Este nome aparece na identidade do APK quando a revenda não definiu um nome próprio. Nomes personalizados das revendas continuam prevalecendo.</p></div>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['themes_csrf']) ?>">
                <input type="hidden" name="action" value="save_default_branding_name">
                <label for="default-branding-name">Nome exibido no aplicativo
                    <input id="default-branding-name" type="text" name="default_branding_name" maxlength="80" value="<?= $escape($defaultBrandingName) ?>" placeholder="Ex.: O Ibo Player Pro">
                </label>
                <p class="help">Até 80 caracteres. Deixe vazio e salve para remover o nome padrão.</p>
                <div class="layout-actions"><button type="submit">Salvar nome do aplicativo</button></div>
            </form>
        </section>
        <section class="section">
            <div class="section-heading">
                <div><h2>Logo e fundo padrão do APK</h2><p>Estas imagens são usadas em dispositivos sem uma identidade personalizada da revenda. Alterações são disponibilizadas pela API nos próximos carregamentos ou consultas de configuração do APK.</p></div>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['themes_csrf']) ?>">
                <input type="hidden" name="action" value="save_default_branding">
                <div class="branding-grid">
                    <div class="branding-item">
                        <h3>Logo padrão</h3>
                        <?php if ($defaultLogoUrl !== ''): ?>
                            <img class="branding-preview" src="<?= $escape($defaultLogoUrl) ?>" alt="Logo padrão atual">
                        <?php else: ?>
                            <div class="branding-empty">Nenhuma logo padrão configurada</div>
                        <?php endif; ?>
                        <label for="default-logo">Enviar logo (PNG, JPG ou WebP; até 2 MB)
                            <input id="default-logo" type="file" name="default_logo" accept="image/png,image/jpeg,image/webp">
                        </label>
                        <?php if ($defaultLogoUrl !== ''): ?>
                            <div class="branding-actions">
                                <button class="danger" type="submit" formaction="" name="action" value="remove_default_logo" formnovalidate>Remover logo padrão</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="branding-item">
                        <h3>Fundo padrão</h3>
                        <?php if ($defaultBackgroundUrl !== ''): ?>
                            <img class="branding-preview background" src="<?= $escape($defaultBackgroundUrl) ?>" alt="Fundo padrão atual">
                        <?php else: ?>
                            <div class="branding-empty">Nenhum fundo padrão configurado</div>
                        <?php endif; ?>
                        <label for="default-background">Enviar fundo (PNG, JPG ou WebP; até 5 MB)
                            <input id="default-background" type="file" name="default_background" accept="image/png,image/jpeg,image/webp">
                        </label>
                        <?php if ($defaultBackgroundUrl !== ''): ?>
                            <div class="branding-actions">
                                <button class="danger" type="submit" formaction="" name="action" value="remove_default_background" formnovalidate>Remover fundo padrão</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="help">Você pode enviar uma ou as duas imagens. Ao atualizar uma delas, a imagem atual da outra será mantida. Logos e fundos configurados individualmente nas revendas continuam tendo prioridade.</p>
                <div class="layout-actions"><button type="submit">Salvar identidade padrão</button></div>
            </form>
        </section>
        <section class="section">
            <div class="section-heading">
                <div><h2>Tema padrão do APK</h2><p>Define o modelo inicial para dispositivos sem tema próprio configurado. Cada conta também pode escolher o próprio tema na área de branding. Para atualizar sem fechar o app, o APK deve consultar a URL <code>theme_config_url</code> da API enquanto estiver aberto e aplicar o campo <code>home_theme</code> quando <code>version</code> mudar.</p></div>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['themes_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="set_default_layout">
                <div class="layout-themes">
                    <?php foreach ($homeThemeOptions as $key => $option): ?>
                        <div class="layout-theme">
                            <img src="assets/home-themes/<?= $escape($option['preview']) ?>" alt="<?= $escape($option['name']) ?>" loading="lazy">
                            <label>
                                <input type="radio" name="home_theme" value="<?= $escape($key) ?>" <?= $key === $defaultHomeTheme ? 'checked' : '' ?>>
                                <?= $escape($option['name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="layout-actions"><button type="submit">Salvar tema padrão</button></div>
            </form>
        </section>
    </section>
</main>
</body>
</html>
