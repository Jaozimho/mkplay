<?php
session_start();
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';
$db = getDB();

if (!empty($_SESSION['reseller_user_id'])) {
    $currentId = filter_var($_SESSION['reseller_user_id'], FILTER_VALIDATE_INT);
    if ($currentId !== false && $currentId !== null) {
        $check = $db->prepare('SELECT id FROM users WHERE id = ? AND COALESCE(admin, 0) <> 1');
        $check->execute([$currentId]);
        if ($check->fetchColumn() !== false) {
            header('Location: index.php');
            exit;
        }
    }
    unset($_SESSION['reseller_user_id']);
}

$error = '';
$username = '';
$logoPath = getDefaultApkBrandingPath($db, 'logo');
$logoUrl = $logoPath === '' ? '' : getPublicBaseUrl() . $logoPath;
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Informe o usuário e a senha.';
    } else {
        $query = $db->prepare('
            SELECT id, password, admin
            FROM users
            WHERE username = ? AND COALESCE(admin, 0) <> 1
        ');
        $query->execute([$username]);
        $user = $query->fetch();
        if ($user && verifyUserPassword($db, $user, $password)) {
            session_regenerate_id(true);
            $_SESSION['reseller_user_id'] = (int) $user['id'];
            $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }
        $error = 'Usuário ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101b35">
    <title>Área da revenda | KSG</title>
    <link rel="stylesheet" href="assets/reseller-login.css?v=20261025-3">
</head>
<body class="auth-page">
<main class="reseller-login-shell">
    <section class="reseller-login-card" aria-label="Acesso à área da revenda">
        <aside class="reseller-login-aside">
            <a class="reseller-login-brand" href="login.php" aria-label="KSG">
                <?php if ($logoUrl !== ''): ?>
                    <span class="reseller-logo-frame"><img src="<?= $escape($logoUrl) ?>" alt="Logo cadastrada no sistema" onerror="this.parentElement.hidden=true;this.parentElement.nextElementSibling.hidden=false"></span>
                    <span class="reseller-brand-fallback" hidden>KSG</span>
                <?php else: ?>
                    <span class="reseller-brand-fallback">KSG</span>
                <?php endif; ?>
            </a>
            <div class="reseller-login-promo">
                <span class="promo-eyebrow">ÁREA DO PARCEIRO</span>
                <h1>Seu negócio<br>em movimento.</h1>
                <p>Acesse sua conta para acompanhar ativações, créditos e as ferramentas da sua revenda.</p>
                <div class="reseller-promo-art" aria-hidden="true">
                    <span class="promo-ring ring-one"></span>
                    <span class="promo-ring ring-two"></span>
                    <span class="promo-dashboard">
                        <span class="dashboard-head"><i></i><i></i><i></i></span>
                        <span class="dashboard-line line-wide"></span>
                        <span class="dashboard-line line-short"></span>
                        <span class="dashboard-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
                    </span>
                    <span class="promo-credit"><small>CRÉDITOS</small><strong>+</strong></span>
                </div>
            </div>
            <div class="reseller-aside-footer"><span></span> Portal oficial para revendedores</div>
        </aside>

        <div class="reseller-login-content">
            <div class="mobile-brand">
                <?php if ($logoUrl !== ''): ?>
                    <span class="reseller-logo-frame"><img src="<?= $escape($logoUrl) ?>" alt="Logo cadastrada no sistema" onerror="this.parentElement.hidden=true;this.parentElement.nextElementSibling.hidden=false"></span>
                    <span class="reseller-brand-fallback" hidden>KSG</span>
                <?php else: ?>
                    <strong>KSG</strong>
                <?php endif; ?>
            </div>
            <div class="reseller-form-wrap">
                <span class="login-eyebrow">BEM-VINDO DE VOLTA</span>
                <h2>Entre na sua conta</h2>
                <p class="login-description">Informe seus dados para acessar o painel da revenda.</p>
                <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
                <form method="post" class="reseller-login-form">
                    <label for="username">Usuário</label>
                    <div class="login-input-wrap">
                        <span aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </span>
                        <input id="username" name="username" placeholder="Seu usuário" value="<?= $escape($username) ?>" autocomplete="username" required autofocus>
                    </div>
                    <label for="password">Senha</label>
                    <div class="login-input-wrap">
                        <span aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0 1 10 0v3M5.5 10h13v10h-13z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <input id="password" name="password" type="password" placeholder="Sua senha" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
                    </div>
                    <button class="reseller-submit" type="submit">Entrar na minha conta <span aria-hidden="true">→</span></button>
                </form>
                <div class="login-security-note"><span aria-hidden="true">◇</span> Acesso seguro à sua revenda</div>
            </div>
            <footer class="reseller-login-footer">© <?= date('Y') ?> KSG <span>·</span> Portal de revendedores</footer>
        </div>
    </section>
</main>
<script>
    (function () {
        const toggle = document.querySelector('.password-toggle');
        const password = document.getElementById('password');
        toggle.addEventListener('click', function () {
            const showPassword = password.type === 'password';
            password.type = showPassword ? 'text' : 'password';
            toggle.textContent = showPassword ? 'Ocultar' : 'Mostrar';
            toggle.setAttribute('aria-label', showPassword ? 'Ocultar senha' : 'Mostrar senha');
            toggle.setAttribute('aria-pressed', String(showPassword));
        });
    })();
</script>
</body>
</html>
