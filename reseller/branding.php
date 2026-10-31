<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$homeThemeOptions = getHomeThemeOptions();
$selectedHomeTheme = array_key_exists($reseller['home_theme'] ?? '', $homeThemeOptions)
    ? $reseller['home_theme']
    : '';
$defaultHomeTheme = getDefaultHomeTheme($db);
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_branding') {
            $brandingNameInput = $_POST['branding_name'] ?? '';
            $brandingName = is_string($brandingNameInput) ? trim($brandingNameInput) : '';
            $homeThemeInput = $_POST['home_theme'] ?? '';
            $homeTheme = is_string($homeThemeInput) ? $homeThemeInput : '';
            if (preg_match('/^.{0,80}$/us', $brandingName) !== 1) {
                $error = 'O nome da empresa pode ter no máximo 80 caracteres.';
            } elseif ($homeTheme !== '' && !array_key_exists($homeTheme, $homeThemeOptions)) {
                $error = 'Selecione um dos temas disponíveis.';
            } else {
                try {
                    $logoPath = null;
                    $logoInput = $_FILES['logo'] ?? [];
                    if (($logoInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $logoPath = saveAccountLogo($logoInput, (int) $reseller['id']);
                    }
                    $backgroundPath = null;
                    $backgroundInput = $_FILES['background_image'] ?? [];
                    if (($backgroundInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $backgroundPath = saveAccountBackground($backgroundInput, (int) $reseller['id']);
                    }

                    $updates = ['branding_name = ?', 'home_theme = ?'];
                    $parameters = [$brandingName, $homeTheme];
                    if ($logoPath !== null) {
                        $updates[] = 'logo = ?';
                        $parameters[] = $logoPath;
                        $reseller['logo'] = $logoPath;
                    }
                    if ($backgroundPath !== null) {
                        $updates[] = 'background_image = ?';
                        $parameters[] = $backgroundPath;
                        $reseller['background_image'] = $backgroundPath;
                    }
                    $parameters[] = (int) $reseller['id'];
                    $saveBranding = $db->prepare(
                        'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? AND COALESCE(admin, 0) <> 1'
                    );
                    $saveBranding->execute($parameters);

                    $reseller['branding_name'] = $brandingName;
                    $reseller['home_theme'] = $homeTheme;
                    $selectedHomeTheme = $homeTheme;
                    $success = 'Configurações de nome, logo, fundo e tema salvas. Imagens não selecionadas foram mantidas.';
                } catch (InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log('Reseller APK branding update failed: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'Não foi possível salvar a identidade do APK.';
                }
            }
        } elseif ($action === 'save_logo') {
            try {
                $logoPath = saveAccountLogo($_FILES['logo'] ?? [], (int) $reseller['id']);
                $saveLogo = $db->prepare('UPDATE users SET logo = ? WHERE id = ? AND COALESCE(admin, 0) <> 1');
                $saveLogo->execute([$logoPath, (int) $reseller['id']]);
                $success = 'Logo do seu APK atualizada.';
                $reseller['logo'] = $logoPath;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Reseller APK logo update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Não foi possível salvar a logo do APK.';
            }
        } elseif ($action === 'remove_logo') {
            $removeLogo = $db->prepare('UPDATE users SET logo = NULL WHERE id = ? AND COALESCE(admin, 0) <> 1');
            $removeLogo->execute([(int) $reseller['id']]);
            $reseller['logo'] = null;
            $success = 'Logo personalizada removida.';
        } elseif ($action === 'remove_background') {
            $removeBackground = $db->prepare('UPDATE users SET background_image = NULL WHERE id = ? AND COALESCE(admin, 0) <> 1');
            $removeBackground->execute([(int) $reseller['id']]);
            $reseller['background_image'] = null;
            $success = 'Fundo personalizado removido.';
        } else {
            $error = 'Ação inválida.';
        }
        $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Identidade do APK | Área da revenda</title>
    <style>
        * { box-sizing: border-box; }
        html { scrollbar-width: none; }
        html::-webkit-scrollbar { display: none; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; overflow-x: clip; scrollbar-width: none; }
        body::-webkit-scrollbar { display: none; }
        main { max-width: 760px; margin: auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        h1 { margin: 0; color: #101828; font-size: 25px; }
        h2 { margin: 0 0 10px; font-size: 18px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { padding: 18px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        p { color: #475467; line-height: 1.55; }
        label { display: block; margin: 14px 0; color: #475467; }
        input[type=file] { display: block; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 7px; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.remove { margin-top: 10px; background: #b42318; }
        main { max-width: 920px; }
        .page-head { margin-bottom: 18px; }
        .page-head p { margin: 6px 0 0; color: #667085; line-height: 1.5; }
        .branding-grid { display: grid; grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); gap: 18px; align-items: start; }
        .branding-panel { padding: 18px; }
        .branding-panel > p { margin: 8px 0 0; color: #667085; line-height: 1.55; }
        .preview-box { display: grid; min-height: 190px; margin: 14px 0; padding: 16px; place-items: center; border: 0; border-radius: 0; background: #101828; }
        .logo-preview { display: block; width: min(100%, 280px); max-height: 160px; border: 0; outline: 0; background: transparent; object-fit: contain; }
        .preview-box p { color: #fff; }
        .background-preview { display: block; width: 100%; max-height: 220px; margin-top: 10px; border: 1px solid #eaecf0; border-radius: 8px; object-fit: cover; }
        .preview-empty { color: #667085; text-align: center; }
        .upload-panel { padding: 18px; }
        .upload-panel p { margin: 8px 0 14px; color: #667085; line-height: 1.5; }
        .file-help { display: block; margin-top: 5px; color: #667085; font-size: 12px; }
        .form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .theme-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin: 12px 0; }
        .theme-option { padding: 8px; border: 1px solid #eaecf0; border-radius: 8px; }
        .theme-option img { display: block; width: 100%; height: 84px; margin-bottom: 7px; border-radius: 5px; object-fit: cover; }
        .theme-option label { display: flex; align-items: center; gap: 6px; margin: 0; }
        .theme-option input { width: auto; margin: 0; }
        button.remove { background: #b42318; }
        .notice { margin-bottom: 14px; padding: 11px 13px; border: 1px solid #abefc6; border-radius: 8px; background: #ecfdf3; color: #067647; }
        .notice.error { border-color: #fecdca; background: #fef3f2; color: #b42318; }
        @media (max-width: 700px) { .branding-grid { grid-template-columns: 1fr; } .branding-panel, .upload-panel { padding: 14px; } .preview-box { min-height: 150px; } }
        @media (max-width: 560px) { body { padding: 18px 10px; } header { align-items: flex-start; flex-direction: column; } .panel { padding: 14px; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css?v=2">
</head>
<body class="reseller-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="page-head">
        <h1>Identidade do APK</h1>
        <p>Personalize o nome, a logo, o fundo e o modelo de tela inicial dos dispositivos vinculados à sua conta.</p>
        <a href="index.php">Voltar ao painel</a>
    </header>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice" role="status"><?= $escape($success) ?></div><?php endif; ?>
    <div class="branding-grid">
        <section class="panel branding-panel">
            <h2>Pré-visualização</h2>
            <p><strong>Nome comercial configurado:</strong> <?= $reseller['branding_name'] !== '' ? $escape($reseller['branding_name']) : 'Nenhum nome configurado' ?></p>
            <?php if (!empty($reseller['logo'])): ?>
                <div class="preview-box">
                    <div>
                        <img class="logo-preview" src="<?= $escape(getAccountLogoUrl($db, (int) $reseller['id'])) ?>" alt="Logo atual do APK">
                        <?php if ($reseller['branding_name'] !== ''): ?><p><?= $escape($reseller['branding_name']) ?></p><?php endif; ?>
                    </div>
                </div>
                <p>Esta é a logo e o nome associados à sua conta.</p>
            <?php else: ?>
                <div class="preview-box"><p class="preview-empty"><?= $reseller['branding_name'] !== '' ? $escape($reseller['branding_name']) . '<br>' : '' ?>Nenhuma logo personalizada enviada.</p></div>
                <p>Sem logo própria, a API não envia imagem personalizada para esta conta.</p>
            <?php endif; ?>
            <h2>Fundo do aplicativo</h2>
            <?php if (!empty($reseller['background_image'])): ?>
                <img class="background-preview" src="<?= $escape(getAccountBackgroundUrl($db, (int) $reseller['id'])) ?>" alt="Fundo atual do aplicativo">
                <p>Este fundo será usado no app dos dispositivos vinculados a esta conta.</p>
            <?php else: ?>
                <p>Nenhum fundo personalizado. O APK usará o fundo padrão.</p>
            <?php endif; ?>
        </section>
        <section class="panel upload-panel">
            <h2>Identidade e tema</h2>
            <p>Configure a identidade e o modelo de tela do APK para os dispositivos vinculados a esta conta. O tema não altera as configurações de outras revendas ou sub-revendas.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                <input type="hidden" name="action" value="save_branding">
                <label>Nome comercial que aparecerá no APK (não é o usuário/login)
                    <input type="text" name="branding_name" maxlength="80" value="<?= $escape($reseller['branding_name']) ?>" placeholder="Digite o nome da sua empresa">
                </label>
                <label>Modelo de tela inicial</label>
                <div class="theme-options">
                    <div class="theme-option">
                        <img src="../admin/assets/home-themes/<?= $escape($homeThemeOptions[$defaultHomeTheme]['preview']) ?>" alt="Tema padrão" loading="lazy">
                        <label><input type="radio" name="home_theme" value="" <?= $selectedHomeTheme === '' ? 'checked' : '' ?> required> Usar padrão do painel (<?= $escape($homeThemeOptions[$defaultHomeTheme]['name']) ?>)</label>
                    </div>
                    <?php foreach ($homeThemeOptions as $key => $option): ?>
                        <div class="theme-option">
                            <img src="../admin/assets/home-themes/<?= $escape($option['preview']) ?>" alt="<?= $escape($option['name']) ?>" loading="lazy">
                            <label><input type="radio" name="home_theme" value="<?= $escape($key) ?>" <?= $selectedHomeTheme === $key ? 'checked' : '' ?> required> <?= $escape($option['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label>Arquivo da logo
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    <small class="file-help">Opcional · PNG, JPG ou WebP · máximo 2 MB · até 4096 × 4096 px</small>
                </label>
                <label>Fundo do aplicativo
                    <input type="file" name="background_image" accept="image/png,image/jpeg,image/webp">
                    <small class="file-help">Opcional · PNG, JPG ou WebP · máximo 5 MB · até 4096 × 4096 px. Se não enviar uma nova imagem, o fundo atual será mantido.</small>
                </label>
                <div class="form-actions">
                    <button type="submit">Salvar nome, logo e fundo</button>
                    <?php if (!empty($reseller['logo'])): ?>
                        <button class="remove" type="submit" form="remove-logo-form">Remover logo</button>
                    <?php endif; ?>
                    <?php if (!empty($reseller['background_image'])): ?>
                        <button class="remove" type="submit" form="remove-background-form">Remover fundo</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php if (!empty($reseller['logo'])): ?>
                <form id="remove-logo-form" method="post" onsubmit="return confirm('Remover sua logo personalizada?')">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                    <input type="hidden" name="action" value="remove_logo">
                </form>
            <?php endif; ?>
            <?php if (!empty($reseller['background_image'])): ?>
                <form id="remove-background-form" method="post" onsubmit="return confirm('Remover o fundo personalizado do aplicativo?')">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                    <input type="hidden" name="action" value="remove_background">
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
