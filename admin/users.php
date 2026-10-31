<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = (string) ($_SESSION['users_success_flash'] ?? '');
unset($_SESSION['users_success_flash']);
$newAccountCredentials = $_SESSION['new_reseller_credentials'] ?? null;
unset($_SESSION['new_reseller_credentials']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['users_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if ($action === 'save_logo') {
                if ($id === false || $id === null || $id < 1 || getManagedUser($db, $id) === null) {
                    throw new InvalidArgumentException('Selecione uma conta de revenda válida.');
                }
                $logoPath = saveAccountLogo($_FILES['logo'] ?? [], $id);
                $saveLogo = $db->prepare('UPDATE users SET logo = ? WHERE id = ? AND COALESCE(admin, 0) <> 1');
                $saveLogo->execute([$logoPath, $id]);
                $success = 'Logo do APK da conta atualizada.';
            } elseif ($action === 'set_apk_default_reseller') {
                $resellerInput = trim((string) ($_POST['default_reseller_id'] ?? ''));
                if ($resellerInput !== '') {
                    $resellerId = filter_var($resellerInput, FILTER_VALIDATE_INT);
                    if ($resellerId === false || $resellerId < 1 || getManagedUser($db, $resellerId) === null) {
                        throw new InvalidArgumentException('Selecione uma revenda válida.');
                    }
                } else {
                    $resellerId = null;
                }
                $saveSetting = $db->prepare("
                    INSERT INTO app_settings (setting_key, setting_value)
                    VALUES ('apk_default_reseller_id', ?)
                    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
                ");
                $saveSetting->execute([$resellerId === null ? '' : (string) $resellerId]);
                $success = 'Revenda padrão do APK atualizada.';
            } elseif ($action === 'delete') {
                if ($id === false || $id === null || $id < 1) {
                    throw new InvalidArgumentException('Revenda inválida.');
                }
                if (!deleteManagedUser($db, $id)) {
                    throw new InvalidArgumentException('A conta tem dispositivos, sub-revendas ou histórico financeiro vinculado e não pode ser excluída.');
                }
                $success = 'Revenda excluída.';
            } elseif ($action === 'create' || $action === 'update') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $storeType = filter_input(INPUT_POST, 'store_type', FILTER_VALIDATE_INT);
                $macAmount = filter_input(INPUT_POST, 'mac_amount', FILTER_VALIDATE_INT);

                if ($name === '' || strlen($name) > 255) {
                    throw new InvalidArgumentException('Informe o nome da revenda (até 255 caracteres).');
                }
                if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                    throw new InvalidArgumentException('O usuário deve ter de 3 a 64 caracteres: letras, números, ponto, hífen ou sublinhado.');
                }
                if (!in_array($storeType, [1, 2], true)) {
                    throw new InvalidArgumentException('Selecione um tipo de revenda válido.');
                }
                if ($macAmount === false || $macAmount === null || $macAmount < 0) {
                    throw new InvalidArgumentException('Informe uma quantidade válida de MACs.');
                }
                if ($action === 'create' && strlen($password) < 8) {
                    throw new InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
                }
                if ($action === 'update' && $password !== '' && strlen($password) < 8) {
                    throw new InvalidArgumentException('A nova senha deve ter pelo menos 8 caracteres.');
                }

                $db->beginTransaction();
                $managedUser = null;
                $parentUserId = null;
                if ($action === 'create') {
                    $parentInput = trim((string) ($_POST['parent_user_id'] ?? ''));
                    if ($parentInput !== '') {
                        $parentUserId = filter_var($parentInput, FILTER_VALIDATE_INT);
                        if ($parentUserId === false || $parentUserId < 1 || getManagedUser($db, $parentUserId) === null) {
                            throw new InvalidArgumentException('Selecione uma conta superior válida.');
                        }
                        $lockQuota = $db->prepare('UPDATE users SET mac_amount = mac_amount WHERE id = ?');
                        $lockQuota->execute([$parentUserId]);
                        $parentQuota = getResellerQuotaSummary($db, $parentUserId);
                        if ($macAmount < 10 || $macAmount % 10 !== 0) {
                            throw new InvalidArgumentException('Sub-revendas recebem cotas em blocos de 10 MACs, com mínimo de 10.');
                        }
                        if ($parentQuota['available'] < $macAmount + 10) {
                            throw new InvalidArgumentException('A conta superior precisa ter a cota distribuída e mais 10 MACs livres para uso próprio.');
                        }
                    }
                } else {
                    if ($id === false || $id === null || $id < 1 || ($managedUser = getManagedUser($db, $id)) === null) {
                        throw new InvalidArgumentException('Revenda inválida.');
                    }
                    $parentUserId = $managedUser['parent_user_id'] === null
                        ? null
                        : (int) $managedUser['parent_user_id'];
                    if ($parentUserId !== null) {
                        $lockQuota = $db->prepare('UPDATE users SET mac_amount = mac_amount WHERE id = ?');
                        $lockQuota->execute([$parentUserId]);
                    }
                    $currentQuota = getResellerQuotaSummary($db, (int) $managedUser['id']);
                    $quotaChanged = $macAmount !== (int) $managedUser['mac_amount'];
                    if ($quotaChanged) {
                        $minimumRequired = $currentQuota['active_devices'] + $currentQuota['allocated_to_children'];
                        if ($currentQuota['allocated_to_children'] > 0) {
                            $minimumRequired += 10;
                        }
                        if ($macAmount < $minimumRequired) {
                            throw new InvalidArgumentException('O limite deve cobrir os MACs ativos, as cotas distribuídas e reservar 10 MACs para uso próprio quando houver sub-revendas.');
                        }
                    }
                    if ($parentUserId !== null && $quotaChanged) {
                        if ($macAmount < 10 || $macAmount % 10 !== 0) {
                            throw new InvalidArgumentException('A cota de uma sub-revenda deve permanecer em blocos de 10 MACs, com mínimo de 10.');
                        }
                        $parentQuota = getResellerQuotaSummary($db, $parentUserId);
                        $parentAvailableAfterUpdate = $parentQuota['available']
                            + (int) $managedUser['mac_amount']
                            - $macAmount;
                        if ($parentAvailableAfterUpdate < 10) {
                            throw new InvalidArgumentException('A conta superior precisa manter pelo menos 10 MACs livres para uso próprio.');
                        }
                    }
                }

                $user = [
                    'name' => $name,
                    'username' => $username,
                    'password' => $password === '' ? null : password_hash($password, PASSWORD_DEFAULT),
                    'store_type' => $storeType,
                    'mac_amount' => $macAmount,
                ];
                if ($action === 'create') {
                    saveManagedUser($db, $user, null, $parentUserId);
                    $newUserId = (int) $db->lastInsertId();
                    $newUserQuery = $db->prepare('SELECT referral_code FROM users WHERE id = ?');
                    $newUserQuery->execute([$newUserId]);
                    $newAccountCredentials = [
                        'name' => $name,
                        'username' => $username,
                        'password' => $password,
                        'login_url' => getPublicBaseUrl() . '/reseller/login.php',
                        'mac_amount' => $macAmount,
                        'referral_url' => resellerReferralUrl((string) $newUserQuery->fetchColumn()),
                        'account_type' => $parentUserId === null ? 'Revenda' : 'Sub-revenda',
                    ];
                    $_SESSION['new_reseller_credentials'] = $newAccountCredentials;
                    $success = $parentUserId === null
                        ? 'Revenda criada.'
                        : 'Sub-revenda criada e cota distribuída.';
                } else {
                    saveManagedUser($db, $user, $id, $parentUserId);
                    $success = 'Revenda atualizada.';
                }
                $db->commit();
            } else {
                throw new InvalidArgumentException('Ação inválida.');
            }

            $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
            if ($action === 'create' && is_array($newAccountCredentials)) {
                $_SESSION['users_success_flash'] = $success;
                header('Location: users.php?created=1');
                exit;
            }
            if ($action === 'update') {
                $_SESSION['users_success_flash'] = $success;
                header('Location: users.php?updated=1');
                exit;
            }
            if ($action === 'delete') {
                $_SESSION['users_success_flash'] = $success;
                header('Location: users.php?deleted=1');
                exit;
            }
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_reseller_credentials']);
            $newAccountCredentials = null;
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_reseller_credentials']);
            $newAccountCredentials = null;
            error_log('Admin user management database error: ' . $e->getMessage());
            http_response_code(500);
            $error = $action === 'set_apk_default_reseller'
                ? 'Não foi possível salvar a configuração da API do APK.'
                : 'Não foi possível salvar a revenda. Verifique se o nome de usuário já está em uso.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_reseller_credentials']);
            $newAccountCredentials = null;
            error_log('Admin reseller operation failed: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível concluir a operação da conta.';
        }
    }
}

$users = getManagedUsers($db);
foreach ($users as &$managedUserRow) {
    $managedUserRow['quota_available'] = getResellerQuotaSummary($db, (int) $managedUserRow['id'])['available'];
}
unset($managedUserRow);
$editRequested = array_key_exists('edit', $_GET);
$editUser = null;
if ($editRequested) {
    $editIdInput = $_GET['edit'];
    $editId = is_string($editIdInput) ? filter_var($editIdInput, FILTER_VALIDATE_INT) : false;
    if ($editId !== false && $editId !== null && $editId > 0) {
        foreach ($users as $managedUserRow) {
            if ((int) $managedUserRow['id'] === $editId) {
                $editUser = $managedUserRow;
                break;
            }
        }
    }
    if ($editUser === null) {
        http_response_code(404);
        $error = 'Revenda não encontrada.';
    }
}
$defaultResellerQuery = $db->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'apk_default_reseller_id'
");
$defaultResellerQuery->execute();
$defaultResellerId = (string) ($defaultResellerQuery->fetchColumn() ?: '');

$latestApiRequest = null;
$latestApiOutcome = null;
$apiLogFiles = [
    dirname(__DIR__) . '/api/logs/api.log.1',
    dirname(__DIR__) . '/api/logs/api.log',
];
foreach ($apiLogFiles as $apiLogFile) {
    if (!is_file($apiLogFile)) {
        continue;
    }
    $apiLogContents = file_get_contents($apiLogFile);
    if ($apiLogContents === false) {
        error_log('Admin users page could not read API request log: ' . $apiLogFile);
        continue;
    }
    foreach (explode("\n", $apiLogContents) as $apiLogLine) {
        $apiLogEntry = json_decode($apiLogLine, true);
        if (!is_array($apiLogEntry) || ($apiLogEntry['endpoint'] ?? '') !== 'getappuser.php') {
            continue;
        }
        if (($apiLogEntry['event'] ?? '') === 'request_received') {
            $latestApiRequest = $apiLogEntry;
            $latestApiOutcome = null;
        } elseif (
            $latestApiRequest !== null
            && ($apiLogEntry['request_id'] ?? '') === ($latestApiRequest['request_id'] ?? null)
        ) {
            $latestApiOutcome = $apiLogEntry;
        }
    }
}

$apiStatusClass = 'unknown';
$apiStatusText = 'Sem comunicação registrada';
$apiResultText = 'Quando o APK consultar a API, o último contato aparecerá aqui.';
$apiTimestamp = '';
if ($latestApiRequest !== null) {
    $apiTimestampRaw = (string) ($latestApiRequest['time'] ?? '');
    $apiTimestampParsed = strtotime($apiTimestampRaw);
    $apiTimestamp = $apiTimestampParsed === false
        ? $apiTimestampRaw
        : date('d/m/Y H:i:s', $apiTimestampParsed);
    $apiOutcomeEvent = (string) ($latestApiOutcome['event'] ?? '');
    if ($apiOutcomeEvent === 'request_processed') {
        $apiStatusClass = 'success';
        $apiStatusText = 'Comunicação concluída';
        $apiResultText = 'A API recebeu e processou a solicitação do APK.';
    } elseif ($apiOutcomeEvent === 'request_rejected') {
        $apiStatusClass = 'warning';
        $apiStatusText = 'Solicitação rejeitada';
        $apiResultText = 'A API recebeu a solicitação, mas rejeitou os dados enviados.';
    } elseif ($apiOutcomeEvent === 'request_failed' || $apiOutcomeEvent === 'php_fatal') {
        $apiStatusClass = 'error';
        $apiStatusText = 'Falha no processamento';
        $apiResultText = 'A API recebeu a solicitação, mas não conseguiu concluí-la.';
    } else {
        $apiStatusClass = 'warning';
        $apiStatusText = 'Solicitação recebida';
        $apiResultText = 'Não há resultado final registrado para a última solicitação.';
    }
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $editRequested ? 'Editar revenda' : 'Usuários e revendas' ?> | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 30px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 1180px; margin: auto; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        h2 { margin: 0 0 16px; font-size: 17px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { margin-bottom: 14px; padding: 16px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .fields { display: grid; grid-template-columns: repeat(3, minmax(130px, 1fr)); gap: 9px; }
        label { display: block; margin-bottom: 10px; color: #475467; font-size: 12px; }
        input, select { display: block; width: 100%; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        .help { margin: 0 0 14px; color: #667085; line-height: 1.5; }
        .message { margin-bottom: 14px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .empty { padding: 26px; color: #667085; text-align: center; }
        .page-head { display: block; }
        .page-head p { margin: 8px 0 0; color: #667085; }
        .api-panel { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, .9fr); gap: 22px; align-items: center; }
        .api-endpoint { display: inline-block; margin-top: 8px; padding: 5px 8px; border-radius: 5px; background: #f2f4f7; color: #344054; font-family: Consolas, monospace; font-size: 12px; }
        .api-status { display: inline-flex; align-items: center; margin-top: 10px; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .api-status.success { background: #ecfdf3; color: #067647; }
        .api-status.warning { background: #fffaeb; color: #b54708; }
        .api-status.error { background: #fef3f2; color: #b42318; }
        .api-status.unknown { background: #f2f4f7; color: #475467; }
        .api-result { margin: 8px 0 0; color: #475467; line-height: 1.5; }
        .api-time { display: block; margin-top: 6px; color: #667085; font-size: 12px; }
        .setting-form { padding-left: 22px; border-left: 1px solid #eaecf0; }
        .setting-form button { margin-top: 3px; }
        .reseller-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 16px; }
        .reseller-toolbar h2 { margin: 0; }
        .search { width: min(100%, 280px); }
        .reseller-list { display: grid; gap: 12px; }
        .reseller-card { padding: 12px; border: 1px solid #eaecf0; border-radius: 10px; }
        .reseller-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .reseller-name { margin: 0; color: #101828; font-size: 16px; }
        .reseller-login { margin: 5px 0 0; color: #667085; }
        .type-badge { flex: 0 0 auto; padding: 5px 9px; border-radius: 999px; background: #eff4ff; color: #3157d5; font-size: 12px; font-weight: 600; }
        .reseller-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 10px 0; }
        .stat { padding: 8px 10px; border-radius: 8px; background: #f9fafb; }
        .stat strong { display: block; color: #101828; font-size: 15px; }
        .stat span { display: block; margin-top: 3px; color: #667085; font-size: 11px; }
        .edit-account-link { display: block; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eaecf0; font-weight: 600; }
        .edit-account-link:hover { text-decoration: underline; }
        .edit-actions { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; }
        .access-card { border-color: #b2ddff; background: #f5faff; }
        .access-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .access-head h2 { margin-bottom: 4px; }
        .access-head .help { margin: 0; }
        .access-card pre { margin: 12px 0 0; padding: 12px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; color: #344054; font: 13px/1.55 Consolas, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
        .account-logo { display: block; width: min(100%, 160px); max-height: 100px; margin: 10px 0; object-fit: contain; border: 1px solid #eaecf0; border-radius: 7px; background: #fff; }
        .reseller-card .account-logo { width: 96px; max-height: 64px; }
        .account-background { display: block; width: min(100%, 240px); max-height: 110px; margin: 8px 0; border: 1px solid #eaecf0; border-radius: 7px; object-fit: cover; }
        .reseller-card .account-background { width: 160px; max-height: 72px; }
        .logo-status { display: inline-block; margin-top: 8px; color: #667085; font-size: 12px; }
        @media (max-width: 900px) { .fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 720px) { .api-panel { grid-template-columns: 1fr; } .setting-form { padding: 17px 0 0; border-left: 0; border-top: 1px solid #eaecf0; } }
        @media (max-width: 560px) { body { padding: 18px 10px; } header, .access-head { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } .reseller-toolbar, .reseller-head { align-items: stretch; flex-direction: column; } .search { width: 100%; } .reseller-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page users-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="page-head">
        <h1><?= $editRequested ? 'Editar revenda' : 'Usuários e revendas' ?></h1>
        <?php if ($editRequested && $editUser !== null): ?>
            <p>Atualize os dados, a senha e a logo desta conta.</p>
            <a href="users.php">Voltar para usuários e revendas</a>
        <?php else: ?>
            <p>Gerencie contas, limites de dispositivos e a integração do APK.</p>
            <a href="index.php">Voltar ao dashboard</a>
        <?php endif; ?>
    </header>
    <?php if ($error !== ''): ?><div class="message error"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= $escape($success) ?></div><?php endif; ?>

    <?php if ($editRequested): ?>
        <?php if ($editUser !== null): ?>
            <?php
            $editLogoPath = getAccountBrandingPath($db, (int) $editUser['id'], 'logo');
            $editLogoUrl = $editLogoPath === '' ? '' : getPublicBaseUrl() . $editLogoPath;
            $editType = (int) $editUser['store_type'];
            ?>
            <section class="panel">
                <h2><?= $escape($editUser['name']) ?> <span class="type-badge"><?= $editType === 2 ? 'Premium' : 'Revenda' ?></span></h2>
                <p class="help">Usuário: @<?= $escape($editUser['username']) ?> · ID #<?= (int) $editUser['id'] ?></p>
                <form method="post" action="users.php?edit=<?= (int) $editUser['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['users_csrf']) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
                    <div class="fields">
                        <label>Nome<input name="name" maxlength="255" value="<?= $escape($editUser['name']) ?>" required></label>
                        <label>Usuário<input name="username" minlength="3" maxlength="64" value="<?= $escape($editUser['username']) ?>" required></label>
                        <label>Nova senha (opcional)<input name="password" type="password" minlength="8" autocomplete="new-password"></label>
                        <label>Tipo
                            <select name="store_type">
                                <option value="1" <?= $editType === 1 ? 'selected' : '' ?>>Revenda</option>
                                <option value="2" <?= $editType === 2 ? 'selected' : '' ?>>Premium</option>
                            </select>
                        </label>
                        <label>Limite de MACs<input name="mac_amount" type="number" min="0" step="1" value="<?= (int) $editUser['mac_amount'] ?>" required></label>
                    </div>
                    <div class="edit-actions"><button type="submit">Salvar alterações</button></div>
                </form>
            </section>

            <section class="panel">
                <h2>Logo do APK</h2>
                <p class="help">Envie uma imagem PNG, JPG ou WebP de até 2 MB para esta conta.</p>
                <form method="post" action="users.php?edit=<?= (int) $editUser['id'] ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['users_csrf']) ?>">
                    <input type="hidden" name="action" value="save_logo">
                    <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
                    <label>Selecionar logo
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
                    </label>
                    <?php if ($editLogoUrl !== ''): ?>
                        <img class="account-logo" src="<?= $escape($editLogoUrl) ?>" alt="Logo do APK de <?= $escape($editUser['name']) ?>">
                    <?php endif; ?>
                    <button type="submit">Salvar logo do APK</button>
                </form>
            </section>

            <section class="panel">
                <h2>Excluir conta</h2>
                <p class="help">Contas com dispositivos, sub-revendas ou histórico financeiro vinculado não podem ser removidas.</p>
                <form class="edit-actions" method="post" action="users.php?edit=<?= (int) $editUser['id'] ?>" onsubmit="return confirm('Excluir esta revenda? Contas com dispositivos vinculados não podem ser removidas.');">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['users_csrf']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
                    <button class="danger" type="submit">Excluir revenda</button>
                </form>
            </section>
        <?php endif; ?>
    <?php else: ?>
    <section class="panel api-panel">
        <div>
            <h2>Conexão com o APK teste</h2>
            <p class="help">Endpoint usado pelo aplicativo para registrar ou carregar um dispositivo:</p>
            <code class="api-endpoint">/api/getappuser.php</code><br>
            <span class="api-status <?= $escape($apiStatusClass) ?>"><?= $escape($apiStatusText) ?></span>
            <p class="api-result"><?= $escape($apiResultText) ?></p>
            <?php if ($apiTimestamp !== ''): ?><time class="api-time"><?= $escape($apiTimestamp) ?></time><?php endif; ?>
        </div>
        <form class="setting-form" method="post">
            <h2>Revenda padrão para novos aparelhos</h2>
            <p class="help">Novos MACs recebidos pelo APK serão vinculados a esta conta. Se a cota estiver cheia, a API recusa o cadastro. Dispositivos existentes não são alterados.</p>
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['users_csrf']) ?>">
            <input type="hidden" name="action" value="set_apk_default_reseller">
            <label>Conta que receberá novos dispositivos
                <select name="default_reseller_id">
                    <option value="">Admin (sem revenda)</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $defaultResellerId === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= $escape($user['name'] . ' (@' . $user['username'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Salvar configuração do APK</button>
        </form>
    </section>

    <?php if (is_array($newAccountCredentials)): ?>
        <?php
        $credentialBlock = implode("\n", [
            'Acesso ao painel — ' . $newAccountCredentials['account_type'],
            'Nome: ' . $newAccountCredentials['name'],
            'Usuário: ' . $newAccountCredentials['username'],
            'Senha inicial: ' . $newAccountCredentials['password'],
            'Painel: ' . $newAccountCredentials['login_url'],
            'MACs liberados: ' . $newAccountCredentials['mac_amount'],
            'Link de indicação: ' . $newAccountCredentials['referral_url'],
        ]);
        ?>
        <section class="panel access-card">
            <div class="access-head">
                <div><h2>Conta criada — dados para enviar</h2><p class="help">Copie este bloco agora; a senha inicial não será mostrada novamente.</p></div>
                <button type="button" id="copy-reseller-access">Copiar dados</button>
            </div>
            <pre id="reseller-access-text"><?= $escape($credentialBlock) ?></pre>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Criar revenda ou sub-revenda</h2>
        <p class="help">As contas usam a tabela central <code>users</code> e acessam somente a <a href="../reseller/login.php">Área da revenda</a>, sem permissão administrativa.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['users_csrf']) ?>">
            <input type="hidden" name="action" value="create">
            <div class="fields">
                <label>Nome<input name="name" maxlength="255" required></label>
                <label>Usuário<input name="username" minlength="3" maxlength="64" required></label>
                <label>Senha inicial<input name="password" type="password" minlength="8" autocomplete="new-password" required></label>
                <label>Tipo<select name="store_type"><option value="1">Revenda</option><option value="2">Premium</option></select></label>
                <label>Limite de MACs<input name="mac_amount" type="number" min="0" step="1" value="1" required></label>
                <label>Conta superior
                    <select name="parent_user_id">
                        <option value="">Conta principal (sem superior)</option>
                        <?php foreach ($users as $parentUser): ?>
                            <option value="<?= (int) $parentUser['id'] ?>" data-available="<?= (int) $parentUser['quota_available'] ?>" <?= $parentUser['quota_available'] < 20 ? 'disabled' : '' ?>><?= $escape($parentUser['name'] . ' (@' . $parentUser['username'] . ') · saldo ' . $parentUser['quota_available']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="help" id="quota-help">A conta principal pode atribuir qualquer quantidade. Ao escolher uma conta superior, a sub-revenda recebe em blocos de 10 MACs e a superior mantém pelo menos 10 livres.</p>
            <button type="submit">Criar revenda</button>
        </form>
    </section>

    <section class="panel">
        <div class="reseller-toolbar">
            <h2>Contas cadastradas (<?= count($users) ?>)</h2>
            <?php if ($users !== []): ?><input class="search" id="reseller-search" type="search" placeholder="Buscar nome ou usuário" aria-label="Buscar revenda"><?php endif; ?>
        </div>
        <?php if ($users === []): ?>
            <div class="empty">Nenhuma revenda cadastrada. Use o formulário acima para criar a primeira conta.</div>
        <?php else: ?>
            <div class="reseller-list">
                <?php foreach ($users as $user): ?>
                    <?php
                    $userType = (int) $user['store_type'] === 2 ? 'Premium' : 'Revenda';
                    $activeCount = (int) $user['active_device_count'];
                    $macLimit = (int) $user['mac_amount'];
                    $searchText = strtolower($user['name'] . ' ' . $user['username']);
                    $accountLogoPath = getAccountBrandingPath($db, (int) $user['id'], 'logo');
                    $accountLogoUrl = $accountLogoPath === '' ? '' : getPublicBaseUrl() . $accountLogoPath;
                    $accountBackgroundPath = getAccountBrandingPath($db, (int) $user['id'], 'background_image');
                    $accountBackgroundUrl = $accountBackgroundPath === '' ? '' : getPublicBaseUrl() . $accountBackgroundPath;
                    ?>
                    <article class="reseller-card" data-search="<?= $escape($searchText) ?>">
                        <div class="reseller-head">
                            <div>
                                <h3 class="reseller-name"><?= $escape($user['name']) ?></h3>
                                <p class="reseller-login">@<?= $escape($user['username']) ?> · ID #<?= (int) $user['id'] ?></p>
                                <?php if ($accountLogoUrl !== ''): ?>
                                    <img class="account-logo" src="<?= $escape($accountLogoUrl) ?>" alt="Logo do APK de <?= $escape($user['name']) ?>">
                                <?php else: ?>
                                    <span class="logo-status">Sem logo do APK cadastrada</span>
                                <?php endif; ?>
                                <?php if ($accountBackgroundUrl !== ''): ?>
                                    <img class="account-background" src="<?= $escape($accountBackgroundUrl) ?>" alt="Fundo do aplicativo de <?= $escape($user['name']) ?>">
                                <?php endif; ?>
                            </div>
                            <span class="type-badge"><?= $userType ?></span>
                        </div>
                        <div class="reseller-stats">
                            <div class="stat"><strong><?= (int) $user['device_count'] ?></strong><span>Dispositivos cadastrados</span></div>
                            <div class="stat"><strong><?= $activeCount ?> / <?= $macLimit ?></strong><span>MACs ativos / limite</span></div>
                            <div class="stat"><strong><?= (int) $user['bonus_mac_amount'] ?></strong><span>MACs de bônus comprados/indicados</span></div>
                            <div class="stat"><strong><?= (int) $user['quota_available'] ?></strong><span>MACs disponíveis para ativar ou distribuir</span></div>
                            <div class="stat"><strong><?= $escape($user['parent_name'] ?: 'Conta principal') ?></strong><span>Conta superior</span></div>
                            <div class="stat"><strong><?= $defaultResellerId === (string) $user['id'] ? 'Padrão do APK' : 'Manual' ?></strong><span>Origem de novos dispositivos</span></div>
                        </div>
                        <label>Link exclusivo de indicação
                            <input type="url" value="<?= $escape(resellerReferralUrl((string) $user['referral_code'])) ?>" readonly onclick="this.select()">
                        </label>
                        <p class="help">Pontos: <?= (int) $user['referral_points'] ?> · Comissão Pix disponível: R$ <?= number_format((int) $user['referral_cash_cents'] / 100, 2, ',', '.') ?></p>
                        <a class="edit-account-link" href="users.php?edit=<?= (int) $user['id'] ?>">Editar dados da revenda</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<script>
    const resellerSearch = document.getElementById('reseller-search');
    const resellerCards = Array.from(document.querySelectorAll('.reseller-card'));
    if (resellerSearch) {
        resellerSearch.addEventListener('input', () => {
            const query = resellerSearch.value.trim().toLocaleLowerCase('pt-BR');
            resellerCards.forEach((card) => {
                card.hidden = !card.dataset.search.includes(query);
            });
        });
    }
</script>
<script>
    const copyAccessButton = document.getElementById('copy-reseller-access');
    if (copyAccessButton) {
        copyAccessButton.addEventListener('click', async () => {
            const text = document.getElementById('reseller-access-text').textContent;
            try {
                await navigator.clipboard.writeText(text);
                copyAccessButton.textContent = 'Copiado';
            } catch (error) {
                const selection = window.getSelection();
                const range = document.createRange();
                range.selectNodeContents(document.getElementById('reseller-access-text'));
                selection.removeAllRanges();
                selection.addRange(range);
                copyAccessButton.textContent = 'Texto selecionado para copiar';
            }
        });
    }
    const accountForm = document.querySelector('form input[name="action"][value="create"]')?.form;
    if (accountForm) {
        const parentSelect = accountForm.querySelector('[name="parent_user_id"]');
        const quotaInput = accountForm.querySelector('[name="mac_amount"]');
        const quotaHelp = document.getElementById('quota-help');
        const submitButton = accountForm.querySelector('button[type="submit"]');

        function updateQuotaInput() {
            const selected = parentSelect.selectedOptions[0];
            const isSubreseller = parentSelect.value !== '';
            const available = isSubreseller ? Number(selected.dataset.available || 0) : null;
            const distributable = isSubreseller ? Math.max(0, available - 10) : null;
            quotaInput.min = isSubreseller ? '10' : '0';
            quotaInput.step = isSubreseller ? '10' : '1';
            if (isSubreseller) {
                quotaInput.max = String(distributable);
                if (Number(quotaInput.value) < 10 && distributable >= 10) {
                    quotaInput.value = '10';
                }
                quotaHelp.textContent = distributable >= 10
                    ? `Esta conta pode repassar até ${distributable} MACs em blocos de 10 e precisa manter pelo menos 10 livres.`
                    : 'Esta conta precisa ter pelo menos 20 MACs livres para repassar 10 e manter 10 para uso próprio.';
                submitButton.disabled = distributable < 10;
            } else {
                quotaInput.removeAttribute('max');
                if (quotaInput.value === '' || Number(quotaInput.value) < 0) {
                    quotaInput.value = '0';
                }
                quotaHelp.textContent = 'A conta principal pode atribuir qualquer quantidade. Ao escolher uma conta superior, a sub-revenda recebe em blocos de 10 MACs e a superior mantém pelo menos 10 livres.';
                submitButton.disabled = false;
            }
        }

        parentSelect.addEventListener('change', updateQuotaInput);
        updateQuotaInput();
    }
</script>
</body>
</html>
