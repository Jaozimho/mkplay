<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';

$db = getDB();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador de dispositivo inválido.');
}

$device = getDeviceById($db, $id);
if ($device === null) {
    http_response_code(404);
    exit('Dispositivo não encontrado.');
}

$escape = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$showValue = static fn($value): string => trim((string) ($value ?? '')) !== ''
    ? $escape($value)
    : '<span class="device-empty">Não informado</span>';
$reseller = !empty($device['id_user']) ? getManagedUser($db, (int) $device['id_user']) : null;
$resellerName = $reseller === null
    ? 'Admin'
    : 'Revenda: ' . $reseller['name'] . ' (@' . $reseller['username'] . ')';
$isActive = !empty($device['active']);
$isExpired = isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null);
$statusText = $isExpired ? 'Vencido' : ($isActive ? 'Ativo' : 'Bloqueado');
$statusClass = $isExpired ? 'expired' : ($isActive ? 'active' : 'blocked');
$title = $device['title'] ?? 'NEW DEVICE';
$fields = [
    ['MAC address', $device['mac_address'] ?? ''],
    ['Device key', $device['key'] ?? ''],
    ['Responsável', $resellerName],
    ['Vencimento', $device['expire_date'] ?? ''],
    ['Tipo de conexão', (string) ($device['type'] ?? '') === '0' ? 'Xtream Codes' : 'Lista M3U'],
    ['Tipo de registro', (int) ($device['store_type'] ?? 1) === 2 ? 'Playlist' : 'Dispositivo'],
    ['DNS', $device['dns'] ?? ''],
    ['Usuário Xtream', $device['username'] ?? ''],
    ['Senha Xtream', $device['password'] ?? ''],
    ['URL da playlist', $device['url'] ?? ''],
    ['URL EPG', $device['epg_url'] ?? ''],
    ['PIN da playlist', $device['playlistpassword'] ?? ''],
    ['Proteção por PIN', (int) ($device['playlist_protected'] ?? 1) === 1 ? 'Ativada' : 'Desativada'],
    ['Criado em', $device['created_at'] ?? ''],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dados do dispositivo | Admin</title>
    <link rel="stylesheet" href="assets/panel.css">
    <style>
        .device-detail-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
        .device-detail-header h1 { margin: 0; font-size: 22px !important; }
        .device-detail-header p { margin: 4px 0 0; color: #667085; font-size: 12px; }
        .device-detail-actions { display: flex; align-items: center; gap: 8px; }
        .device-detail-actions a { padding: 7px 10px; border: 1px solid #eaecf0; border-radius: 6px; font-size: 12px; text-decoration: none; }
        .device-detail-panel { padding: 14px; }
        .device-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eaecf0; }
        .device-summary strong { color: #101828; font-size: 14px; }
        .device-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px 14px; margin: 0; }
        .device-field { display: grid; grid-template-columns: minmax(105px, .35fr) minmax(0, 1fr); align-items: baseline; gap: 8px; min-width: 0; padding: 7px 0; border-bottom: 1px solid #f0f1f3; }
        .device-field.wide { grid-column: 1 / -1; }
        .device-field dt { color: #667085; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .device-field dd { min-width: 0; margin: 0; color: #344054; font-size: 12px; overflow-wrap: anywhere; }
        .device-empty { color: #98a2b3; }
        .device-status { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .device-status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .device-status.active { background: #ecfdf3; color: #067647; }
        .device-status.blocked { background: #fef3f2; color: #b42318; }
        .device-status.expired { background: #fffaeb; color: #b54708; }
        @media (max-width: 760px) {
            .device-detail-header { align-items: flex-start; flex-direction: column; }
            .device-fields { grid-template-columns: 1fr; gap: 0; }
            .device-field.wide { grid-column: auto; }
        }
    </style>
</head>
<body class="admin-page device-detail-page">
    <?php require __DIR__ . '/navigation.php'; ?>
    <main>
        <header class="device-detail-header">
            <div>
                <h1>Dados do dispositivo</h1>
                <p><?= $escape($title) ?> · ID #<?= (int) $device['id'] ?></p>
            </div>
            <div class="device-detail-actions">
                <a href="devices.php">Voltar aos dispositivos</a>
                <a href="device_edit.php?id=<?= (int) $device['id'] ?>">Editar</a>
            </div>
        </header>
        <section class="panel device-detail-panel" aria-label="Dados completos do dispositivo">
            <div class="device-summary">
                <strong><?= $escape($title) ?></strong>
                <span class="device-status <?= $statusClass ?>"><?= $statusText ?></span>
            </div>
            <dl class="device-fields">
                <?php foreach ($fields as [$label, $value]): ?>
                    <?php $wide = in_array($label, ['DNS', 'URL da playlist', 'URL EPG'], true); ?>
                    <div class="device-field<?= $wide ? ' wide' : '' ?>">
                        <dt><?= $escape($label) ?></dt>
                        <dd><?= $showValue($value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </main>
</body>
</html>
