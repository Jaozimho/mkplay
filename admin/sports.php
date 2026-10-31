<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

if (empty($_SESSION['sports_csrf'])) {
    $_SESSION['sports_csrf'] = bin2hex(random_bytes(32));
}

$fields = ['header_n', 'border_c', 'background_c', 'text_c', 'days', 'api'];
$labels = [
    'header_n' => 'Nome do cabeçalho',
    'border_c' => 'Cor da borda',
    'background_c' => 'Cor do fundo',
    'text_c' => 'Cor do texto',
    'days' => 'Dias',
    'api' => 'API',
];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['sports_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if ($action === 'save_api_sports_key') {
                $apiKey = $_POST['api_sports_key'] ?? '';
                if (!is_string($apiKey) || trim($apiKey) === '' || strlen(trim($apiKey)) > 255) {
                    throw new InvalidArgumentException('Informe uma chave API-Sports válida.');
                }
                $saveKey = $db->prepare("
                    INSERT INTO app_settings (setting_key, setting_value)
                    VALUES ('api_sports_key', ?)
                    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
                ");
                $saveKey->execute([trim($apiKey)]);
                $success = 'Chave da API-Sports salva no servidor.';
            } elseif ($action === 'clear_api_sports_key') {
                $clearKey = $db->prepare("DELETE FROM app_settings WHERE setting_key = 'api_sports_key'");
                $clearKey->execute();
                $success = 'Chave da API-Sports removida do servidor.';
            } elseif ($action === 'create' || $action === 'update') {
                $values = [];
                foreach ($fields as $field) {
                    $value = $_POST[$field] ?? '';
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Valores inválidos.');
                    }
                    $values[$field] = trim($value);
                }

                if ($action === 'create') {
                    createSport($db, $values);
                    $success = 'Configuração esportiva adicionada.';
                } elseif ($id !== false && $id !== null && $id > 0) {
                    updateSport($db, $id, $values);
                    $success = 'Configuração esportiva atualizada.';
                } else {
                    throw new InvalidArgumentException('Registro inválido.');
                }
            } elseif ($action === 'delete' && $id !== false && $id !== null && $id > 0) {
                deleteSport($db, $id);
                $success = 'Configuração esportiva excluída.';
            } else {
                throw new InvalidArgumentException('Ação ou registro inválido.');
            }

            $_SESSION['sports_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            http_response_code(500);
            $error = 'Não foi possível salvar a alteração no banco.';
        }
    }
}

$sports = getSports($db);
$apiKeySetting = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'api_sports_key'");
$apiKeySetting->execute();
$storedApiKey = trim((string) ($apiKeySetting->fetchColumn() ?: ''));
$hasStoredApiKey = $storedApiKey !== '';
$hasEnvironmentApiKey = trim((string) (getenv('API_SPORTS_KEY') ?: '')) !== '';
$maskedStoredApiKey = $hasStoredApiKey
    ? substr($storedApiKey, 0, 4) . str_repeat('*', 12)
    : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Esportes | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 1050px; margin: auto; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 22px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        h2 { margin: 0 0 15px; font-size: 17px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { overflow: hidden; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .section { padding: 20px; border-bottom: 1px solid #eaecf0; }
        .fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        label { display: block; color: #475467; font-size: 12px; }
        input { display: block; width: 100%; min-width: 0; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        .submit { margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eaecf0; text-align: left; vertical-align: top; }
        th { background: #fcfcfd; color: #667085; font-size: 11px; text-transform: uppercase; }
        .edit-row { display: flex; align-items: flex-start; gap: 10px; }
        td form.edit { min-width: 620px; }
        .edit-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .edit-actions { display: flex; gap: 8px; margin-top: 8px; }
        .message { margin-bottom: 15px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .empty { padding: 28px; color: #667085; text-align: center; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 650px) { body { padding: 20px 10px; } .top { align-items: flex-start; flex-direction: column; } .fields, .edit-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page sports-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top">
        <h1>Configurações de esportes</h1>
        <a href="index.php">Voltar ao dashboard</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="section">
            <h2>API-Sports — jogos do dia</h2>
            <p>Configure a chave usada pelo endpoint <code>/api/sport.php</code>. A chave não é exibida depois de salva e não é enviada ao aplicativo.</p>
            <p><strong>Status:</strong>
                <?php if ($hasEnvironmentApiKey): ?>
                    Chave configurada (variável do servidor)
                <?php elseif ($hasStoredApiKey): ?>
                    Chave salva: <code><?= htmlspecialchars($maskedStoredApiKey, ENT_QUOTES, 'UTF-8') ?></code>
                <?php else: ?>
                    Chave não configurada
                <?php endif; ?>
            </p>
            <div class="api-key-actions">
            <form class="api-key-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sports_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_api_sports_key">
                <label for="api-sports-key">Chave API-Sports
                    <input id="api-sports-key" type="password" name="api_sports_key" maxlength="255" autocomplete="new-password" required>
                </label>
                <button class="submit" type="submit">Salvar chave</button>
            </form>
            <?php if ($hasStoredApiKey): ?>
                <form class="api-key-clear" method="post" onsubmit="return confirm('Remover a chave da API-Sports?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sports_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="clear_api_sports_key">
                    <button class="danger" type="submit">Remover chave salva</button>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="section">
            <h2>Adicionar configuração</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sports_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <div class="fields">
                    <?php foreach ($fields as $field): ?>
                        <label><?= htmlspecialchars($labels[$field], ENT_QUOTES, 'UTF-8') ?>
                            <input type="text" name="<?= $field ?>" maxlength="255">
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="submit" type="submit">Adicionar</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Campos da configuração</th></tr></thead>
                <tbody>
                <?php if ($sports === []): ?>
                    <tr><td class="empty" colspan="2">Nenhuma configuração cadastrada ainda.</td></tr>
                <?php else: ?>
                    <?php foreach ($sports as $sport): ?>
                        <tr>
                            <td><?= (int) $sport['id'] ?></td>
                            <td>
                              <div class="edit-row">
                                <form class="edit" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sports_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $sport['id'] ?>">
                                    <div class="edit-grid">
                                        <?php foreach ($fields as $field): ?>
                                            <label><?= htmlspecialchars($labels[$field], ENT_QUOTES, 'UTF-8') ?>
                                                <input type="text" name="<?= $field ?>" maxlength="255" value="<?= htmlspecialchars((string) ($sport[$field] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="edit-actions">
                                        <button type="submit">Salvar</button>
                                    </div>
                                </form>
                                <form method="post" onsubmit="return confirm('Excluir esta configuração?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sports_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $sport['id'] ?>">
                                    <button class="danger" type="submit">Excluir</button>
                                </form>
                              </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
