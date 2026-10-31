<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== false && $id !== null && $id > 0;
$device = $isEdit ? getDeviceById($db, $id) : null;
if ($isEdit && $device === null) {
    http_response_code(404);
    exit('Dispositivo não encontrado.');
}

if (empty($_SESSION['device_csrf'])) {
    $_SESSION['device_csrf'] = bin2hex(random_bytes(32));
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
    'id_user' => null,
    'store_type' => 1,
    'active' => 1,
];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['device_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $textFields = ['mac_address', 'key', 'username', 'password', 'expire_date', 'dns', 'epg_url', 'title', 'url', 'type', 'playlistpassword'];
        foreach ($textFields as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $ownerInput = trim((string) ($_POST['id_user'] ?? ''));
        $values['playlist_protected'] = isset($_POST['playlist_protected']) ? 1 : 0;
        $ownerId = $ownerInput === '' ? null : filter_var($ownerInput, FILTER_VALIDATE_INT);
        $storeType = filter_input(INPUT_POST, 'store_type', FILTER_VALIDATE_INT);
        $active = filter_input(INPUT_POST, 'active', FILTER_VALIDATE_INT);

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
            if (!in_array($storeType, [1, 2], true) || !in_array($active, [0, 1], true)) {
                throw new InvalidArgumentException('Status ou tipo de conta inválido.');
            }
            if ($values['expire_date'] !== '') {
                $date = DateTime::createFromFormat('!Y-m-d', $values['expire_date']);
                if (!$date || $date->format('Y-m-d') !== $values['expire_date']) {
                    throw new InvalidArgumentException('A data de vencimento deve estar no formato válido AAAA-MM-DD.');
                }
            } else {
                $values['expire_date'] = null;
            }
            if ($ownerId === false || ($ownerId !== null && getManagedUser($db, $ownerId) === null)) {
                throw new InvalidArgumentException('Selecione uma revenda existente ou deixe sem atribuição.');
            }
            if (strlen($values['key']) > 255 || strlen($values['username']) > 255 || strlen($values['password']) > 255 || strlen($values['dns']) > 2048 || strlen($values['epg_url']) > 2048 || strlen($values['url']) > 4096 || strlen($values['playlistpassword']) > 255) {
                throw new InvalidArgumentException('Um ou mais campos excedem o tamanho permitido.');
            }

            if ($values['type'] === '0') {
                if ($values['dns'] === '' || $values['username'] === '' || $values['password'] === '') {
                    throw new InvalidArgumentException('Para Xtream, informe DNS, usuário e senha.');
                }
                $baseUrl = rtrim($values['dns'], '/');
                if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Informe uma URL HTTP ou HTTPS válida para o DNS.');
                }
                $values['url'] = $baseUrl . '/get.php?username=' . rawurlencode($values['username'])
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
                throw new InvalidArgumentException('Já existe um dispositivo cadastrado com este MAC.');
            }

            $values['id_user'] = $ownerId;
            $values['store_type'] = $storeType;
            $values['active'] = $active;
            saveDevice($db, $values, $isEdit ? $id : null);
            header('Location: index.php?msg=' . ($isEdit ? 'device_updated' : 'device_created'));
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('Admin device save error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível salvar o dispositivo no banco.';
        }
    }
}

$users = $db->query('SELECT id, name, username, logo FROM users WHERE COALESCE(admin, 0) <> 1 ORDER BY name, username')->fetchAll();
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$adminLogoUrl = getAccountLogoUrl($db, null);
$activationUrl = '';
if ($isEdit && (string) ($values['key'] ?? '') !== '') {
    $activationUrl = getPublicBaseUrl() . '/ativar.php';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isEdit ? 'Editar' : 'Adicionar' ?> dispositivo | IBO Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 30px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 900px; margin: auto; padding: 22px; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; color: #101828; font-size: 25px; }
        a { color: #3157d5; text-decoration: none; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        label { display: block; color: #475467; font-size: 12px; }
        input, select { display: block; width: 100%; margin-top: 5px; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; font: inherit; }
        .wide { grid-column: 1 / -1; }
        .connection-fields[hidden] { display: none !important; }
        .owner-logo-preview { display: flex; align-items: center; gap: 12px; margin-top: 8px; color: #667085; font-size: 12px; }
        .owner-logo-preview img { width: 76px; height: 52px; object-fit: contain; border: 1px solid #eaecf0; border-radius: 7px; background: #fff; }
        .owner-logo-preview img[hidden], .owner-logo-empty[hidden] { display: none !important; }
        button { margin-top: 17px; padding: 10px 14px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        .message { margin-bottom: 15px; padding: 12px; border-radius: 6px; background: #fef3f2; color: #b42318; }
        .help { color: #667085; line-height: 1.5; }
        .activation { margin-top: 24px; padding: 18px; border: 1px solid #d0d5dd; border-radius: 8px; }
        .activation h2 { margin: 0 0 8px; font-size: 18px; }
        .activation p { color: #475467; line-height: 1.5; }
        #activation-qr { display: grid; min-height: 270px; place-items: center; }
        #activation-qr img, #activation-qr canvas { max-width: 100%; height: auto; }
        #activation-link { width: 100%; }
        .qr-error { color: #b42318 !important; }
        @media (max-width: 560px) { body { padding: 16px 9px; } main { padding: 17px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } .wide { grid-column: auto; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header><h1><?= $isEdit ? 'Editar dispositivo' : 'Adicionar dispositivo' ?></h1><a href="index.php">Voltar ao dashboard</a></header>
    <?php if ($error !== ''): ?><div class="message"><?= $escape($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['device_csrf']) ?>">
        <div class="fields">
            <label>Endereço MAC<input name="mac_address" maxlength="17" placeholder="00:11:22:33:44:55" value="<?= $escape($values['mac_address']) ?>" required></label>
            <label>Nome do dispositivo<input name="title" maxlength="255" value="<?= $escape($values['title']) ?>" required></label>
            <label>Tipo de conexão<select name="type" id="connection-type"><option value="0" <?= (string) $values['type'] === '0' ? 'selected' : '' ?>>Xtream Codes</option><option value="1" <?= (string) $values['type'] === '1' ? 'selected' : '' ?>>Lista M3U</option></select></label>
            <label>Revenda
                <select name="id_user" id="device-owner">
                    <option value="" data-logo="<?= $escape($adminLogoUrl) ?>" <?= empty($values['id_user']) ? 'selected' : '' ?>>Admin (sem revenda)</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" data-logo="<?= $escape(getAccountLogoUrl($db, (int) $user['id'])) ?>" <?= (string) ($values['id_user'] ?? '') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= $escape($user['name'] . ' (' . $user['username'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="owner-logo-preview" id="owner-logo-preview" aria-live="polite">
                    <img id="owner-logo-image" alt="Logo da conta selecionada" hidden>
                    <span id="owner-logo-empty" class="owner-logo-empty">Sem logo carregada para esta conta.</span>
                </span>
            </label>
            <label>Vencimento<input name="expire_date" type="date" value="<?= $escape(isset($values['expire_date']) ? substr((string) $values['expire_date'], 0, 10) : '') ?>"></label>
            <label>Tipo de registro<select name="store_type"><option value="1" <?= (int) $values['store_type'] === 1 ? 'selected' : '' ?>>Dispositivo</option><option value="2" <?= (int) $values['store_type'] === 2 ? 'selected' : '' ?>>Playlist</option></select></label>
            <label>Status<select name="active"><option value="1" <?= (int) $values['active'] === 1 ? 'selected' : '' ?>>Ativo</option><option value="0" <?= (int) $values['active'] === 0 ? 'selected' : '' ?>>Bloqueado</option></select></label>
            <label>Chave do dispositivo<input name="key" maxlength="255" inputmode="numeric" placeholder="Ex.: 713062" value="<?= $escape($values['key']) ?>" required></label>
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
                    <label>Senha de proteção da playlist<input name="playlistpassword" inputmode="numeric" maxlength="12" value="<?= $escape($values['playlistpassword']) ?>"></label>
                    <label class="wide"><span><input type="checkbox" name="playlist_protected" value="1" <?= (int) ($values['playlist_protected'] ?? 1) === 1 ? 'checked' : '' ?> style="display:inline-block;width:auto;margin-right:6px">Exigir PIN para abrir a playlist</span></label>
                </div>
            </div>
        </div>
        <p class="help">Informe o MAC e a chave exatamente como aparecem no aplicativo. Xtream mostra DNS, usuário e senha; M3U mostra URL da playlist, EPG e senha de proteção.</p>
        <button type="submit"><?= $isEdit ? 'Salvar alterações' : 'Criar dispositivo' ?></button>
    </form>
    <?php if ($isEdit && $activationUrl !== ''): ?>
        <section class="activation">
            <h2>QR Code universal de ativação</h2>
            <p>Este QR é igual para todos os dispositivos. O cliente informa o MAC e a chave abaixo na página que abrir; depois configura a playlist e segue para o Pix.</p>
            <div id="activation-qr" data-url="<?= $escape($activationUrl) ?>" aria-label="QR Code de ativação"></div>
            <label for="activation-link">Link de ativação</label>
            <input id="activation-link" type="url" readonly value="<?= $escape($activationUrl) ?>">
            <p id="activation-message" role="status" aria-live="polite"></p>
        </section>
    <?php endif; ?>
</main>
<?php if ($isEdit && $activationUrl !== ''): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<?php endif; ?>
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

    const ownerSelect = document.getElementById('device-owner');
    const ownerLogoImage = document.getElementById('owner-logo-image');
    const ownerLogoEmpty = document.getElementById('owner-logo-empty');
    function updateOwnerLogo() {
        const logoUrl = ownerSelect.selectedOptions[0]?.dataset.logo || '';
        ownerLogoImage.src = logoUrl;
        ownerLogoImage.hidden = logoUrl === '';
        ownerLogoEmpty.hidden = logoUrl !== '';
    }
    ownerSelect.addEventListener('change', updateOwnerLogo);
    updateOwnerLogo();

    const activationQr = document.getElementById('activation-qr');
    if (activationQr) {
        const message = document.getElementById('activation-message');
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof window.QRCode !== 'function') {
                message.className = 'qr-error';
                message.textContent = 'Não foi possível carregar o gerador do QR. Confira a conexão e recarregue a página.';
                return;
            }
            new window.QRCode(activationQr, {
                text: activationQr.dataset.url,
                width: 256,
                height: 256,
                correctLevel: window.QRCode.CorrectLevel.M
            });
        });
    }
</script>
</body>
</html>
