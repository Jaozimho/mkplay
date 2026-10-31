<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

if (empty($_SESSION['intro_csrf'])) {
    $_SESSION['intro_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['intro_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $url = trim($_POST['url'] ?? '');
        if ($url !== '' && (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
        )) {
            $error = 'Informe uma URL HTTP ou HTTPS válida, ou deixe o campo vazio para desativar o redirecionamento.';
        } else {
            try {
                setIntroUrl($db, $url);
                $_SESSION['intro_csrf'] = bin2hex(random_bytes(32));
                $success = $url === '' ? 'Redirecionamento desativado.' : 'URL de introdução salva.';
            } catch (PDOException $e) {
                error_log('Intro admin database error: ' . $e->getMessage());
                http_response_code(500);
                $error = 'Não foi possível salvar a configuração.';
            }
        }
    }
}

$url = getIntroUrl($db);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Introdução do app | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 680px; margin: auto; padding: 22px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 24px; }
        a { color: #3157d5; text-decoration: none; }
        label { display: block; margin-bottom: 7px; color: #475467; font-weight: 600; }
        input { width: 100%; margin-bottom: 12px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
        button { padding: 10px 14px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        .help { margin: 6px 0 18px; color: #667085; line-height: 1.5; }
        .message { margin-bottom: 14px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        @media (max-width: 520px) { body { padding: 18px 10px; } header { align-items: flex-start; flex-direction: column; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page intro-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1>Introdução do app</h1><a href="index.php">Voltar ao dashboard</a></header>
    <?php if ($error !== ''): ?><div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['intro_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="url">URL da introdução</label>
        <input id="url" type="url" name="url" maxlength="2048" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://exemplo.com/introducao">
        <p class="help">O endpoint <code>/api/intro.php</code> redireciona para esta URL. Deixe vazio para desativar.</p>
        <button type="submit">Salvar</button>
    </form>
</main>
</body>
</html>
