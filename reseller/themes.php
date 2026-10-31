<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$options = getHomeThemeOptions();
$defaultTheme = getDefaultHomeTheme($db);
$selectedTheme = array_key_exists($reseller['home_theme'] ?? '', $options)
    ? $reseller['home_theme']
    : '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $theme = $_POST['home_theme'] ?? null;
        if (!is_string($theme) || ($theme !== '' && !array_key_exists($theme, $options))) {
            $error = 'Selecione um dos temas disponíveis.';
        } else {
            $saveTheme = $db->prepare(
                'UPDATE users SET home_theme = ? WHERE id = ? AND COALESCE(admin, 0) <> 1'
            );
            $saveTheme->execute([$theme, (int) $reseller['id']]);
            $selectedTheme = $theme;
            $reseller['home_theme'] = $theme;
            $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
            $success = $theme === ''
                ? 'O APK usará o tema padrão definido pelo administrador.'
                : 'Tema salvo para os dispositivos vinculados à sua conta.';
        }
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tema do APK | Área da revenda</title>
    <link rel="stylesheet" href="assets/panel.css?v=2">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 1000px; margin: auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        h1 { margin: 0; color: #101828; font-size: 25px; }
        h2 { margin: 0 0 8px; font-size: 18px; }
        p { color: #667085; line-height: 1.55; }
        a { color: #3157d5; text-decoration: none; }
        .panel { padding: 18px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .theme-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin: 18px 0; }
        .theme-option { padding: 10px; border: 1px solid #eaecf0; border-radius: 9px; background: #fff; }
        .theme-option.selected { border-color: #3157d5; box-shadow: 0 0 0 2px #3157d522; }
        .theme-option img { display: block; width: 100%; aspect-ratio: 16 / 9; margin-bottom: 9px; border-radius: 6px; object-fit: cover; }
        .theme-option label { display: flex; align-items: flex-start; gap: 8px; color: #344054; font-weight: 600; line-height: 1.4; cursor: pointer; }
        .theme-option input { margin: 2px 0 0; }
        .theme-option small { display: block; margin: 4px 0 0 22px; color: #667085; font-weight: 400; line-height: 1.4; }
        button { min-height: 40px; padding: 10px 15px; border: 0; border-radius: 7px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; font-weight: 600; }
        .notice { margin-bottom: 14px; padding: 11px 13px; border: 1px solid #abefc6; border-radius: 8px; background: #ecfdf3; color: #067647; }
        .notice.error { border-color: #fecdca; background: #fef3f2; color: #b42318; }
        @media (max-width: 560px) { body { padding: 18px 10px; } header { align-items: flex-start; flex-direction: column; } .panel { padding: 14px; } }
    </style>
</head>
<body class="reseller-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header>
        <div>
            <h1>Tema do APK</h1>
            <p>Escolha o modelo da tela inicial para os dispositivos vinculados à sua conta.</p>
        </div>
        <a href="index.php">Voltar ao painel</a>
    </header>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice" role="status"><?= $escape($success) ?></div><?php endif; ?>
    <section class="panel">
        <h2>Selecione um modelo</h2>
        <p>Esta configuração vale somente para seus dispositivos. Sub-revendas escolhem o próprio tema; o tema padrão fica sob controle do administrador. Para refletir a troca sem fechar o app, o APK precisa consultar a URL <code>theme_config_url</code> da API enquanto estiver aberto e aplicar <code>home_theme</code> quando a versão da configuração mudar.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
            <div class="theme-options">
                <article class="theme-option <?= $selectedTheme === '' ? 'selected' : '' ?>">
                    <img src="../admin/assets/home-themes/<?= $escape($options[$defaultTheme]['preview']) ?>" alt="Tema padrão do painel" loading="lazy">
                    <label>
                        <input type="radio" name="home_theme" value="" <?= $selectedTheme === '' ? 'checked' : '' ?> required>
                        Usar tema padrão do painel
                    </label>
                    <small>Modelo atual: <?= $escape($options[$defaultTheme]['name']) ?></small>
                </article>
                <?php foreach ($options as $key => $option): ?>
                    <article class="theme-option <?= $selectedTheme === $key ? 'selected' : '' ?>">
                        <img src="../admin/assets/home-themes/<?= $escape($option['preview']) ?>" alt="<?= $escape($option['name']) ?>" loading="lazy">
                        <label>
                            <input type="radio" name="home_theme" value="<?= $escape($key) ?>" <?= $selectedTheme === $key ? 'checked' : '' ?> required>
                            <?= $escape($option['name']) ?>
                        </label>
                    </article>
                <?php endforeach; ?>
            </div>
            <button type="submit">Salvar tema da minha conta</button>
        </form>
    </section>
</main>
</body>
</html>
