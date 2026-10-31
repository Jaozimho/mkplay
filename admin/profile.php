<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$userId = (int) $_SESSION['user_id'];
$statement = $db->prepare('SELECT id, name, username, password, logo, background_image, branding_name, home_theme FROM users WHERE id = ? AND admin = 1');
$statement->execute([$userId]);
$admin = $statement->fetch();
if (!$admin) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}
$homeThemeOptions = getHomeThemeOptions();
$selectedHomeTheme = array_key_exists($admin['home_theme'] ?? '', $homeThemeOptions)
    ? $admin['home_theme']
    : '';
$defaultHomeTheme = getDefaultHomeTheme($db);

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['profile_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_branding') {
            try {
                $brandingNameInput = $_POST['branding_name'] ?? '';
                $brandingName = is_string($brandingNameInput) ? trim($brandingNameInput) : '';
                $homeThemeInput = $_POST['home_theme'] ?? '';
                $homeTheme = is_string($homeThemeInput) ? $homeThemeInput : '';
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                if (!verifyUserPassword($db, $admin, $currentPassword)) {
                    throw new InvalidArgumentException('A senha atual está incorreta.');
                }
                if (preg_match('/^.{0,80}$/us', $brandingName) !== 1) {
                    throw new InvalidArgumentException('O nome comercial pode ter no máximo 80 caracteres.');
                }
                if ($homeTheme !== '' && !array_key_exists($homeTheme, $homeThemeOptions)) {
                    throw new InvalidArgumentException('Selecione um dos temas disponíveis.');
                }

                $logoPath = null;
                $logoInput = $_FILES['logo'] ?? [];
                if (($logoInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $logoPath = saveAccountLogo($logoInput, $userId);
                }
                $backgroundPath = null;
                $backgroundInput = $_FILES['background_image'] ?? [];
                if (($backgroundInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $backgroundPath = saveAccountBackground($backgroundInput, $userId);
                }

                $updates = ['branding_name = ?', 'home_theme = ?'];
                $parameters = [$brandingName, $homeTheme];
                if ($logoPath !== null) {
                    $updates[] = 'logo = ?';
                    $parameters[] = $logoPath;
                }
                if ($backgroundPath !== null) {
                    $updates[] = 'background_image = ?';
                    $parameters[] = $backgroundPath;
                }
                $parameters[] = $userId;
                $updateBranding = $db->prepare(
                    'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? AND admin = 1'
                );
                $updateBranding->execute($parameters);
                $statement->execute([$userId]);
                $admin = $statement->fetch();
                $selectedHomeTheme = $homeTheme;
                $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
                $success = 'Configurações de nome, logo, fundo e tema salvas. Imagens não selecionadas foram mantidas.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Admin APK branding update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Não foi possível atualizar a identidade do APK.';
            }
        } elseif ($action === 'save_logo') {
            try {
                $logoPath = saveAccountLogo($_FILES['logo'] ?? [], $userId);
                $updateLogo = $db->prepare('UPDATE users SET logo = ? WHERE id = ? AND admin = 1');
                $updateLogo->execute([$logoPath, $userId]);
                $statement->execute([$userId]);
                $admin = $statement->fetch();
                $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
                $success = 'Logo do APK do administrador atualizada.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Admin APK logo update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Não foi possível atualizar a logo do APK.';
            }
        } elseif ($action === 'remove_logo') {
            $updateLogo = $db->prepare('UPDATE users SET logo = NULL WHERE id = ? AND admin = 1');
            $updateLogo->execute([$userId]);
            $statement->execute([$userId]);
            $admin = $statement->fetch();
            $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
            $success = 'Logo personalizada removida.';
        } elseif ($action === 'remove_background') {
            $updateBackground = $db->prepare('UPDATE users SET background_image = NULL WHERE id = ? AND admin = 1');
            $updateBackground->execute([$userId]);
            $statement->execute([$userId]);
            $admin = $statement->fetch();
            $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
            $success = 'Fundo personalizado removido.';
        } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (!verifyUserPassword($db, $admin, $currentPassword)) {
            $error = 'A senha atual está incorreta.';
        } elseif ($name === '' || strlen($name) > 255) {
            $error = 'Informe um nome (até 255 caracteres).';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $error = 'O usuário deve ter de 3 a 64 caracteres: letras, números, ponto, hífen ou sublinhado.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $error = 'A nova senha deve ter pelo menos 8 caracteres.';
        } else {
            try {
                $check = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
                $check->execute([$username, $userId]);
                if ((int) $check->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Este nome de usuário já está em uso.');
                }
                if ($newPassword === '') {
                    $update = $db->prepare('UPDATE users SET name = ?, username = ? WHERE id = ? AND admin = 1');
                    $update->execute([$name, $username, $userId]);
                } else {
                    $update = $db->prepare('UPDATE users SET name = ?, username = ?, password = ? WHERE id = ? AND admin = 1');
                    $update->execute([$name, $username, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                }
                $statement->execute([$userId]);
                $admin = $statement->fetch();
                $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
                $success = 'Perfil atualizado.';
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                error_log('Admin profile update error: ' . $e->getMessage());
                http_response_code(500);
                $error = 'Não foi possível atualizar o perfil.';
            }
            }
        }
    }
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meu perfil | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 30px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 620px; margin: auto; padding: 22px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 25px; }
        a { color: #3157d5; text-decoration: none; }
        label { display: block; margin-bottom: 14px; color: #475467; font-size: 13px; }
        input { display: block; width: 100%; margin-top: 5px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
        button { padding: 10px 14px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        .message { margin-bottom: 14px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .help { color: #667085; line-height: 1.5; }
        @media (max-width: 520px) { body { padding: 18px 10px; } header { align-items: flex-start; flex-direction: column; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1>Meu perfil</h1><a href="index.php">Voltar ao dashboard</a></header>
    <?php if ($error !== ''): ?><div class="message error"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= $escape($success) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['profile_csrf']) ?>">
        <label>Nome<input name="name" maxlength="255" value="<?= $escape($admin['name']) ?>" required></label>
        <label>Usuário<input name="username" minlength="3" maxlength="64" value="<?= $escape($admin['username']) ?>" required></label>
        <label>Senha atual<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Nova senha (opcional)<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
        <p class="help">A senha atual é solicitada para confirmar qualquer alteração. Deixe a nova senha vazia para mantê-la.</p>
        <button type="submit">Salvar perfil</button>
    </form>
</main>
</body>
</html>
