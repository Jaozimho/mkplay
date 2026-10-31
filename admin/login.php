<?php
session_start();
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

if (!empty($_SESSION['logged_in'])) {
   $sessionAdminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
   if ($sessionAdminId !== false && $sessionAdminId !== null) {
       $checkAdmin = $db->prepare('SELECT admin FROM users WHERE id = ?');
       $checkAdmin->execute([$sessionAdminId]);
       if ((int) $checkAdmin->fetchColumn() === 1) {
           header('Location: index.php');
           exit;
       }
   }
   unset($_SESSION['logged_in'], $_SESSION['user_id']);
}

// Redireciona para setup se não houver usuário
$stmt = $db->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() == 0) {
    header('Location: setup.php');
    exit;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Informe o usuário e a senha.";
    } else {
        $stmt = $db->prepare("SELECT id, password, admin FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && (int) $user['admin'] === 1 && verifyUserPassword($db, $user, $password)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = (int) $user['id'];
            header('Location: index.php');
            exit;
        } else {
            $error = "Usuário ou senha incorretos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101b35">
    <title>Login - IBO Admin</title>
    <link rel="stylesheet" href="assets/admin-login.css?v=20261025-2">
</head>
<body class="auth-page">
    <main class="admin-login-shell">
        <section class="admin-login-card" aria-label="Acesso administrativo">
            <aside class="admin-login-aside">
                <a class="admin-login-brand" href="login.php" aria-label="IBO Admin">
                    <span class="admin-brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <path d="M24 4 43 15v18L24 44 5 33V15L24 4Z" fill="currentColor" fill-opacity=".16"/>
                            <path d="M18 14h7.8c5.1 0 8.1 2.2 8.1 6 0 2.3-1.3 4-3.6 4.9 3.1.7 4.9 2.8 4.9 5.7 0 4.4-3.5 7-9.2 7H18V14Zm7.3 9.3c2 0 3.1-.8 3.1-2.4s-1.1-2.3-3.1-2.3h-1.9v4.7h1.9Zm.8 9.1c2.3 0 3.6-.9 3.6-2.6s-1.3-2.6-3.6-2.6h-2.7v5.2h2.7Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span><strong>IBO</strong><small>PAINEL ADMINISTRATIVO</small></span>
                </a>
                <div class="admin-login-promo">
                    <span class="promo-eyebrow">GESTÃO EM UM SÓ LUGAR</span>
                    <h1>Seu painel.<br>Seu controle.</h1>
                    <p>Acesse as ferramentas de administração e acompanhe sua operação com praticidade.</p>
                    <div class="promo-decoration" aria-hidden="true">
                        <span class="promo-orbit orbit-one"></span>
                        <span class="promo-orbit orbit-two"></span>
                        <span class="promo-glow"></span>
                        <span class="promo-tile tile-one">▦</span>
                        <span class="promo-tile tile-two">✓</span>
                        <span class="promo-tile tile-three">↗</span>
                    </div>
                </div>
                <div class="admin-login-aside-footer"><span class="status-dot"></span> Ambiente administrativo protegido</div>
            </aside>

            <div class="admin-login-content">
                <div class="login-mobile-brand">
                    <span class="admin-brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <path d="M24 4 43 15v18L24 44 5 33V15L24 4Z" fill="currentColor" fill-opacity=".16"/>
                            <path d="M18 14h7.8c5.1 0 8.1 2.2 8.1 6 0 2.3-1.3 4-3.6 4.9 3.1.7 4.9 2.8 4.9 5.7 0 4.4-3.5 7-9.2 7H18V14Zm7.3 9.3c2 0 3.1-.8 3.1-2.4s-1.1-2.3-3.1-2.3h-1.9v4.7h1.9Zm.8 9.1c2.3 0 3.6-.9 3.6-2.6s-1.3-2.6-3.6-2.6h-2.7v5.2h2.7Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <strong>IBO Admin</strong>
                </div>
                <div class="login-form-wrap">
                    <span class="login-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M7 10V7a5 5 0 0 1 10 0v3M5.5 10h13v10h-13z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 14v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span class="login-eyebrow">BEM-VINDO DE VOLTA</span>
                    <h2>Entrar no painel</h2>
                    <p class="login-description">Informe suas credenciais de administrador para continuar.</p>
                    <?php if ($error): ?>
                        <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" class="admin-login-form">
                        <label for="username">Usuário</label>
                        <div class="login-input-wrap">
                            <span aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </span>
                            <input id="username" type="text" name="username" placeholder="Seu usuário" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="username" required autofocus>
                        </div>
                        <label for="password">Senha</label>
                        <div class="login-input-wrap password-input-wrap">
                            <span aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0 1 10 0v3M5.5 10h13v10h-13z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <input id="password" type="password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
                            <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
                        </div>
                        <button class="login-submit" type="submit">Entrar com segurança <span aria-hidden="true">→</span></button>
                    </form>
                    <div class="login-security-note"><span aria-hidden="true">♢</span> Suas credenciais são protegidas</div>
                </div>
                <footer class="admin-login-footer">© <?= date('Y') ?> IBO Admin <span>·</span> Acesso restrito</footer>
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