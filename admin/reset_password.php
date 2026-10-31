<?php
session_start();

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('A redefinição de senha só pode ser feita neste computador.');
}

$dbPath = __DIR__ . '/../api/db/.ansdb.db';
$error = '';
$success = '';
$databaseAvailable = is_file($dbPath);

if (!$databaseAvailable) {
    http_response_code(500);
    $error = 'Banco da API não encontrado.';
} elseif (empty($_SESSION['reset_password_csrf'])) {
    $_SESSION['reset_password_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['reset_password_csrf'], $csrfToken)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';

        if ($username === '' || $password === '' || $confirmation === '') {
            $error = 'Preencha todos os campos.';
        } elseif (strlen($password) < 6) {
            $error = 'A senha deve ter pelo menos 6 caracteres.';
        } elseif ($password !== $confirmation) {
            $error = 'As senhas não coincidem.';
        } else {
            try {
                require_once __DIR__ . '/../api/includes/db.php';
                $db = getDB();

                $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND admin = 1');
                $stmt->execute([$username]);
                $userId = $stmt->fetchColumn();

                if ($userId === false) {
                    $error = 'Administrador não encontrado. Confira o nome de usuário.';
                } else {
                    $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND admin = 1');
                    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                    $success = 'Senha atualizada. Agora você pode entrar no painel.';
                    $_SESSION['reset_password_csrf'] = bin2hex(random_bytes(32));
                }
            } catch (PDOException $e) {
                http_response_code(500);
                $error = 'Não foi possível acessar o banco de dados. Verifique se o SQLite está habilitado no PHP.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir senha - IBO Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 40px 16px; background: #f5f5f5; color: #222; }
        .form { max-width: 400px; margin: auto; background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        input { box-sizing: border-box; width: 100%; padding: 11px; margin: 8px 0 14px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #2196F3; color: white; padding: 12px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .message { margin: 12px 0; }
        .error { color: #b00020; }
        .success { color: #167c32; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-page">
    <main class="form">
        <h2>Redefinir senha do administrador</h2>
        <p>Disponível somente neste computador.</p>
        <?php if ($error !== ''): ?>
            <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($databaseAvailable): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['reset_password_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="username">Usuário administrador</label>
                <input id="username" type="text" name="username" autocomplete="username" required>
                <label for="password">Nova senha (mínimo 6 caracteres)</label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="6" required>
                <label for="password_confirmation">Confirme a nova senha</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="6" required>
                <button type="submit">Atualizar senha</button>
            </form>
        <?php endif; ?>
        <a class="back" href="login.php">Voltar ao login</a>
    </main>
</body>
</html>
