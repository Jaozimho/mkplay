<?php
session_start();
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$referralCode = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
$referrerQuery = $db->prepare('
    SELECT id, name
    FROM users
    WHERE referral_code = ? AND COALESCE(admin, 0) <> 1
');
$referrerQuery->execute([$referralCode]);
$referrer = preg_match('/^[a-f0-9]{24}$/i', $referralCode) === 1
    ? $referrerQuery->fetch()
    : false;
$referrerLogoUrl = $referrer !== false
    ? getAccountLogoUrl($db, (int) $referrer['id'])
    : '';

$error = '';
$success = '';
if (empty($_SESSION['reseller_register_csrf'])) {
    $_SESSION['reseller_register_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_register_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($referrer === false) {
        http_response_code(404);
        $error = 'Este link de indicação é inválido ou não está mais disponível.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        try {
            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException('Informe seu nome (até 255 caracteres).');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                throw new InvalidArgumentException('O usuário deve ter de 3 a 64 caracteres: letras, números, ponto, hífen ou sublinhado.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
            }

            $db->beginTransaction();
            $verifyReferrer = $db->prepare('
                SELECT id FROM users
                WHERE id = ? AND referral_code = ? AND COALESCE(admin, 0) <> 1
            ');
            $verifyReferrer->execute([(int) $referrer['id'], $referralCode]);
            if ($verifyReferrer->fetchColumn() === false) {
                throw new InvalidArgumentException('Este link de indicação não está mais disponível.');
            }
            $create = $db->prepare('
                INSERT INTO users (
                    name, username, password, admin, store_type, mac_amount, parent_user_id,
                    referred_by_user_id, referral_code
                ) VALUES (?, ?, ?, 0, 1, 0, ?, ?, ?)
            ');
            $create->execute([
                $name,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                (int) $referrer['id'],
                (int) $referrer['id'],
                createReferralCode($db),
            ]);
            $newResellerId = (int) $db->lastInsertId();
            $db->commit();
            session_regenerate_id(true);
            $_SESSION['reseller_user_id'] = $newResellerId;
            $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['reseller_created_message'] = 'Conta criada com sucesso. Agora você já pode começar a usar seu painel.';
            unset($_SESSION['reseller_register_csrf']);
            header('Location: index.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                $error = 'Este nome de usuário já está em uso.';
            } else {
                error_log('Referral registration failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Não foi possível concluir o cadastro. Tente novamente.';
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Referral registration failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Não foi possível concluir o cadastro. Tente novamente.';
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
    <meta name="theme-color" content="#101b35">
    <title>Cadastro de revenda | Área da revenda</title>
    <link rel="stylesheet" href="assets/register.css?v=register-partner-20261025-4">
</head>
<body class="register-page">
<main class="register-layout">
    <section class="register-story" aria-label="Benefícios da área de revenda">
        <div class="brand" aria-label="<?= $referrer !== false ? 'Logo da revenda ' . $escape($referrer['name']) : 'Área da revenda' ?>">
            <?php if ($referrerLogoUrl !== ''): ?>
                <img class="brand-logo" src="<?= $escape($referrerLogoUrl) ?>" alt="Logo da revenda <?= $escape($referrer['name']) ?>">
                <span class="brand-fallback"><?= $escape($referrer['name']) ?></span>
            <?php else: ?>
                <span class="brand-fallback">Revenda</span>
            <?php endif; ?>
        </div>
        <div class="story-copy">
            <span class="eyebrow"><span class="eyebrow-dot"></span> SEJA UM PARCEIRO</span>
            <h1>Seu negócio.<br>Suas regras.<br><span>Seu crescimento.</span></h1>
            <p>Crie sua conta de revenda e gerencie seus dispositivos e créditos em um só lugar.</p>
        </div>
        <div class="credit-preview" aria-label="Você controla seus créditos MAC">
            <div class="preview-icon" aria-hidden="true">↗</div>
            <div>
                <strong>Comece no seu ritmo</strong>
                <span>Adicione créditos quando estiver pronto</span>
            </div>
            <div class="preview-orbit orbit-one"></div>
            <div class="preview-orbit orbit-two"></div>
        </div>
        <div class="story-footer">
            <span class="footer-spark" aria-hidden="true">✦</span>
            <span>Uma plataforma simples para sua operação crescer.</span>
        </div>
        <span class="decor decor-one" aria-hidden="true"></span>
        <span class="decor decor-two" aria-hidden="true"></span>
    </section>

    <section class="register-content">
        <div class="register-card">
            <div class="card-heading">
                <span class="form-kicker">CADASTRO DE PARCEIRO</span>
                <h2>Criar conta de revenda</h2>
                <p>Preencha seus dados para acessar seu painel.</p>
            </div>

            <?php if ($referrer !== false): ?>
                <div class="referral-note">
                    <span class="referral-icon" aria-hidden="true">✓</span>
                    <span>Você foi convidado por <strong><?= $escape($referrer['name']) ?></strong>.</span>
                </div>
                <p class="credit-note">Sua conta começa sem créditos. Você poderá comprar um lote pelo painel quando quiser.</p>
            <?php else: ?>
                <div class="message error" role="alert">
                    <?= $error !== '' ? $escape($error) : 'Este link de indicação é inválido ou não está mais disponível.' ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== '' && $referrer !== false): ?>
                <div class="message error" role="alert"><?= $escape($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="message success" role="status">
                    <span class="success-icon" aria-hidden="true">✓</span>
                    <span><?= $escape($success) ?></span>
                </div>
                <a class="submit-button success-button" href="login.php">Ir para o login <span aria-hidden="true">→</span></a>
            <?php elseif ($referrer !== false): ?>
                <form class="register-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_register_csrf']) ?>">
                    <input type="hidden" name="ref" value="<?= $escape($referralCode) ?>">
                    <label for="register-name">Nome completo</label>
                    <input id="register-name" name="name" maxlength="255" autocomplete="name" placeholder="Como podemos chamar você?" required>

                    <label for="register-username">Nome de usuário</label>
                    <input id="register-username" name="username" minlength="3" maxlength="64" autocomplete="username" autocapitalize="none" placeholder="Escolha seu usuário" required>
                    <span class="field-hint">De 3 a 64 caracteres. Use letras, números, ponto, hífen ou _</span>

                    <label for="register-password">Senha</label>
                    <input id="register-password" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Crie uma senha segura" required>
                    <span class="field-hint">Use pelo menos 8 caracteres.</span>

                    <button class="submit-button" type="submit">Criar minha conta <span aria-hidden="true">→</span></button>
                </form>
                <p class="login-prompt">Já tem uma conta? <a href="login.php">Entrar no painel</a></p>
            <?php endif; ?>
        </div>
        <p class="content-footer">Ao criar sua conta, você poderá acessar sua área exclusiva de revenda.</p>
    </section>
</main>
<script>
    document.querySelectorAll('.brand-logo').forEach(function (logo) {
        logo.addEventListener('error', function () {
            logo.hidden = true;
            logo.nextElementSibling.hidden = false;
        });
    });
</script>
</body>
</html>
