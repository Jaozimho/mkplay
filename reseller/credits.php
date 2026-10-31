<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/pix.php';
require_once __DIR__ . '/../api/includes/referrals.php';

$db = getDB();
$referralPolicy = getReferralRewardPolicy();
$creditQuota = getResellerQuotaSummary($db, (int) $reseller['id']);
$error = '';
$success = '';
$config = $db->query('SELECT * FROM pix_config WHERE id = 1')->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create_credit_order') {
                $amountCents = (int) ($config['reseller_credit_amount_cents'] ?? 0);
                if ($amountCents < 1 || trim((string) ($config['pix_key'] ?? '')) === '') {
                    throw new InvalidArgumentException('A compra de créditos ainda não está configurada pelo administrador.');
                }
                $txid = 'MAC' . strtoupper(bin2hex(random_bytes(10)));
                $payload = generatePixPayload(
                    (string) $config['pix_key'],
                    (string) $config['merchant_name'],
                    (string) $config['merchant_city'],
                    $amountCents,
                    $txid
                );
                $referrerQuery = $db->prepare('SELECT referred_by_user_id FROM users WHERE id = ?');
                $referrerQuery->execute([(int) $reseller['id']]);
                $referredByUserId = $referrerQuery->fetchColumn();
                $create = $db->prepare('
                    INSERT INTO reseller_credit_orders (
                        user_id, referrer_user_id, txid, amount_cents, pix_payload,
                        pix_key, merchant_name, merchant_city
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $create->execute([
                    (int) $reseller['id'],
                    $referredByUserId === false || $referredByUserId === null ? null : (int) $referredByUserId,
                    $txid,
                    $amountCents,
                    $payload,
                    (string) $config['pix_key'],
                    (string) $config['merchant_name'],
                    (string) $config['merchant_city'],
                ]);
                header('Location: credits.php?msg=order_created');
                exit;
            }

            if (in_array($action, ['withdraw_points', 'withdraw_cash'], true)) {
                $pixKey = trim((string) ($_POST['pix_key'] ?? $reseller['referral_pix_key'] ?? ''));
                if ($pixKey === '' || strlen($pixKey) > 77) {
                    throw new InvalidArgumentException('Informe uma chave Pix válida (máximo de 77 caracteres).');
                }
                $walletType = $action === 'withdraw_points' ? 'points' : 'cash';
                $points = 0;
                $cashCents = 0;
                if ($walletType === 'points') {
                    $pointsInput = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_INT);
                    if ($pointsInput === false || $pointsInput === null
                        || $pointsInput < 1 || $pointsInput > intdiv(PHP_INT_MAX, 100)) {
                        throw new InvalidArgumentException('Informe a quantidade de pontos inteiros para resgatar.');
                    }
                    $points = $pointsInput;
                    $cashCents = $points * 100;
                } else {
                    $amountInput = str_replace(',', '.', trim((string) ($_POST['cash_amount'] ?? '')));
                    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $amountInput)) {
                        throw new InvalidArgumentException('Informe um valor de saque válido.');
                    }
                    $cashCents = (int) round((float) $amountInput * 100);
                    if ($cashCents < 1) {
                        throw new InvalidArgumentException('O valor do saque precisa ser maior que zero.');
                    }
                }

                $db->beginTransaction();
                $savePixKey = $db->prepare('UPDATE users SET referral_pix_key = ? WHERE id = ?');
                $savePixKey->execute([$pixKey, (int) $reseller['id']]);
                if ($walletType === 'points') {
                    $debit = $db->prepare('
                        UPDATE users SET referral_points = referral_points - ?
                        WHERE id = ? AND referral_points >= ?
                    ');
                    $debit->execute([$points, (int) $reseller['id'], $points]);
                } else {
                    $debit = $db->prepare('
                        UPDATE users SET referral_cash_cents = referral_cash_cents - ?
                        WHERE id = ? AND referral_cash_cents >= ?
                    ');
                    $debit->execute([$cashCents, (int) $reseller['id'], $cashCents]);
                }
                if ($debit->rowCount() !== 1) {
                    throw new InvalidArgumentException('Saldo insuficiente para este saque.');
                }
                $createWithdrawal = $db->prepare('
                    INSERT INTO referral_withdrawals (user_id, wallet_type, points_amount, cash_amount_cents, pix_key)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $createWithdrawal->execute([
                    (int) $reseller['id'],
                    $walletType,
                    $points,
                    $cashCents,
                    $pixKey,
                ]);
                $db->commit();
                header('Location: credits.php?msg=withdrawal_requested');
                exit;
            }

            throw new InvalidArgumentException('Ação inválida.');
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Reseller credit operation failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Não foi possível processar a solicitação. Tente novamente.';
        }
    }
}

$message = match ($_GET['msg'] ?? '') {
    'order_created' => 'Pedido de lote criado. Copie o Pix e aguarde a conferência do administrador.',
    'withdrawal_requested' => 'Saque solicitado; o administrador fará o pagamento Pix manualmente.',
    default => '',
};
$ordersQuery = $db->prepare('
    SELECT id, txid, amount_cents, pix_payload, status, created_at
    FROM reseller_credit_orders
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 50
');
$ordersQuery->execute([(int) $reseller['id']]);
$orders = $ordersQuery->fetchAll();
$withdrawalsQuery = $db->prepare('
    SELECT id, wallet_type, points_amount, cash_amount_cents, pix_key, status, created_at
    FROM referral_withdrawals
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 50
');
$withdrawalsQuery->execute([(int) $reseller['id']]);
$withdrawals = $withdrawalsQuery->fetchAll();
$referralsQuery = $db->prepare('
    SELECT id, name, username, bonus_mac_amount, referral_points, referral_cash_cents
    FROM users
    WHERE parent_user_id = ? AND COALESCE(admin, 0) <> 1
    ORDER BY id DESC
');
$referralsQuery->execute([(int) $reseller['id']]);
$referrals = $referralsQuery->fetchAll();
$rewardsQuery = $db->prepare('
    SELECT rewards.points_amount, rewards.cash_amount_cents, rewards.bonus_mac_amount,
           orders.txid, orders.created_at, buyer.name AS buyer_name
    FROM referral_rewards AS rewards
    JOIN reseller_credit_orders AS orders ON orders.id = rewards.credit_order_id
    JOIN users AS buyer ON buyer.id = rewards.purchaser_user_id
    WHERE rewards.referrer_user_id = ?
    ORDER BY rewards.id DESC
    LIMIT 50
');
$rewardsQuery->execute([(int) $reseller['id']]);
$rewards = $rewardsQuery->fetchAll();
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Créditos e indicações | Área da revenda</title>
    <style>
        * { box-sizing: border-box; }
        html { scrollbar-width: none; }
        html::-webkit-scrollbar { display: none; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; overflow-x: clip; scrollbar-width: none; }
        body::-webkit-scrollbar { display: none; }
        main { max-width: 1100px; margin: auto; }
        header, .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { margin-bottom: 18px; padding: 20px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        p { color: #475467; line-height: 1.55; }
        label { display: block; margin: 12px 0; color: #475467; font-size: 12px; }
        input, textarea { display: block; width: 100%; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        textarea { min-height: 86px; resize: vertical; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        .button { display: inline-block; padding: 9px 12px; border-radius: 6px; background: #3157d5; color: #fff; }
        .message, .error { padding: 12px; border-radius: 6px; }
        .message { background: #ecfdf3; color: #067647; }
        .error { background: #fef3f2; color: #b42318; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .order { padding: 14px 0; border-top: 1px solid #eaecf0; }
        .order:first-child { border-top: 0; }
        .table-wrap { overflow: visible; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #eaecf0; text-align: left; overflow-wrap: anywhere; }
        th { color: #667085; font-size: 11px; text-transform: uppercase; }
        @media (max-width: 700px) {
            body { padding: 18px 10px; }
            header, .toolbar { align-items: flex-start; flex-direction: column; }
            .grid { grid-template-columns: 1fr; }
            .table-wrap thead { display: none; }
            .table-wrap table, .table-wrap tbody, .table-wrap tr, .table-wrap td { display: block; width: 100%; }
            .table-wrap tr { margin: 10px 0; padding: 8px; border: 1px solid #eaecf0; border-radius: 8px; }
            .table-wrap td { display: flex; justify-content: space-between; gap: 10px; border: 0; }
            .table-wrap td::before { content: attr(data-label); color: #667085; font-weight: 600; }
        }
    </style>
    <link rel="stylesheet" href="assets/panel.css?v=reseller-footer-center-20261025-3">
</head>
<body class="reseller-page credits-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1>Créditos e indicações</h1><a href="index.php">Voltar ao painel</a></header>
    <?php if ($message !== ''): ?><p class="message"><?= $escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif; ?>
    <section class="panel credit-balance" aria-label="Saldo de créditos MAC">
        <div><span>Créditos disponíveis</span><strong><?= (int) $creditQuota['available'] ?></strong></div>
        <div><span>Créditos totais</span><strong><?= (int) $creditQuota['mac_amount'] ?></strong></div>
        <div><span>Ativações em uso</span><strong><?= (int) $creditQuota['active_devices'] ?></strong></div>
        <div><span>Reservados para sub-revendas</span><strong><?= (int) $creditQuota['allocated_to_children'] ?></strong></div>
    </section>
    <section class="panel">
        <h2>Comprar lote de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs</h2>
        <?php if ((int) ($config['reseller_credit_amount_cents'] ?? 0) < 1): ?>
            <p>O administrador ainda não definiu o preço deste lote. A compra ficará disponível quando a configuração Pix for concluída.</p>
        <?php else: ?>
            <p>Valor do lote: <strong>R$ <?= number_format((int) $config['reseller_credit_amount_cents'] / 100, 2, ',', '.') ?></strong>. Após conferir o pagamento, o administrador libera <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs na sua conta. Não pague um pedido antigo depois que ele for recusado.</p>
            <form method="post" onsubmit="return confirm('Criar um pedido Pix de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs?')">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                <input type="hidden" name="action" value="create_credit_order">
                <button type="submit">Gerar Pix para <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs</button>
            </form>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Meus pedidos de créditos</h2>
        <?php if ($orders === []): ?>
            <p>Nenhum pedido de lote criado.</p>
        <?php else: foreach ($orders as $order): ?>
            <article class="order">
                <p><strong>Pedido #<?= (int) $order['id'] ?></strong> · R$ <?= number_format((int) $order['amount_cents'] / 100, 2, ',', '.') ?> · <?= $escape($order['status']) ?> · <?= $escape($order['created_at']) ?></p>
                <p>Identificador Pix: <code><?= $escape($order['txid']) ?></code></p>
                <?php if ($order['status'] === 'pending'): ?>
                    <label>Pix copia e cola<textarea readonly onclick="this.select()"><?= $escape($order['pix_payload']) ?></textarea></label>
                    <button type="button" onclick="copyPix(this)">Copiar código Pix</button>
                <?php endif; ?>
            </article>
        <?php endforeach; endif; ?>
    </section>
    <section class="panel">
        <h2>Contas indicadas (<?= count($referrals) ?>)</h2>
        <?php if ($referrals === []): ?>
            <p>Quando alguém criar uma conta pelo seu link, ela aparecerá aqui.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Revenda</th><th>Usuário</th><th>MACs de bônus</th><th>Pontos</th><th>Comissão Pix</th></tr></thead>
                    <tbody><?php foreach ($referrals as $referral): ?>
                        <tr>
                            <td data-label="Revenda"><?= $escape($referral['name']) ?></td>
                            <td data-label="Usuário">@<?= $escape($referral['username']) ?></td>
                            <td data-label="MACs de bônus"><?= (int) $referral['bonus_mac_amount'] ?></td>
                            <td data-label="Pontos"><?= (int) $referral['referral_points'] ?></td>
                            <td data-label="Comissão Pix">R$ <?= number_format((int) $referral['referral_cash_cents'] / 100, 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Recompensas recebidas</h2>
        <?php if ($rewards === []): ?>
            <p>A cada lote pago de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs por uma indicação, o bônus aparecerá aqui.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Indicado</th><th>Lote Pix</th><th>MACs recebidos</th><th>Pontos</th><th>Comissão Pix</th><th>Data</th></tr></thead>
                    <tbody><?php foreach ($rewards as $reward): ?>
                        <tr>
                            <td data-label="Indicado"><?= $escape($reward['buyer_name']) ?></td>
                            <td data-label="Lote Pix"><code><?= $escape($reward['txid']) ?></code></td>
                            <td data-label="MACs recebidos"><?= (int) $reward['bonus_mac_amount'] ?></td>
                            <td data-label="Pontos"><?= (int) $reward['points_amount'] ?></td>
                            <td data-label="Comissão Pix">R$ <?= number_format((int) $reward['cash_amount_cents'] / 100, 2, ',', '.') ?></td>
                            <td data-label="Data"><?= $escape($reward['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Solicitar saque Pix</h2>
        <p>10 pontos equivalem a R$ 10,00. As comissões em dinheiro e os pontos são saldos separados. O administrador confirma o pagamento manualmente.</p>
        <div class="grid">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                <input type="hidden" name="action" value="withdraw_points">
                <label>Pontos disponíveis<input value="<?= (int) $reseller['referral_points'] ?>" readonly></label>
                <label>Pontos a resgatar<input name="points" type="number" min="1" max="<?= (int) $reseller['referral_points'] ?>" step="1" required></label>
                <label>Chave Pix<input name="pix_key" maxlength="77" value="<?= $escape($reseller['referral_pix_key']) ?>" required></label>
                <button type="submit" <?= (int) $reseller['referral_points'] < 1 ? 'disabled' : '' ?>>Solicitar pontos em reais</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
                <input type="hidden" name="action" value="withdraw_cash">
                <label>Comissão disponível (R$)<input value="<?= number_format((int) $reseller['referral_cash_cents'] / 100, 2, ',', '.') ?>" readonly></label>
                <label>Valor a sacar (R$)<input name="cash_amount" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" required></label>
                <label>Chave Pix<input name="pix_key" maxlength="77" value="<?= $escape($reseller['referral_pix_key']) ?>" required></label>
                <button type="submit" <?= (int) $reseller['referral_cash_cents'] < 1 ? 'disabled' : '' ?>>Solicitar comissão</button>
            </form>
        </div>
    </section>
    <section class="panel">
        <h2>Histórico de saques</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Solicitação</th><th>Origem</th><th>Valor</th><th>Chave Pix</th><th>Status</th><th>Data</th></tr></thead>
                <tbody>
                <?php if ($withdrawals === []): ?>
                    <tr><td colspan="6">Nenhum saque solicitado.</td></tr>
                <?php else: foreach ($withdrawals as $withdrawal): ?>
                    <tr>
                        <td data-label="Solicitação">#<?= (int) $withdrawal['id'] ?></td>
                        <td data-label="Origem"><?= $withdrawal['wallet_type'] === 'points' ? 'Pontos' : 'Comissão' ?></td>
                        <td data-label="Valor">R$ <?= number_format((int) $withdrawal['cash_amount_cents'] / 100, 2, ',', '.') ?><?= $withdrawal['wallet_type'] === 'points' ? ' (' . (int) $withdrawal['points_amount'] . ' pontos)' : '' ?></td>
                        <td data-label="Chave Pix"><?= $escape($withdrawal['pix_key']) ?></td>
                        <td data-label="Status"><?= $escape($withdrawal['status']) ?></td>
                        <td data-label="Data"><?= $escape($withdrawal['created_at']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
async function copyPix(button) {
    const value = button.previousElementSibling.querySelector('textarea').value;
    try {
        await navigator.clipboard.writeText(value);
        button.textContent = 'Código Pix copiado';
    } catch (error) {
        button.previousElementSibling.querySelector('textarea').select();
        button.textContent = 'Selecione e copie o código Pix';
    }
}
</script>
</body>
</html>
