<?php
$adminBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/');
$pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
if ($pathInfo === 'devices') {
    header('Location: ' . $adminBase . '/devices.php', true, 302);
    exit;
}
$pageAliases = [
    'users.php',
    'profile.php',
    'adstatus.php',
    'portals.php',
    'intro.php',
    'themes.php',
    'sportconfira.php',
    'sports.php',
    'ads.php',
];
if (in_array($pathInfo, $pageAliases, true)) {
    header('Location: ' . $adminBase . '/' . $pathInfo, true, 302);
    exit;
}
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/referrals.php';
require_once __DIR__ . '/../api/includes/api_log.php';
$db = getDB();

if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

$actionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['dashboard_csrf'], $token)) {
        http_response_code(400);
        $actionError = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['device_action'] ?? '';
        $mac = trim((string) ($_POST['mac_address'] ?? ''));
        if ($mac === '' || !in_array($action, ['toggle', 'delete'], true)) {
            $actionError = 'Ação ou dispositivo inválido.';
        } else {
            try {
                if ($action === 'delete') {
                    deleteDevice($db, $mac);
                    header('Location: ?msg=del');
                } else {
                    $active = filter_input(INPUT_POST, 'active', FILTER_VALIDATE_INT);
                    if (!in_array($active, [0, 1], true)) {
                        throw new InvalidArgumentException('Status de dispositivo inválido.');
                    }
                    setDeviceActive($db, $mac, $active === 1);
                    apiLog('device_status_changed', [
                        'scope' => 'admin',
                        'mac' => apiMaskedMac($mac),
                        'device_active' => $active === 1,
                        'device_status' => $active === 1 ? 'active' : 'blocked',
                    ]);
                    header('Location: ?msg=status');
                }
                exit;
            } catch (InvalidArgumentException $e) {
                $actionError = $e->getMessage();
            } catch (PDOException $e) {
                error_log('Admin dashboard device action error: ' . $e->getMessage());
                http_response_code(500);
                $actionError = 'Não foi possível atualizar o dispositivo.';
            }
        }
    }
}

$devices = getDevices($db);
$resellers = $db->query('
    SELECT id, name, username
    FROM users
    WHERE COALESCE(admin, 0) <> 1
')->fetchAll();
$resellersById = array_column($resellers, null, 'id');
$deviceStats = getDeviceStats($db);
$totalDevices = $deviceStats['total'];
$activeDevices = $deviceStats['active'];
$blockedDevices = $deviceStats['blocked'];
$expiredDevices = $deviceStats['expired'];
$today = strtotime('today');
$referralPolicy = getReferralRewardPolicy();
$pendingReferralValidations = 0;
if (empty($adminDevicesPage)) {
    $pendingReferralValidations = (int) $db->query("
        SELECT COUNT(*)
        FROM reseller_credit_orders
        WHERE status = 'pending' AND referrer_user_id IS NOT NULL
    ")->fetchColumn();
}

$message = match ($_GET['msg'] ?? '') {
    'status' => ['class' => 'success', 'text' => 'Status do dispositivo atualizado.'],
    'del' => ['class' => 'success', 'text' => 'Dispositivo excluído com sucesso.'],
    'device_created' => ['class' => 'success', 'text' => 'Dispositivo criado com sucesso.'],
    'device_updated' => ['class' => 'success', 'text' => 'Dispositivo atualizado com sucesso.'],
    default => null,
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= !empty($adminDevicesPage) ? 'Dispositivos | Admin' : 'Dashboard | Admin' ?></title>
    <style>
        :root {
            color-scheme: light;
            --navy: #101828;
            --muted: #667085;
            --line: #eaecf0;
            --surface: #ffffff;
            --canvas: #f7f8fa;
            --blue: #3157d5;
            --green: #16804a;
            --red: #c43232;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: #1d2939; font-family: Inter, "Segoe UI", Arial, sans-serif; }
        .layout { min-height: 100vh; }
        main { padding: 34px clamp(20px, 4vw, 56px) 48px; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 28px; }
        .eyebrow { margin: 0 0 8px; color: var(--muted); font-size: 13px; }
        h1 { margin: 0; color: #101828; font-size: clamp(25px, 3vw, 32px); letter-spacing: -.7px; }
        .subtitle { margin: 9px 0 0; color: var(--muted); font-size: 14px; }
        .date-chip { padding: 10px 13px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: #475467; font-size: 13px; white-space: nowrap; }
        .flash { margin-bottom: 20px; padding: 13px 16px; border: 1px solid #abefc6; border-radius: 8px; background: #ecfdf3; color: #067647; font-size: 14px; }
        .metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 28px; }
        .metric { min-height: 138px; padding: 20px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: 0 1px 2px rgba(16, 24, 40, .03); }
        .metric-top { display: flex; align-items: center; justify-content: space-between; color: var(--muted); font-size: 13px; font-weight: 500; }
        .metric-icon { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; background: #eff4ff; color: var(--blue); font-size: 17px; }
        .metric-value { margin-top: 15px; color: #101828; font-size: 30px; font-weight: 700; letter-spacing: -.8px; }
        .metric-note { margin-top: 4px; color: #98a2b3; font-size: 12px; }
        .metric.blocked .metric-icon, .metric.expired .metric-icon { background: #fef3f2; color: var(--red); }
        .metric.active .metric-icon { background: #ecfdf3; color: var(--green); }
        .referral-rule { margin-bottom: 28px; padding: 22px 24px; }
        .referral-rule-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; }
        .referral-rule h2 { margin: 0; color: #101828; font-size: 17px; }
        .referral-rule p { margin: 6px 0 0; color: var(--muted); font-size: 13px; line-height: 1.5; }
        .referral-benefits { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
        .referral-benefit { min-width: 0; padding: 13px 14px; border: 1px solid var(--line); border-radius: 9px; background: #fcfcfd; }
        .referral-benefit strong { display: block; color: #101828; font-size: 20px; }
        .referral-benefit span { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; line-height: 1.4; }
        .referral-rule-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; }
        .referral-pending { color: #b54708 !important; font-weight: 600; }
        .referral-link { flex: 0 0 auto; padding: 9px 12px; border-radius: 7px; background: var(--blue); color: #fff; font-size: 13px; text-decoration: none; }
        .referral-link:hover { background: #2646b2; }
        .panel { overflow: hidden; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: 0 1px 2px rgba(16, 24, 40, .03); }
        .panel-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 22px 24px; border-bottom: 1px solid var(--line); }
        .panel-title { margin: 0; color: #101828; font-size: 17px; }
        .panel-description { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .table-tools { display: flex; gap: 10px; }
        .search, .filter { min-height: 38px; padding: 8px 11px; border: 1px solid #d0d5dd; border-radius: 7px; background: #fff; color: #344054; font: inherit; font-size: 13px; }
        .search { width: 230px; }
        .search:focus, .filter:focus { outline: 3px solid #dbe5ff; border-color: #8098f9; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { padding: 12px 18px; background: #fcfcfd; color: #667085; font-size: 11px; font-weight: 600; letter-spacing: .55px; text-align: left; text-transform: uppercase; }
        td { padding: 15px 18px; border-top: 1px solid #f0f1f3; color: #475467; font-size: 13px; }
        tbody tr:hover { background: #fafbff; }
        .device-name { color: #101828; font-weight: 600; }
        .device-id { color: #98a2b3; font-size: 12px; }
        .mono { color: #344054; font-family: Consolas, monospace; font-size: 12px; }
        .device-view-link { display: inline-block; margin-top: 4px; color: var(--blue); font-size: 11px; text-decoration: none; }
        .device-view-link:hover { text-decoration: underline; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .badge.active { background: #ecfdf3; color: #067647; }
        .badge.blocked { background: #fef3f2; color: #b42318; }
        .badge.expired { background: #fffaeb; color: #b54708; }
        .actions { display: flex; align-items: center; gap: 8px; }
        .action { padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; color: #475467; font-size: 12px; text-decoration: none; }
        .action:hover { background: #f9fafb; }
        .action.unblock { border-color: #abefc6; color: #067647; }
        .action.delete { border-color: #fecdca; color: #b42318; }
        .empty { padding: 44px 20px; color: var(--muted); text-align: center; }
        .empty strong { display: block; margin-bottom: 7px; color: #344054; font-size: 15px; }
        .table-footer { padding: 13px 20px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; }
        body.devices-page .metrics { display: none; }
        @media (max-width: 1100px) { .metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) {
            main { padding: 25px 16px 36px; }
            .page-header { flex-direction: column; margin-bottom: 22px; }
            .referral-rule { padding: 18px; }
            .referral-benefits { grid-template-columns: 1fr; gap: 8px; margin-top: 14px; }
            .referral-benefit { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
            .referral-benefit span { margin-top: 0; text-align: right; }
            .referral-rule-footer { align-items: flex-start; flex-direction: column; }
            .panel-header { align-items: flex-start; flex-direction: column; padding: 18px; }
            .table-tools { width: 100%; }
            .search { flex: 1; width: auto; min-width: 0; }
            .filter { max-width: 135px; }
        }
        @media (max-width: 430px) { .metrics { gap: 10px; } .metric { min-height: 126px; padding: 14px; } .metric-value { font-size: 26px; } .metric-top { font-size: 12px; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page<?= !empty($adminDevicesPage) ? ' devices-page' : ' dashboard-page' ?>">
    <div class="layout">
        <?php require __DIR__ . '/navigation.php'; ?>

        <main>
            <header class="page-header">
                <div>
                    <p class="eyebrow"><?= !empty($adminDevicesPage) ? 'Gerenciamento' : 'Visão geral' ?></p>
                    <h1><?= !empty($adminDevicesPage) ? 'Dispositivos' : 'Dashboard' ?></h1>
                    <p class="subtitle"><?= !empty($adminDevicesPage)
                        ? 'Gerencie os dispositivos registrados na sua API.'
                        : 'Acompanhe seus dispositivos e o status da operação.' ?></p>
                </div>
                <div class="date-chip"><?= htmlspecialchars(date('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></div>
            </header>

            <?php if ($message !== null): ?>
                <div class="flash"><?= htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($actionError !== ''): ?>
                <div class="flash error"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (empty($adminDevicesPage)): ?>
            <section class="metrics" aria-label="Resumo dos dispositivos">
                <article class="metric">
                    <div class="metric-top"><span>Total de dispositivos</span><span class="metric-icon">&#9635;</span></div>
                    <div class="metric-value"><?= $totalDevices ?></div>
                    <div class="metric-note">Todos os dispositivos cadastrados</div>
                </article>
                <article class="metric active">
                    <div class="metric-top"><span>Dispositivos ativos</span><span class="metric-icon">&#10003;</span></div>
                    <div class="metric-value"><?= $activeDevices ?></div>
                    <div class="metric-note">Com acesso liberado</div>
                </article>
                <article class="metric blocked">
                    <div class="metric-top"><span>Dispositivos bloqueados</span><span class="metric-icon">&#8856;</span></div>
                    <div class="metric-value"><?= $blockedDevices ?></div>
                    <div class="metric-note">Com acesso suspenso</div>
                </article>
                <article class="metric expired">
                    <div class="metric-top"><span>Licenças vencidas</span><span class="metric-icon">&#9200;</span></div>
                    <div class="metric-value"><?= $expiredDevices ?></div>
                    <div class="metric-note">Data de vencimento anterior a hoje</div>
                </article>
            </section>
            <?php endif; ?>

            <?php if (empty($adminDevicesPage)): ?>
            <section class="panel referral-rule" aria-labelledby="referral-rule-title">
                <div class="referral-rule-header">
                    <div>
                        <h2 id="referral-rule-title">Regra de indicações</h2>
                        <p>Quando uma revenda indicada compra um lote de <?= (int) $referralPolicy['credit_mac_amount'] ?> MACs, o administrador confere o pagamento e decide se a indicação é válida.</p>
                    </div>
                </div>
                <div class="referral-benefits">
                    <div class="referral-benefit">
                        <strong>+<?= (int) $referralPolicy['reward_mac_amount'] ?> MACs</strong>
                        <span>créditos bônus para quem indicou</span>
                    </div>
                    <div class="referral-benefit">
                        <strong>+<?= (int) $referralPolicy['reward_points'] ?> pontos</strong>
                        <span>equivalentes a R$ <?= number_format((int) $referralPolicy['reward_points'], 2, ',', '.') ?></span>
                    </div>
                    <div class="referral-benefit">
                        <strong>+R$ <?= number_format((int) $referralPolicy['reward_cash_cents'] / 100, 2, ',', '.') ?></strong>
                        <span>de comissão Pix para quem indicou</span>
                    </div>
                </div>
                <div class="referral-rule-footer">
                    <p class="<?= $pendingReferralValidations > 0 ? 'referral-pending' : '' ?>">
                        <?= $pendingReferralValidations > 0
                            ? $pendingReferralValidations . ' pedido(s) com indicação aguardando análise.'
                            : 'As recompensas só são liberadas quando o pagamento é aprovado e a indicação é validada pelo administrador.' ?>
                    </p>
                    <a class="referral-link" href="pix.php#credit-orders">Analisar indicações</a>
                </div>
            </section>
            <?php endif; ?>

            <section class="panel" id="devices">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Dispositivos</h2>
                        <p class="panel-description">Gerencie os dispositivos registrados na sua API.</p>
                    </div>
                    <div class="table-tools">
                        <input class="search" id="device-search" type="search" placeholder="Buscar MAC, título ou usuário..." aria-label="Buscar dispositivos">
                        <select class="filter" id="status-filter" aria-label="Filtrar por status">
                            <option value="all">Todos</option>
                            <option value="active">Ativos</option>
                            <option value="blocked">Bloqueados</option>
                            <option value="expired">Vencidos</option>
                        </select>
                        <a class="action" href="device_edit.php">Adicionar</a>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Dispositivo</th>
                                <th>MAC address</th>
                                <th>Device key</th>
                                <th>Usuário</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="device-rows">
                            <?php if (empty($devices)): ?>
                                <tr><td colspan="7" class="empty"><strong>Nenhum dispositivo registrado</strong>Os dispositivos aparecerão aqui quando forem cadastrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($devices as $device): ?>
                                    <?php
                                    $isActive = !empty($device['active']);
                                    $isExpired = isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null);
                                    $statusFilter = $isExpired ? 'expired' : ($isActive ? 'active' : 'blocked');
                                    $statusText = $isExpired ? 'Vencido' : ($isActive ? 'Ativo' : 'Bloqueado');
                                    $statusClass = $isExpired ? 'expired' : ($isActive ? 'active' : 'blocked');
                                    $title = $device['title'] ?? 'NEW DEVICE';
                                    $mac = $device['mac_address'] ?? '';
                                    $username = $device['username'] ?? '';
                                    $expires = $device['expire_date'] ?? '';
                                    $reseller = $resellersById[$device['id_user'] ?? ''] ?? null;
                                    $resellerName = $reseller === null
                                        ? 'Admin'
                                        : 'Revenda: ' . $reseller['name'] . ' (@' . $reseller['username'] . ')';
                                    $searchText = strtolower($mac . ' ' . $title . ' ' . $username . ' ' . ($device['key'] ?? '') . ' ' . ($device['dns'] ?? '') . ' ' . ($device['url'] ?? '') . ' ' . $resellerName);
                                    $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr data-status="<?= $statusFilter ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <div class="device-card-heading">
                                                <span class="device-name"><?= $escape($title) ?></span>
                                                <a class="device-view-link" href="device_view.php?id=<?= (int) $device['id'] ?>">Ver todos os dados</a>
                                            </div>
                                        </td>
                                        <td class="mono"><?= htmlspecialchars($mac, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="mono"><?= $escape($device['key'] ?? '') ?></td>
                                        <td><?= $escape($resellerName) ?></td>
                                        <td><?= htmlspecialchars($expires, ENT_QUOTES, 'UTF-8') ?: '<span class="device-id">Sem vencimento</span>' ?></td>
                                        <td class="device-status-cell"><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                        <td class="device-actions-cell">
                                            <div class="actions">
                                                <a class="action" href="device_edit.php?id=<?= (int) $device['id'] ?>">Editar</a>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['dashboard_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="device_action" value="toggle">
                                                    <input type="hidden" name="mac_address" value="<?= htmlspecialchars($mac, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                                                    <button class="action <?= $isActive ? '' : 'unblock' ?>" type="submit"><?= $isActive ? 'Bloquear' : 'Desbloquear' ?></button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('Tem certeza que deseja excluir este dispositivo?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['dashboard_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="device_action" value="delete">
                                                    <input type="hidden" name="mac_address" value="<?= htmlspecialchars($mac, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button class="action delete" type="submit">Excluir</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($devices)): ?>
                    <div class="table-footer" id="table-count"><?= $totalDevices ?> dispositivo(s) cadastrado(s)</div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        const searchInput = document.getElementById('device-search');
        const statusFilter = document.getElementById('status-filter');
        const deviceRows = Array.from(document.querySelectorAll('#device-rows tr[data-status]'));
        const tableCount = document.getElementById('table-count');

        function filterDevices() {
            const query = searchInput.value.trim().toLocaleLowerCase('pt-BR');
            const status = statusFilter.value;
            let visible = 0;

            deviceRows.forEach((row) => {
                const matchesSearch = row.dataset.search.includes(query);
                const matchesStatus = status === 'all' || row.dataset.status === status;
                const show = matchesSearch && matchesStatus;
                row.hidden = !show;
                if (show) visible++;
            });

            if (tableCount) {
                tableCount.textContent = `${visible} de ${deviceRows.length} dispositivo(s)`;
            }
        }

        searchInput.addEventListener('input', filterDevices);
        statusFilter.addEventListener('change', filterDevices);
    </script>
</body>
</html>
