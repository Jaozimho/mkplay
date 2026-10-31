<?php
require_once __DIR__ . '/require_reseller.php';
require_once __DIR__ . '/../api/includes/branding.php';
$db = getDB();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== false && $id !== null && $id > 0;
$device = null;
if ($isEdit) {
    $lookup = $db->prepare('SELECT * FROM ibo WHERE id = ? AND id_user = ?');
    $lookup->execute([$id, $reseller['id']]);
    $device = $lookup->fetch();
    if (!$device) {
        http_response_code(404);
        exit('Dispositivo não encontrado.');
    }
}

$values = $device ?? [
    'mac_address' => '',
    'key' => '',
    'username' => '',
    'password' => '',
    'expire_date' => '',
    'dns' => '',
    'epg_url' => '',
    'title' => '',
    'url' => '',
    'type' => '0',
    'playlistpassword' => '',
    'playlist_protected' => 1,
    'active' => 1,
];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['reseller_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        foreach (['mac_address', 'key', 'username', 'password', 'expire_date', 'dns', 'epg_url', 'title', 'url', 'type', 'playlistpassword'] as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        try {
            $mac = strtoupper(preg_replace('/[:-]/', '', $values['mac_address']));
            if (!preg_match('/^[0-9A-F]{12}$/', $mac)) {
                throw new InvalidArgumentException('Informe um MAC com 12 dígitos hexadecimais.');
            }
            $values['mac_address'] = implode(':', str_split($mac, 2));
            if ($values['title'] === '' || strlen($values['title']) > 255) {
                throw new InvalidArgumentException('Informe o nome do dispositivo (até 255 caracteres).');
            }
            if ($values['key'] === '') {
                throw new InvalidArgumentException('Informe a chave exibida no aplicativo junto ao MAC.');
            }
            if (!in_array($values['type'], ['0', '1'], true)) {
                throw new InvalidArgumentException('Selecione um modo de conexão válido.');
            }
            if ($values['expire_date'] !== '') {
                $date = DateTime::createFromFormat('!Y-m-d', $values['expire_date']);
                if (!$date || $date->format('Y-m-d') !== $values['expire_date']) {
                    throw new InvalidArgumentException('A data de vencimento deve estar no formato AAAA-MM-DD.');
                }
            } else {
                $values['expire_date'] = null;
            }
            if (strlen($values['key']) > 255 || strlen($values['username']) > 255 || strlen($values['password']) > 255 || strlen($values['dns']) > 2048 || strlen($values['epg_url']) > 2048 || strlen($values['url']) > 4096 || strlen($values['playlistpassword']) > 255) {
                throw new InvalidArgumentException('Um ou mais campos excedem o tamanho permitido.');
            }
            if ($values['type'] === '0') {
                if ($values['dns'] === '' || $values['username'] === '' || $values['password'] === '') {
                    throw new InvalidArgumentException('Para Xtream, informe DNS, usuário e senha.');
                }
                $dns = rtrim($values['dns'], '/');
                if (filter_var($dns, FILTER_VALIDATE_URL) === false || !in_array(parse_url($dns, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Informe uma URL HTTP ou HTTPS válida para o DNS.');
                }
                $values['url'] = $dns . '/get.php?username=' . rawurlencode($values['username'])
                    . '&password=' . rawurlencode($values['password']) . '&type=m3u_plus&output=ts';
            } elseif (
                filter_var($values['url'], FILTER_VALIDATE_URL) === false
                || !in_array(parse_url($values['url'], PHP_URL_SCHEME), ['http', 'https'], true)
            ) {
                throw new InvalidArgumentException('Para M3U, informe uma URL HTTP ou HTTPS válida.');
            }

            $duplicate = $db->prepare('SELECT COUNT(*) FROM ibo WHERE mac_address = ? AND id <> ?');
            $duplicate->execute([$values['mac_address'], $isEdit ? $id : 0]);
            if ((int) $duplicate->fetchColumn() > 0) {
                throw new InvalidArgumentException('Já existe um dispositivo com esse MAC.');
            }

            if (!$isEdit) {
                $db->beginTransaction();
                $lockQuota = $db->prepare('UPDATE users SET mac_amount = mac_amount WHERE id = ?');
                $lockQuota->execute([(int) $reseller['id']]);
                $quota = getResellerQuotaSummary($db, (int) $reseller['id']);
                if ($quota['available'] < 1) {
                    throw new InvalidArgumentException('Sua cota disponível acabou. Solicite uma recarga ao administrador antes de ativar novos MACs.');
                }
            }

            $values['id_user'] = (int) $reseller['id'];
            $values['store_type'] = 1;
            $values['active'] = $isEdit ? (int) $device['active'] : 1;
            saveDevice($db, $values, $isEdit ? $id : null);
            if ($db->inTransaction()) {
                $db->commit();
            }
            header('Location: index.php?msg=saved');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Reseller device save error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível salvar o dispositivo.';
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
    <title><?= $isEdit ? 'Editar' : 'Adicionar' ?> dispositivo | Área da revenda</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 850px; margin: auto; padding: 22px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 25px; }
        a { color: #3157d5; text-decoration: none; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        label { display: block; color: #475467; font-size: 12px; }
        input, select { display: block; width: 100%; margin-top: 5px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        .wide { grid-column: 1 / -1; }
        .connection-fields[hidden] { display: none !important; }
        .account-logo-preview { display: flex; align-items: center; gap: 12px; margin: 0 0 16px; color: #667085; font-size: 12px; }
        .account-logo-preview img { width: 76px; height: 52px; object-fit: contain; border: 1px solid #eaecf0; border-radius: 7px; background: #fff; }
        button { margin-top: 16px; padding: 10px 14px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        .error { margin-bottom: 14px; padding: 12px; border-radius: 6px; background: #fef3f2; color: #b42318; }
        .help { color: #667085; line-height: 1.5; }
        @media (max-width: 560px) { body { padding: 16px 9px; } main { padding: 17px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } .wide { grid-column: auto; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="reseller-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1><?= $isEdit ? 'Editar dispositivo' : 'Adicionar dispositivo' ?></h1><a href="index.php">Voltar</a></header>
    <?php if ($error !== ''): ?><div class="error"><?= $escape($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['reseller_csrf']) ?>">
        <div class="fields">
            <label>Endereço MAC<input name="mac_address" maxlength="17" placeholder="00:11:22:33:44:55" value="<?= $escape($values['mac_address']) ?>" required></label>
            <label>Chave do dispositivo<input name="key" maxlength="255" inputmode="numeric" placeholder="Ex.: 713062" value="<?= $escape($values['key']) ?>" required></label>
            <label>Nome do dispositivo<input name="title" maxlength="255" value="<?= $escape($values['title']) ?>" required></label>
            <label>Modo de conexão<select name="type" id="connection-type"><option value="0" <?= (string) $values['type'] === '0' ? 'selected' : '' ?>>Xtream Codes</option><option value="1" <?= (string) $values['type'] === '1' ? 'selected' : '' ?>>Lista M3U</option></select></label>
            <label>Vencimento<input name="expire_date" type="date" value="<?= $escape(isset($values['expire_date']) ? substr((string) $values['expire_date'], 0, 10) : '') ?>"></label>
            <div class="connection-fields xtream wide">
                <div class="fields">
                    <label>DNS<input name="dns" maxlength="2048" value="<?= $escape($values['dns']) ?>"></label>
                    <label>Usuário Xtream<input name="username" maxlength="255" value="<?= $escape($values['username']) ?>"></label>
                    <label class="wide">Senha Xtream<input name="password" maxlength="255" value="<?= $escape($values['password']) ?>"></label>
                </div>
            </div>
            <div class="connection-fields m3u wide">
                <div class="fields">
                    <label class="wide">URL M3U<input name="url" type="url" maxlength="4096" value="<?= $escape($values['url']) ?>"></label>
                    <label>URL EPG<input name="epg_url" maxlength="2048" value="<?= $escape($values['epg_url']) ?>"></label>
                    <label>Senha de proteção da playlist<input name="playlistpassword" maxlength="255" value="<?= $escape($values['playlistpassword']) ?>"></label>
                </div>
            </div>
        </div>
        <?php $accountLogoUrl = getAccountLogoUrl($db, (int) $reseller['id']); ?>
        <div class="account-logo-preview">
            <?php if ($accountLogoUrl !== ''): ?>
                <img src="<?= $escape($accountLogoUrl) ?>" alt="Logo desta revenda para o APK">
                <span>Esta logo será enviada aos dispositivos desta conta.</span>
            <?php else: ?>
                <span>Nenhuma logo cadastrada. <a href="branding.php">Carregar logo do APK</a></span>
            <?php endif; ?>
        </div>
        <p class="help">Informe o MAC e a chave exatamente como aparecem no aplicativo. Dispositivos ativos respeitam o limite definido para sua conta.</p>
        <button type="submit">Salvar dispositivo</button>
    </form>
</main>
<script>
    const connectionType = document.getElementById('connection-type');
    const xtreamFields = document.querySelectorAll('.connection-fields.xtream');
    const m3uFields = document.querySelectorAll('.connection-fields.m3u');
    function updateConnectionFields() {
        const xtream = connectionType.value === '0';
        xtreamFields.forEach((field) => { field.hidden = !xtream; });
        m3uFields.forEach((field) => { field.hidden = xtream; });
    }
    connectionType.addEventListener('change', updateConnectionFields);
    updateConnectionFields();
</script>
</body>
</html>
