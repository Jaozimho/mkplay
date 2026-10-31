<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/pix.php';
require_once __DIR__ . '/../api/includes/referrals.php';
$referralPolicy = getReferralRewardPolicy();

if (empty($_SESSION['pix_admin_csrf'])) {
    $_SESSION['pix_admin_csrf'] = bin2hex(random_bytes(32));
}

$db = getDB();
$error = '';
$success = '';
$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['pix_admin_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_config') {
                $pixKey = trim((string) ($_POST['pix_key'] ?? ''));
                $merchantName = trim((string) ($_POST['merchant_name'] ?? ''));
                $merchantCity = trim((string) ($_POST['merchant_city'] ?? ''));
                $amountInput = trim((string) ($_POST['amount'] ?? ''));
                $amountInput = str_replace(',', '.', $amountInput);
                $creditAmountInput = str_replace(',', '.', trim((string) ($_POST['credit_amount'] ?? '')));
                if ($pixKey === '' || strlen($pixKey) > 77 || $merchantName === '' || strlen($merchantName) > 100
                    || $merchantCity === '' || strlen($merchantCity) > 80
                    || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $amountInput)
                    || ($creditAmountInput !== '' && !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $creditAmountInput))) {
                    throw new InvalidArgumentException('Preencha uma chave Pix, recebedor, cidade e preço válidos.');
                }
                $amountCents = (int) round((float) $amountInput * 100);
                $creditAmountCents = $creditAmountInput === ''
                    ? 0
                    : (int) round((float) $creditAmountInput * 100);
                if ($amountCents < 1) {
                    throw new InvalidArgumentException('O valor Pix precisa ser maior que zero.');
                }
                if ($creditAmountInput !== '' && $creditAmountCents < 1) {
                    throw new InvalidArgumentException('O valor do lote de MACs precisa ser maior que zero.');
                }
                generatePixPayload($pixKey, $merchantName, $merchantCity, $amountCents, 'CONFIGTEST');
                if ($creditAmountCents > 0) {
                    generatePixPayload($pixKey, $merchantName, $merchantCity, $creditAmountCents, 'CREDITTEST');
                }
                $db->beginTransaction();
                $save = $db->prepare('
                    INSERT INTO pix_config (id, pix_key, merchant_name, merchant_city, amount_cents, reseller_credit_amount_cents, duration_days, updated_at)
                    VALUES (1, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(id) DO UPDATE SET
                        pix_key = excluded.pix_key,
                        merchant_name = excluded.merchant_name,
                        merchant_city = excluded.merchant_city,
                        amount_cents = excluded.amount_cents,
                        reseller_credit_amount_cents = excluded.reseller_credit_amount_cents,
                        duration_days = excluded.duration_days,
                        updated_at = CURRENT_TIMESTAMP
                ');
                $save->execute([
                    $pixKey,
                    $merchantName,
                    $merchantCity,
                    $amountCents,
                    $creditAmountCents,
                    pixSubscriptionDurationDays(),
                ]);
                $normalizePending = $db->prepare("
                    UPDATE pix_payments SET duration_days = ?
                    WHERE status = 'pending' AND duration_days != ?
                ");
                $normalizePending->execute([
                    pixSubscriptionDurationDays(),
                    pixSubscriptionDurationDays(),
                ]);
                $db->commit();
                $success = 'Configuração Pix salva.';
            } elseif (in_array($action, ['approve', 'reject'], true)) {
                $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
                if ($paymentId === false || $paymentId === null || $paymentId < 1) {
                    throw new InvalidArgumentException('Pedido de pagamento inválido.');
                }
                $db->beginTransaction();
                $find = $db->prepare("SELECT * FROM pix_payments WHERE id = ? AND status = 'pending'");
                $find->execute([$paymentId]);
                $payment = $find->fetch();
                if ($payment === false) {
                    throw new InvalidArgumentException('O pagamento não existe ou já foi processado.');
                }
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';
                $updatePayment = $db->prepare("
                    UPDATE pix_payments
                    SET status = ?, processed_at = CURRENT_TIMESTAMP, processed_by = ?
                    WHERE id = ? AND status = 'pending'
                ");
                $updatePayment->execute([$newStatus, $adminId, $paymentId]);
                if ($updatePayment->rowCount() !== 1) {
                    throw new RuntimeException('Não foi possível atualizar o status do pagamento.');
                }
                if ($action === 'approve') {
                    $setPaidTerm = $db->prepare('UPDATE pix_payments SET duration_days = ? WHERE id = ?');
                    $setPaidTerm->execute([pixSubscriptionDurationDays(), $paymentId]);
                    $deviceQuery = $db->prepare('SELECT id, expire_date FROM ibo WHERE mac_address = ?');
                    $deviceQuery->execute([(string) $payment['mac_address']]);
                    $device = $deviceQuery->fetch();
                    if ($device === false) {
                        throw new RuntimeException('O dispositivo do pedido não foi encontrado.');
                    }
                    $newExpiration = nextPixSubscriptionExpiration(
                        isset($device['expire_date']) ? (string) $device['expire_date'] : null
                    );
                    $extendDevice = $db->prepare('UPDATE ibo SET expire_date = ?, active = 1 WHERE id = ?');
                    $extendDevice->execute([$newExpiration, (int) $device['id']]);
                }
                $db->commit();
                $success = $action === 'approve'
                    ? 'Pagamento aprovado e assinatura ativada.'
                    : 'Pedido marcado como não pago.';
            } elseif (in_array($action, ['approve_credit_order', 'reject_credit_order'], true)) {
                $orderId = filter_var($_POST['credit_order_id'] ?? null, FILTER_VALIDATE_INT);
                if ($orderId === false || $orderId === null || $orderId < 1) {
                    throw new InvalidArgumentException('Pedido de créditos inválido.');
                }
                $db->beginTransaction();
                $findOrder = $db->prepare("
                    SELECT * FROM reseller_credit_orders WHERE id = ? AND status = 'pending'
                ");
                $findOrder->execute([$orderId]);
                $order = $findOrder->fetch();
                if ($order === false) {
                    throw new InvalidArgumentException('O pedido de créditos não existe ou já foi processado.');
                }
                $referralValidated = (string) ($_POST['referral_validated'] ?? '') === '1';
                if ($action !== 'approve_credit_order') {
                    $referralValidated = false;
                }
                if ($referralValidated && $order['referrer_user_id'] === null) {
                    throw new InvalidArgumentException('Este pedido não tem indicação vinculada para validar.');
                }
                $newStatus = $action === 'approve_credit_order' ? 'approved' : 'rejected';
                $updateOrder = $db->prepare("
                    UPDATE reseller_credit_orders
                    SET status = ?, referral_validated = ?, processed_at = CURRENT_TIMESTAMP, processed_by = ?
                    WHERE id = ? AND status = 'pending'
                ");
                $updateOrder->execute([$newStatus, $referralValidated ? 1 : 0, $adminId, $orderId]);
                if ($updateOrder->rowCount() !== 1) {
                    throw new RuntimeException('Não foi possível atualizar o pedido de créditos.');
                }
                if ($action === 'approve_credit_order') {
                    $grantBuyerCredits = $db->prepare('
                        UPDATE users SET bonus_mac_amount = bonus_mac_amount + ?
                        WHERE id = ? AND COALESCE(admin, 0) <> 1
                    ');
                    $grantBuyerCredits->execute([
                        (int) $referralPolicy['credit_mac_amount'],
                        (int) $order['user_id'],
                    ]);
                    if ($grantBuyerCredits->rowCount() !== 1) {
                        throw new RuntimeException('A conta que fez o pedido não foi encontrada.');
                    }
                    if ($order['referrer_user_id'] !== null && $referralValidated) {
                        $saveReward = $db->prepare('
                            INSERT INTO referral_rewards (
                                credit_order_id, referrer_user_id, purchaser_user_id,
                                bonus_mac_amount, points_amount, cash_amount_cents
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ');
                        $saveReward->execute([
                            $orderId,
                            (int) $order['referrer_user_id'],
                            (int) $order['user_id'],
                            (int) $referralPolicy['reward_mac_amount'],
                            (int) $referralPolicy['reward_points'],
                            (int) $referralPolicy['reward_cash_cents'],
                        ]);
                        $grantReferrerReward = $db->prepare('
                            UPDATE users
                            SET bonus_mac_amount = bonus_mac_amount + ?,
                                referral_points = referral_points + ?,
                                referral_cash_cents = referral_cash_cents + ?
                            WHERE id = ? AND COALESCE(admin, 0) <> 1
                        ');
                        $grantReferrerReward->execute([
                            (int) $referralPolicy['reward_mac_amount'],
                            (int) $referralPolicy['reward_points'],
                            (int) $referralPolicy['reward_cash_cents'],
                            (int) $order['referrer_user_id'],
                        ]);
                        if ($grantReferrerReward->rowCount() !== 1) {
                            throw new RuntimeException('A conta indicadora não foi encontrada; a aprovação foi cancelada.');
                        }
                    }
                }
                $db->commit();
                $success = $action === 'approve_credit_order'
                    ? ($referralValidated
                        ? 'Pagamento e indicação confirmados: ' . (int) $referralPolicy['credit_mac_amount']
                            . ' MACs liberados e recompensas aplicadas.'
                        : 'Pagamento confirmado: ' . (int) $referralPolicy['credit_mac_amount']
                            . ' MACs liberados. A indicação não foi validada e não gerou recompensas.')
                    : 'Pedido de créditos marcado como não pago.';
            } elseif (in_array($action, ['approve_withdrawal', 'reject_withdrawal'], true)) {
                $withdrawalId = filter_var($_POST['withdrawal_id'] ?? null, FILTER_VALIDATE_INT);
                if ($withdrawalId === false || $withdrawalId === null || $withdrawalId < 1) {
                    throw new InvalidArgumentException('Solicitação de saque inválida.');
                }
                $db->beginTransaction();
                $findWithdrawal = $db->prepare("
                    SELECT * FROM referral_withdrawals WHERE id = ? AND status = 'pending'
                ");
                $findWithdrawal->execute([$withdrawalId]);
                $withdrawal = $findWithdrawal->fetch();
                if ($withdrawal === false) {
                    throw new InvalidArgumentException('O saque não existe ou já foi processado.');
                }
                $newStatus = $action === 'approve_withdrawal' ? 'paid' : 'rejected';
                $updateWithdrawal = $db->prepare("
                    UPDATE referral_withdrawals
                    SET status = ?, processed_at = CURRENT_TIMESTAMP, processed_by = ?
                    WHERE id = ? AND status = 'pending'
                ");
                $updateWithdrawal->execute([$newStatus, $adminId, $withdrawalId]);
                if ($updateWithdrawal->rowCount() !== 1) {
                    throw new RuntimeException('Não foi possível atualizar o saque.');
                }
                if ($action === 'reject_withdrawal') {
                    if ($withdrawal['wallet_type'] === 'points') {
                        $refund = $db->prepare('
                            UPDATE users SET referral_points = referral_points + ? WHERE id = ?
                        ');
                        $refund->execute([(int) $withdrawal['points_amount'], (int) $withdrawal['user_id']]);
                    } else {
                        $refund = $db->prepare('
                            UPDATE users SET referral_cash_cents = referral_cash_cents + ? WHERE id = ?
                        ');
                        $refund->execute([(int) $withdrawal['cash_amount_cents'], (int) $withdrawal['user_id']]);
                    }
                    if ($refund->rowCount() !== 1) {
                        throw new RuntimeException('Não foi possível devolver o saldo do saque recusado.');
                    }
                }
                $db->commit();
                $success = $action === 'approve_withdrawal'
                    ? 'Saque marcado como pago. Confirme o Pix antes de concluir.'
                    : 'Saque recusado e saldo devolvido à revenda.';
            } else {
                throw new InvalidArgumentException('Ação inválida.');
            }
            $_SESSION['pix_admin_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Pix admin operation failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Não foi possível processar a operação Pix.';
        }
    }
}

$config = $db->query('SELECT * FROM pix_config WHERE id = 1')->fetch();
$payments = $db->query('
    SELECT payments.id, payments.txid, payments.mac_address, payments.amount_cents,
           payments.duration_days, payments.status, payments.created_at,
           ibo.title, ibo.expire_date
    FROM pix_payments AS payments
    LEFT JOIN ibo ON ibo.mac_address = payments.mac_address
    ORDER BY CASE payments.status WHEN "pending" THEN 0 ELSE 1 END, payments.id DESC
    LIMIT 100
')->fetchAll();
$creditOrders = $db->query('
    SELECT orders.id, orders.txid, orders.amount_cents, orders.status, orders.created_at,
           buyer.name AS buyer_name, buyer.username AS buyer_username,
           referrer.name AS referrer_name, orders.referral_validated
    FROM reseller_credit_orders AS orders
    LEFT JOIN users AS buyer ON buyer.id = orders.user_id
    LEFT JOIN users AS referrer ON referrer.id = orders.referrer_user_id
    ORDER BY CASE orders.status WHEN "pending" THEN 0 ELSE 1 END, orders.id DESC
    LIMIT 100
')->fetchAll();
$withdrawals = $db->query('
    SELECT withdrawals.id, withdrawals.wallet_type, withdrawals.points_amount,
           withdrawals.cash_amount_cents, withdrawals.pix_key, withdrawals.status,
           withdrawals.created_at, users.name AS user_name, users.username
    FROM referral_withdrawals AS withdrawals
    LEFT JOIN users ON users.id = withdrawals.user_id
    ORDER BY CASE withdrawals.status WHEN "pending" THEN 0 ELSE 1 END, withdrawals.id DESC
    LIMIT 100
')->fetchAll();
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pix e ativações | Admin</title>
    <link rel="stylesheet" href="assets/panel.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 22px; }
        .panel { margin-bottom: 22px; padding: 22px; border: 1px solid #eaecf0; border-radius: 12px; background: #fff; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 16px; }
        label { display: block; margin: 12px 0; color: #344054; font-weight: 600; }
        input { display: block; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 7px; font: inherit; }
        button { padding: 8px 11px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.reject { background: #b42318; }
        .notice, .error, .success { margin: 12px 0; padding: 12px; border-radius: 7px; }
        .notice { background: #fffaeb; color: #93370d; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .table-wrap { overflow: visible; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; white-space: normal; }
        th, td { padding: 11px; border-bottom: 1px solid #eaecf0; text-align: left; overflow-wrap: anywhere; }
        th { color: #667085; font-size: 12px; }
        td form { display: inline-block; margin-right: 5px; }
        label.referral-check { display: flex; align-items: center; gap: 6px; font-size: 11px; }
        label.referral-check input { width: auto; min-height: 0; margin: 0; }
        body.admin-page.pix-admin-page main > .top {
            min-height: 0;
            align-items: center;
            margin: 0 0 10px;
        }
        body.admin-page.pix-admin-page .top h1 { font-size: 22px !important; }
        body.admin-page.pix-admin-page main > .panel {
            margin: 0 0 10px;
            padding: 12px;
            border-radius: 9px;
            box-shadow: none;
        }
        body.admin-page.pix-admin-page .panel h2 { margin: 0 0 7px; font-size: 15px; }
        body.admin-page.pix-admin-page .panel > p:not(.notice) { margin: 4px 0 8px; }
        body.admin-page.pix-admin-page .grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 2px 12px;
        }
        body.admin-page.pix-admin-page form > .grid label { margin: 5px 0; font-size: 11px; }
        body.admin-page.pix-admin-page input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="color"]) {
            min-height: 30px;
            margin-top: 3px;
            padding: 5px 8px;
            font-size: 12px;
        }
        body.admin-page.pix-admin-page form > button {
            min-height: 30px;
            padding: 6px 9px;
            font-size: 11px;
        }
        body.admin-page.pix-admin-page .notice,
        body.admin-page.pix-admin-page .error,
        body.admin-page.pix-admin-page .success {
            margin: 7px 0;
            padding: 8px 10px;
            font-size: 11px;
            line-height: 1.4;
        }
        body.admin-page.pix-admin-page .table-wrap { overflow-x: auto; }
        body.admin-page.pix-admin-page .table-wrap table {
            width: 100%;
            table-layout: auto;
            font-size: 11px;
        }
        body.admin-page.pix-admin-page .table-wrap th,
        body.admin-page.pix-admin-page .table-wrap td {
            padding: 6px 7px;
            font-size: 11px;
            line-height: 1.35;
        }
        body.admin-page.pix-admin-page .table-wrap td form { margin: 0 4px 3px 0; }
        body.admin-page.pix-admin-page .table-wrap td button {
            min-height: 28px;
            padding: 5px 7px;
            font-size: 10px;
        }
        body.admin-page.pix-admin-page label.referral-check {
            margin: 0 0 4px;
            font-size: 10px;
        }
        @media (max-width: 1300px) {
            body.admin-page.pix-admin-page .table-wrap { overflow: visible; }
            body.admin-page.pix-admin-page .table-wrap thead {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                clip-path: inset(50%);
            }
            body.admin-page.pix-admin-page .table-wrap table,
            body.admin-page.pix-admin-page .table-wrap tbody {
                display: block;
                width: 100%;
            }
            body.admin-page.pix-admin-page .table-wrap tbody {
                display: grid;
                gap: 6px;
            }
            body.admin-page.pix-admin-page .table-wrap tbody tr {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0 10px;
                width: 100%;
                margin: 0;
                padding: 5px 8px;
                border: 1px solid #eaecf0;
                border-radius: 7px;
            }
            body.admin-page.pix-admin-page .table-wrap tbody td {
                display: grid;
                grid-template-columns: minmax(58px, .45fr) minmax(0, 1fr);
                align-items: baseline;
                gap: 5px;
                min-width: 0;
                padding: 4px 0;
                border: 0;
                border-bottom: 1px solid #f0f1f3;
                font-size: 10px;
            }
            body.admin-page.pix-admin-page .table-wrap tbody td::before {
                color: #667085;
                font-size: 9px;
                font-weight: 700;
                content: attr(data-label);
            }
            body.admin-page.pix-admin-page .table-wrap tbody td:last-child { grid-column: 1 / -1; }
            body.admin-page.pix-admin-page .table-wrap tbody td[colspan] {
                display: block;
                grid-column: 1 / -1;
                border: 0;
            }
            body.admin-page.pix-admin-page .table-wrap tbody td[colspan]::before { content: none; }
            body.admin-page.pix-admin-page .table-wrap tbody td form {
                display: inline-flex;
                align-items: flex-start;
                flex-wrap: wrap;
                gap: 3px;
                max-width: 100%;
                margin: 0 5px 3px 0;
            }
            body.admin-page.pix-admin-page .table-wrap tbody td button {
                min-height: 25px;
                padding: 4px 6px;
                font-size: 9px;
            }
        }
        @media (min-width: 761px) and (max-width: 1300px) {
            body.admin-page.pix-admin-page .table-wrap tbody tr {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 0 12px;
            }
            body.admin-page.pix-admin-page .table-wrap tbody td {
                grid-template-columns: max-content minmax(0, 1fr);
                gap: 6px;
            }
        }
        @media (max-width: 760px) {
            body.admin-page.pix-admin-page .top { align-items: flex-start; flex-direction: column; }
            body.admin-page.pix-admin-page .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="admin-page pix-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top"><h1>Pix e ativações</h1><a href="index.php">Voltar ao dashboard</a></header>
    <?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p class="success"><?= $escape($success) ?></p><?php endif; ?>
    <section class="panel">
        <h2>Configurar Pix estático</h2>
        <p class="notice">O Pix não é confirmado automaticamente. Confira o crédito no banco antes de aprovar o pedido. Nunca aprove apenas com base no comprovante enviado pelo cliente.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['pix_admin_csrf']) ?>">
            <input type="hidden" name="action" value="save_config">
            <div class="grid">
                <label>Chave Pix<input name="pix_key" maxlength="77" value="<?= $escape((string) ($config['pix_key'] ?? '')) ?>" required></label>
                <label>Nome do recebedor<input name="merchant_name" maxlength="100" value="<?= $escape((string) ($config['merchant_name'] ?? '')) ?>" required></label>
                <label>Cidade do recebedor<input name="merchant_city" maxlength="80" value="<?= $escape((string) ($config['merchant_city'] ?? '')) ?>" required></label>
                <label>Valor da renovação de dispositivo (R$)<input name="amount" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" value="<?= isset($config['amount_cents']) ? number_format((int) $config['amount_cents'] / 100, 2, '.', '') : '' ?>" required></label>
                <label>Valor do lote de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs (R$)<input name="credit_amount" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" value="<?= !empty($config['reseller_credit_amount_cents']) ? number_format((int) $config['reseller_credit_amount_cents'] / 100, 2, '.', '') : '' ?>"><small>Deixe vazio até definir o preço. Não altera o valor das renovações de dispositivos.</small></label>
                <label>Validade da assinatura<input type="text" value="12 meses" readonly><input type="hidden" name="duration_days" value="<?= pixSubscriptionDurationDays() ?>"></label>
            </div>
            <button type="submit">Salvar configuração</button>
        </form>
    </section>
    <section class="panel">
        <h2>Pedidos Pix</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pedido</th><th>MAC</th><th>Playlist</th><th>Valor</th><th>Prazo</th><th>Status</th><th>Vencimento</th><th>Criado</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if ($payments === []): ?>
                    <tr><td colspan="9">Ainda não há pedidos Pix.</td></tr>
                <?php else: foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= (int) $payment['id'] ?></td>
                        <td><?= $escape((string) $payment['mac_address']) ?></td>
                        <td><?= $escape((string) ($payment['title'] ?? '')) ?></td>
                        <td>R$ <?= number_format((int) $payment['amount_cents'] / 100, 2, ',', '.') ?></td>
                        <td><?= $payment['status'] === 'pending' || (int) $payment['duration_days'] === pixSubscriptionDurationDays()
                            ? '12 meses'
                            : (int) $payment['duration_days'] . ' dias' ?></td>
                        <td><?= $escape((string) $payment['status']) ?></td>
                        <td><?= $escape((string) ($payment['expire_date'] ?? 'Não informado')) ?></td>
                        <td><?= $escape((string) $payment['created_at']) ?></td>
                        <td>
                            <?php if ($payment['status'] === 'pending'): ?>
                                <?php foreach (['approve' => 'Aprovar pago', 'reject' => 'Marcar não pago'] as $action => $label): ?>
                                    <form method="post" onsubmit="return confirm('<?= $action === 'approve' ? 'Confirma que verificou o crédito e deseja ativar?' : 'Marcar este pedido como não pago?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['pix_admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="<?= $action ?>">
                                        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                        <button class="<?= $action === 'reject' ? 'reject' : '' ?>" type="submit"><?= $escape($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel" id="credit-orders">
        <h2>Pedidos de lotes de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs</h2>
        <p class="notice">Confira o crédito no banco pelo valor e identificador Pix antes de aprovar. A aprovação libera o lote para a conta. Para pedidos com indicação, a recompensa da política atual vem marcada; desmarque somente se a indicação não for válida. Liberação e recompensas são aplicadas uma única vez.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pedido</th><th>Revenda</th><th>Indicado por</th><th>Valor</th><th>Identificador Pix</th><th>Status</th><th>Data</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if ($creditOrders === []): ?>
                    <tr><td colspan="8">Ainda não há pedidos de lotes.</td></tr>
                <?php else: foreach ($creditOrders as $order): ?>
                    <tr>
                        <td data-label="Pedido">#<?= (int) $order['id'] ?></td>
                        <td data-label="Revenda"><?= $escape((string) ($order['buyer_name'] ?? 'Conta removida')) ?> (@<?= $escape((string) ($order['buyer_username'] ?? '')) ?>)</td>
                        <td data-label="Indicado por">
                            <?= $escape((string) ($order['referrer_name'] ?? 'Sem indicação')) ?>
                            <?php if ($order['referrer_name'] !== null && $order['status'] !== 'pending'): ?>
                                <small>(<?= (int) $order['referral_validated'] === 1 ? 'indicação validada' : 'indicação não validada' ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Valor">R$ <?= number_format((int) $order['amount_cents'] / 100, 2, ',', '.') ?> · <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs</td>
                        <td data-label="Identificador Pix"><?= $escape((string) $order['txid']) ?></td>
                        <td data-label="Status"><?= $escape((string) $order['status']) ?></td>
                        <td data-label="Data"><?= $escape((string) $order['created_at']) ?></td>
                        <td data-label="Ação">
                            <?php if ($order['status'] === 'pending'): ?>
                                <?php foreach (['approve_credit_order' => 'Confirmar pago', 'reject_credit_order' => 'Recusar'] as $action => $label): ?>
                                    <form method="post" onsubmit="return confirm('<?= $action === 'approve_credit_order' ? 'Confirma o recebimento do valor? Os créditos serão liberados; a recompensa da indicação pode ser desmarcada se não for válida.' : 'Recusar este pedido? O lote não será liberado.' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['pix_admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="<?= $action ?>">
                                        <input type="hidden" name="credit_order_id" value="<?= (int) $order['id'] ?>">
                                        <?php if ($action === 'approve_credit_order' && $order['referrer_name'] !== null): ?>
                                            <label class="referral-check"><input type="checkbox" name="referral_validated" value="1" checked> Aplicar recompensa de indicação (<?= (int) $referralPolicy['reward_mac_amount'] ?> MACs, <?= (int) $referralPolicy['reward_points'] ?> pontos e R$ <?= number_format((int) $referralPolicy['reward_cash_cents'] / 100, 2, ',', '.') ?>)</label>
                                        <?php endif; ?>
                                        <button class="<?= $action === 'reject_credit_order' ? 'reject' : '' ?>" type="submit"><?= $escape($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel">
        <h2>Saques de pontos e comissões</h2>
        <p class="notice">Ao aprovar, faça primeiro o pagamento via Pix. Se recusar, o saldo é devolvido automaticamente à revenda.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Solicitação</th><th>Revenda</th><th>Origem</th><th>Valor</th><th>Chave Pix</th><th>Status</th><th>Data</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if ($withdrawals === []): ?>
                    <tr><td colspan="8">Ainda não há saques solicitados.</td></tr>
                <?php else: foreach ($withdrawals as $withdrawal): ?>
                    <tr>
                        <td data-label="Solicitação">#<?= (int) $withdrawal['id'] ?></td>
                        <td data-label="Revenda"><?= $escape((string) ($withdrawal['user_name'] ?? 'Conta removida')) ?> (@<?= $escape((string) ($withdrawal['username'] ?? '')) ?>)</td>
                        <td data-label="Origem"><?= $withdrawal['wallet_type'] === 'points' ? 'Pontos (' . (int) $withdrawal['points_amount'] . ')' : 'Comissão' ?></td>
                        <td data-label="Valor">R$ <?= number_format((int) $withdrawal['cash_amount_cents'] / 100, 2, ',', '.') ?></td>
                        <td data-label="Chave Pix"><?= $escape((string) $withdrawal['pix_key']) ?></td>
                        <td data-label="Status"><?= $escape((string) $withdrawal['status']) ?></td>
                        <td data-label="Data"><?= $escape((string) $withdrawal['created_at']) ?></td>
                        <td data-label="Ação">
                            <?php if ($withdrawal['status'] === 'pending'): ?>
                                <?php foreach (['approve_withdrawal' => 'Confirmar pago', 'reject_withdrawal' => 'Recusar/devolver'] as $action => $label): ?>
                                    <form method="post" onsubmit="return confirm('<?= $action === 'approve_withdrawal' ? 'Confirma que o Pix foi pago?' : 'Recusar o saque e devolver o saldo?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['pix_admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="<?= $action ?>">
                                        <input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>">
                                        <button class="<?= $action === 'reject_withdrawal' ? 'reject' : '' ?>" type="submit"><?= $escape($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
document.querySelectorAll('.table-wrap table').forEach(function (table) {
    const labels = Array.from(table.querySelectorAll('thead th'), function (header) {
        return header.textContent.trim();
    });
    table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.from(row.cells).forEach(function (cell, index) {
            if (!cell.hasAttribute('colspan') && labels[index]) cell.dataset.label = labels[index];
        });
    });
});
</script>
</body>
</html>
