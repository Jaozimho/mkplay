<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/api_log.php';
$db = getDB();

$error = '';
$newSubresellerCredentials = $_SESSION['new_subreseller_credentials'] ?? null;
unset($_SESSION['new_subreseller_credentials']);
$createdMessage = (string) ($_SESSION['reseller_created_message'] ?? '');
unset($_SESSION['reseller_created_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'create_subreseller') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $storeType = filter_input(INPUT_POST, 'store_type', FILTER_VALIDATE_INT);
        $macAmount = filter_input(INPUT_POST, 'mac_amount', FILTER_VALIDATE_INT);
        try {
            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException('Informe o nome da sub-revenda (até 255 caracteres).');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                throw new InvalidArgumentException('O usuário deve ter de 3 a 64 caracteres: letras, números, ponto, hífen ou sublinhado.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
            }
            if (!in_array($storeType, [1, 2], true)) {
                throw new InvalidArgumentException('Selecione um tipo de conta válido.');
            }
            if ($macAmount === false || $macAmount === null || $macAmount < 10 || $macAmount % 10 !== 0) {
                throw new InvalidArgumentException('A cota da sub-revenda deve ser em blocos de 10 MACs, com mínimo de 10.');
            }

            $db->beginTransaction();
            $lockQuota = $db->prepare('UPDATE users SET mac_amount = mac_amount WHERE id = ?');
            $lockQuota->execute([(int) $reseller['id']]);
            $quota = getResellerQuotaSummary($db, (int) $reseller['id']);
            if ($quota['available'] < $macAmount + 10) {
                $distributable = max(0, intdiv($quota['available'] - 10, 10) * 10);
                throw new InvalidArgumentException(
                    'Você precisa manter 10 MACs livres para suas ativações. Pode repassar até '
                    . $distributable . ' MAC(s); recarregue sua cota para criar esta sub-revenda.'
                );
            }
            $create = $db->prepare('
                INSERT INTO users (name, username, password, admin, store_type, mac_amount, parent_user_id, referral_code)
                VALUES (?, ?, ?, 0, ?, ?, ?, ?)
            ');
            $create->execute([
                $name,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $storeType,
                $macAmount,
                (int) $reseller['id'],
                createReferralCode($db),
            ]);
            $createdId = (int) $db->lastInsertId();
            $createdAccount = $db->prepare('SELECT referral_code FROM users WHERE id = ?');
            $createdAccount->execute([$createdId]);
            $newSubresellerCredentials = [
                'name' => $name,
                'username' => $username,
                'password' => $password,
                'login_url' => getPublicBaseUrl() . '/reseller/login.php',
                'mac_amount' => $macAmount,
                'referral_url' => resellerReferralUrl((string) $createdAccount->fetchColumn()),
            ];
            $_SESSION['new_subreseller_credentials'] = $newSubresellerCredentials;
            $_SESSION['reseller_created_message'] = 'Sub-revenda criada e cota distribuída.';
            $db->commit();
            header('Location: index.php?msg=subreseller_created');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_subreseller_credentials'], $_SESSION['reseller_created_message']);
            $newSubresellerCredentials = null;
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_subreseller_credentials'], $_SESSION['reseller_created_message']);
            $newSubresellerCredentials = null;
            error_log('Reseller sub-account creation error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível criar a sub-revenda. Verifique se o nome de usuário já está em uso.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unset($_SESSION['new_subreseller_credentials'], $_SESSION['reseller_created_message']);
            $newSubresellerCredentials = null;
            error_log('Reseller sub-account creation error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível concluir a criação da sub-revenda.';
        }
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        $deviceId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $active = filter_input(INPUT_POST, 'active', FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId === null || $deviceId < 1 || !in_array($active, [0, 1], true)) {
            $error = 'Ação ou dispositivo inválido.';
        } else {
            try {
                $update = $db->prepare('UPDATE ibo SET active = ? WHERE id = ? AND id_user = ?');
                $update->execute([$active, $deviceId, (int) $reseller['id']]);
                if ($update->rowCount() !== 1) {
                    $error = 'Dispositivo não encontrado.';
                } else {
                    $deviceMacQuery = $db->prepare('SELECT mac_address FROM ibo WHERE id = ? AND id_user = ?');
                    $deviceMacQuery->execute([$deviceId, (int) $reseller['id']]);
                    $deviceMac = $deviceMacQuery->fetchColumn();
                    apiLog('device_status_changed', [
                        'scope' => 'reseller',
                        'reseller_id' => (int) $reseller['id'],
                        'mac' => apiMaskedMac(is_string($deviceMac) ? $deviceMac : null),
                        'device_active' => $active === 1,
                        'device_status' => $active === 1 ? 'active' : 'blocked',
                    ]);
                    header('Location: index.php?msg=' . ($active === 1 ? 'unblocked' : 'blocked'));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Reseller device status update error: ' . $e->getMessage());
                http_response_code(500);
                $error = 'Não foi possível atualizar o status do dispositivo.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $deviceId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId === null || $deviceId < 1) {
            $error = 'Dispositivo inválido.';
        } else {
            try {
                $delete = $db->prepare('DELETE FROM ibo WHERE id = ? AND id_user = ?');
                $delete->execute([$deviceId, $reseller['id']]);
                if ($delete->rowCount() !== 1) {
                    $error = 'Dispositivo não encontrado.';
                } else {
                    header('Location: index.php?msg=deleted');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Reseller device deletion error: ' . $e->getMessage());
                http_response_code(500);
                $error = 'Não foi possível excluir o dispositivo.';
            }
        }
    } else {
        $error = 'Ação inválida.';
    }
}

$devicesQuery = $db->prepare('
    SELECT id, mac_address, title, username, expire_date, active, store_type, created_at
    FROM ibo
    WHERE id_user = ?
    ORDER BY id DESC
');
$devicesQuery->execute([$reseller['id']]);
$devices = $devicesQuery->fetchAll();
$activationHistory = array_slice($devices, 0, 20);
$quotaSummary = getResellerQuotaSummary($db, (int) $reseller['id']);
$usedSlots = $quotaSummary['active_devices'];
$availableSlots = $quotaSummary['available'];
$totalCredits = (int) $reseller['mac_amount'] + (int) $reseller['bonus_mac_amount'];
$maxSubresellerQuota = max(0, intdiv($availableSlots - 10, 10) * 10);
$childrenQuery = $db->prepare('SELECT id, name, username, mac_amount, bonus_mac_amount FROM users WHERE parent_user_id = ? ORDER BY name');
$childrenQuery->execute([(int) $reseller['id']]);
$subresellers = $childrenQuery->fetchAll();
$subresellerQuotas = [];
foreach ($subresellers as $subreseller) {
    $subresellerQuotas[(int) $subreseller['id']] = getResellerQuotaSummary($db, (int) $subreseller['id']);
}
$referralUrl = resellerReferralUrl((string) $reseller['referral_code']);
$message = match ($_GET['msg'] ?? '') {
    'deleted' => 'Dispositivo removido.',
    'saved' => 'Dispositivo salvo.',
    'blocked' => 'Dispositivo bloqueado.',
    'unblocked' => 'Dispositivo desbloqueado.',
    'subreseller_created' => 'Sub-revenda criada e cota distribuída.',
    default => '',
};
if ($message === '') {
    $message = $createdMessage;
}
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meus dispositivos | Área da revenda</title>
    <style>
        * { box-sizing: border-box; }
        html { scrollbar-width: none; }
        html::-webkit-scrollbar { display: none; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        body { overflow-x: clip; scrollbar-width: none; }
        body::-webkit-scrollbar { display: none; }
        main { max-width: 1100px; margin: auto; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        a { color: #3157d5; text-decoration: none; }
        .summary, .panel { margin-bottom: 18px; padding: 18px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .summary { color: #475467; }
        .summary.warning { border-color: #fedf89; background: #fffaeb; color: #93370d; }
        .credit-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .credit-stat { min-width: 0; padding: 12px; border: 1px solid #eaecf0; border-radius: 8px; background: #fff; }
        .credit-stat span { display: block; color: #667085; font-size: 11px; }
        .credit-stat strong { display: block; margin-top: 4px; color: #101828; font-size: 22px; line-height: 1.2; }
        .credit-stat.available { border-color: #b2ddff; background: #f5faff; }
        .credit-note { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 0; color: #667085; font-size: 12px; }
        .credit-note a { flex: 0 0 auto; }
        .activation-panel { padding: 14px; }
        .activation-panel h2 { margin: 0 0 4px; font-size: 16px; }
        .activation-panel > p { margin: 0 0 8px; color: #667085; font-size: 11px; }
        .activation-history th, .activation-history td { padding: 7px 9px; font-size: 11px; }
        .activation-status { font-weight: 600; }
        .activation-status.active { color: #067647; }
        .activation-status.blocked, .activation-status.expired { color: #b54708; }
        .activation-empty { padding: 14px; color: #667085; text-align: center; font-size: 12px; }
        .fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        label { display: block; color: #475467; font-size: 12px; }
        input, select { display: block; width: 100%; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .button, button { display: inline-block; padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        .table-wrap { overflow: visible; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; white-space: normal; }
        th, td { padding: 11px; border-bottom: 1px solid #eaecf0; text-align: left; }
        th, td { overflow-wrap: anywhere; }
        th { background: #fcfcfd; color: #667085; font-size: 11px; text-transform: uppercase; }
        .actions { display: flex; gap: 8px; align-items: center; }
        .actions form { margin: 0; }
        .message { margin-bottom: 14px; padding: 12px; border-radius: 6px; background: #ecfdf3; color: #067647; }
        .message.error { background: #fef3f2; color: #b42318; }
        .access-card { padding: 14px; border-color: #b2ddff; background: #f5faff; }
        .access-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .access-card h2 { margin: 0; }
        .access-card pre { margin: 10px 0 0; padding: 10px; border: 1px solid #d0d5dd; border-radius: 7px; background: #fff; font: 12px/1.5 Consolas, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
        .empty { padding: 28px; color: #667085; text-align: center; }
        .status { font-weight: 600; }
        @media (max-width: 650px) { .fields { grid-template-columns: 1fr; } }
        @media (max-width: 850px) { .credit-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 850px) {
            .table-wrap table, .table-wrap tbody { display: block; width: 100%; }
            .table-wrap thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); clip-path: inset(50%); }
            .table-wrap tbody { display: grid; gap: 9px; padding: 9px; }
            .table-wrap tbody tr { display: grid; min-width: 0; padding: 6px 10px; border: 1px solid #eaecf0; border-radius: 9px; background: #fff; }
            .table-wrap tbody tr[hidden] { display: none; }
            .table-wrap tbody td { display: grid; grid-template-columns: minmax(78px, .34fr) minmax(0, 1fr); gap: 8px; min-width: 0; padding: 6px 0; border: 0; border-bottom: 1px solid #f0f1f3; }
            .table-wrap tbody td:last-child { border-bottom: 0; }
            .table-wrap tbody td::before { color: #667085; font-size: 11px; font-weight: 700; letter-spacing: .35px; text-transform: uppercase; content: attr(data-label); }
            .table-wrap tbody td[colspan] { display: block; border: 0; text-align: center; }
            .table-wrap tbody td[colspan]::before { content: none; }
            .table-wrap tbody td .actions { min-width: 0; flex-wrap: wrap; }
            .table-wrap tbody td form { max-width: 100%; }
        }
        @media (max-width: 560px) { body { padding: 18px 10px; } header, .toolbar { align-items: flex-start; flex-direction: column; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="reseller-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1>Olá, <?= $escape($reseller['name']) ?></h1></header>
    <?php if (is_array($newSubresellerCredentials)): ?>
        <?php
        $subresellerAccess = implode("\n", [
            'Acesso ao painel — Sub-revenda',
            'Nome: ' . $newSubresellerCredentials['name'],
            'Usuário: ' . $newSubresellerCredentials['username'],
            'Senha inicial: ' . $newSubresellerCredentials['password'],
            'Painel: ' . $newSubresellerCredentials['login_url'],
            'MACs liberados: ' . $newSubresellerCredentials['mac_amount'],
            'Link de indicação: ' . $newSubresellerCredentials['referral_url'],
        ]);
        ?>
        <section class="panel access-card">
            <div class="access-head">
                <div><h2>Sub-revenda criada — dados para enviar</h2><p>A senha inicial aparece somente agora. Copie e envie para a pessoa cadastrada.</p></div>
                <button type="button" id="copy-subreseller-access">Copiar dados</button>
            </div>
            <pre id="subreseller-access-text"><?= $escape($subresellerAccess) ?></pre>
        </section>
    <?php endif; ?>
    <section class="summary credit-summary <?= $availableSlots <= 2 ? 'warning' : '' ?>" aria-label="Saldo de créditos MAC">
        <div class="credit-stat available"><span>Créditos disponíveis</span><strong><?= $availableSlots ?></strong></div>
        <div class="credit-stat"><span>Créditos totais</span><strong><?= $totalCredits ?></strong></div>
        <div class="credit-stat"><span>Ativações em uso</span><strong><?= $usedSlots ?></strong></div>
        <div class="credit-stat"><span>Repassados às sub-revendas</span><strong><?= $quotaSummary['allocated_to_children'] ?></strong></div>
        <p class="credit-note">
            <span>Uma ativação ativa reserva 1 crédito MAC. Tipo de conta: <?= (int) $reseller['store_type'] === 2 ? 'Premium' : 'Revenda' ?>.</span>
            <a class="button" href="credits.php">Comprar créditos</a>
        </p>
        <?php if ($availableSlots <= 2): ?>
            <p class="credit-note"><strong>Atenção:</strong> restam apenas <?= $availableSlots ?> créditos. Recarregue/solicite mais cota ao administrador para novas ativações.</p>
        <?php endif; ?>
    </section>
    <section class="panel activation-panel">
        <h2>Ativações realizadas (<?= count($devices) ?>)</h2>
        <p>Dispositivos cadastrados nesta conta; as 20 ativações mais recentes aparecem aqui.</p>
        <?php if ($activationHistory === []): ?>
            <div class="activation-empty">Nenhuma ativação cadastrada ainda.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="activation-history">
                    <thead><tr><th>MAC</th><th>Dispositivo</th><th>Data da ativação</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($activationHistory as $activation): ?>
                        <?php
                        $activationExpired = isDeviceExpired(isset($activation['expire_date']) ? (string) $activation['expire_date'] : null);
                        $activationStatus = empty($activation['active'])
                            ? 'Bloqueado'
                            : ($activationExpired ? 'Vencido' : 'Ativo');
                        $activationStatusClass = empty($activation['active'])
                            ? 'blocked'
                            : ($activationExpired ? 'expired' : 'active');
                        ?>
                        <tr>
                            <td><?= $escape($activation['mac_address']) ?></td>
                            <td><?= $escape($activation['title']) ?></td>
                            <td><?= $escape($activation['created_at'] ?? 'Data não informada') ?></td>
                            <td class="activation-status <?= $activationStatusClass ?>"><?= $activationStatus ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Indicações e recompensas</h2>
        <p>Compartilhe seu link para cadastrar novas revendas. Em cada lote pago de 10 MACs por uma conta indicada, você recebe mais 10 MACs, 10 pontos (R$ 10,00) e R$ 20,00 de comissão Pix.</p>
        <label>Seu link exclusivo<input type="url" value="<?= $escape($referralUrl) ?>" readonly onclick="this.select()"></label>
        <p><strong><?= (int) $reseller['referral_points'] ?> ponto(s)</strong> disponíveis · <strong>R$ <?= number_format((int) $reseller['referral_cash_cents'] / 100, 2, ',', '.') ?></strong> em comissões Pix.</p>
        <a class="button" href="credits.php">Comprar MACs e solicitar saque</a>
    </section>
    <?php if ($message !== ''): ?><div class="message"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="message error"><?= $escape($error) ?></div><?php endif; ?>
    <section class="panel">
        <h2>Criar sub-revenda</h2>
        <p>As cotas são repassadas em blocos de 10 MACs. Você precisa manter pelo menos 10 MACs livres para suas próprias ativações; se não houver saldo suficiente, recarregue sua cota.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
            <input type="hidden" name="action" value="create_subreseller">
            <div class="fields">
                <label>Nome<input name="name" maxlength="255" required></label>
                <label>Usuário<input name="username" minlength="3" maxlength="64" required></label>
                <label>Senha inicial<input name="password" type="password" minlength="8" autocomplete="new-password" required></label>
                <label>Tipo<select name="store_type"><option value="1">Revenda</option><option value="2">Premium</option></select></label>
                <label>MACs para liberar<input name="mac_amount" type="number" min="10" max="<?= $maxSubresellerQuota ?>" step="10" value="<?= $maxSubresellerQuota >= 10 ? 10 : '' ?>" required></label>
            </div>
            <?php if ($maxSubresellerQuota < 10): ?>
                <p class="message error">Você precisa ter pelo menos 20 MACs disponíveis para repassar 10 e manter 10 para suas próprias ativações. Recarregue sua cota para criar uma sub-revenda.</p>
            <?php endif; ?>
            <button class="button" type="submit" <?= $maxSubresellerQuota < 10 ? 'disabled' : '' ?>>Criar sub-revenda</button>
        </form>
    </section>
    <section class="panel">
        <h2>Minhas sub-revendas (<?= count($subresellers) ?>)</h2>
        <?php if ($subresellers === []): ?>
            <p class="empty">Nenhuma sub-revenda cadastrada.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Usuário</th><th>MACs ativos</th><th>Cota</th><th>Disponíveis</th></tr></thead>
                    <tbody><?php foreach ($subresellers as $subreseller): ?>
                        <?php $childQuota = $subresellerQuotas[(int) $subreseller['id']]; ?>
                        <tr>
                            <td><?= $escape($subreseller['name']) ?></td>
                            <td>@<?= $escape($subreseller['username']) ?></td>
                            <td><?= (int) $childQuota['active_devices'] ?></td>
                            <td><?= (int) $subreseller['mac_amount'] + (int) $subreseller['bonus_mac_amount'] ?></td>
                            <td><?= (int) $childQuota['available'] ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="toolbar"><h2>Meus dispositivos</h2><a class="button" href="device.php">Adicionar dispositivo</a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>MAC</th><th>Nome</th><th>Usuário</th><th>Vencimento</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php if ($devices === []): ?>
                    <tr><td class="empty" colspan="6">Nenhum dispositivo cadastrado.</td></tr>
                <?php else: foreach ($devices as $device): ?>
                    <?php
                    $expired = isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null);
                    $status = empty($device['active']) ? 'Bloqueado' : ($expired ? 'Vencido' : 'Ativo');
                    ?>
                    <tr>
                        <td><?= $escape($device['mac_address']) ?></td>
                        <td><?= $escape($device['title']) ?></td>
                        <td><?= $escape($device['username']) ?></td>
                        <td><?= $escape($device['expire_date']) ?></td>
                        <td class="status"><?= $escape($status) ?></td>
                        <td><div class="actions">
                            <a class="button" href="device.php?id=<?= (int) $device['id'] ?>">Editar</a>
                            <form method="post" onsubmit="return confirm('<?= empty($device['active']) ? 'Desbloquear' : 'Bloquear' ?> este dispositivo?')">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                <input type="hidden" name="active" value="<?= empty($device['active']) ? '1' : '0' ?>">
                                <button type="submit"><?= empty($device['active']) ? 'Desbloquear' : 'Bloquear' ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('Excluir este dispositivo?')">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                <button class="danger" type="submit">Excluir</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
    const copySubresellerAccess = document.getElementById('copy-subreseller-access');
    if (copySubresellerAccess) {
        copySubresellerAccess.addEventListener('click', async () => {
            const text = document.getElementById('subreseller-access-text').textContent;
            try {
                await navigator.clipboard.writeText(text);
                copySubresellerAccess.textContent = 'Copiado';
            } catch (error) {
                const range = document.createRange();
                range.selectNodeContents(document.getElementById('subreseller-access-text'));
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                copySubresellerAccess.textContent = 'Texto selecionado para copiar';
            }
        });
    }
    document.querySelectorAll('.table-wrap table').forEach(function (table) {
        const labels = Array.from(table.querySelectorAll('thead th'), function (header) {
            return header.textContent.trim();
        });
        table.querySelectorAll('tbody tr').forEach(function (row) {
            Array.from(row.cells).forEach(function (cell, index) {
                if (!cell.hasAttribute('colspan') && labels[index]) {
                    cell.dataset.label = labels[index];
                }
            });
        });
    });
</script>
</body>
</html>
