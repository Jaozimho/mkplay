<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

if (empty($_SESSION['sportconfira_csrf'])) {
    $_SESSION['sportconfira_csrf'] = bin2hex(random_bytes(32));
}

$fields = ['title', 'url', 'c1', 'c2', 'c3'];
$labels = [
    'title' => 'Título',
    'url' => 'URL do widget',
    'c1' => 'Cor 1',
    'c2' => 'Cor 2',
    'c3' => 'Cor 3',
];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['sportconfira_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        try {
            if ($action === 'create' || $action === 'update') {
                $values = [];
                foreach ($fields as $field) {
                    $value = $_POST[$field] ?? '';
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Valores inválidos.');
                    }
                    $values[$field] = trim($value);
                }
                if ($values['title'] === '') {
                    throw new InvalidArgumentException('Informe o título.');
                }
                if (strlen($values['title']) > 25 || strlen($values['c1']) > 25 || strlen($values['c2']) > 25 || strlen($values['c3']) > 25) {
                    throw new InvalidArgumentException('Título e cores aceitam no máximo 25 caracteres.');
                }
                if (strlen($values['url']) > 200) {
                    throw new InvalidArgumentException('A URL aceita no máximo 200 caracteres.');
                }

                if ($action === 'create') {
                    createSportConfig($db, $values);
                    $success = 'Configuração adicionada.';
                } elseif ($id !== false && $id !== null && $id > 0) {
                    updateSportConfig($db, $id, $values);
                    $success = 'Configuração atualizada.';
                } else {
                    throw new InvalidArgumentException('Registro inválido.');
                }
            } elseif ($action === 'delete' && $id !== false && $id !== null && $id > 0) {
                deleteSportConfig($db, $id);
                $success = 'Configuração excluída.';
            } else {
                throw new InvalidArgumentException('Ação ou registro inválido.');
            }

            $_SESSION['sportconfira_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            http_response_code(500);
            $error = 'Não foi possível salvar a alteração no banco.';
        }
    }
}

$configs = getSportConfigs($db);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuração esportiva | Admin</title>
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
        .fields { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
        label { display: block; color: #475467; font-size: 12px; }
        input { display: block; width: 100%; min-width: 0; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        .submit { margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eaecf0; text-align: left; vertical-align: top; }
        th { background: #fcfcfd; color: #667085; font-size: 11px; text-transform: uppercase; }
        td form.edit { min-width: 700px; }
        .table-wrap { overflow-x: auto; }
        .edit-row { display: flex; align-items: flex-start; gap: 10px; }
        .edit-grid { display: grid; grid-template-columns: repeat(5, minmax(100px, 1fr)); gap: 8px; }
        .edit-actions { margin-top: 8px; }
        .message { margin-bottom: 15px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .empty { padding: 28px; color: #667085; text-align: center; }
        @media (max-width: 750px) { body { padding: 20px 10px; } .top { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page sportconfira-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top">
        <h1>Configuração esportiva</h1>
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
            <h2>Adicionar configuração</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sportconfira_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <div class="fields">
                    <?php foreach ($fields as $field): ?>
                        <label><?= htmlspecialchars($labels[$field], ENT_QUOTES, 'UTF-8') ?>
                            <input type="text" name="<?= $field ?>" maxlength="<?= $field === 'url' ? 200 : 25 ?>" <?= $field === 'title' ? 'required' : '' ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="submit" type="submit">Adicionar</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Configuração</th></tr></thead>
                <tbody>
                <?php if ($configs === []): ?>
                    <tr><td class="empty" colspan="2">Nenhuma configuração cadastrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($configs as $config): ?>
                        <tr>
                            <td><?= (int) $config['id'] ?></td>
                            <td>
                                <div class="edit-row">
                                    <form class="edit" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sportconfira_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= (int) $config['id'] ?>">
                                        <div class="edit-grid">
                                            <?php foreach ($fields as $field): ?>
                                                <label><?= htmlspecialchars($labels[$field], ENT_QUOTES, 'UTF-8') ?>
                                                    <input type="text" name="<?= $field ?>" maxlength="<?= $field === 'url' ? 200 : 25 ?>" value="<?= htmlspecialchars((string) ($config[$field] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $field === 'title' ? 'required' : '' ?>>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="edit-actions"><button type="submit">Salvar</button></div>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Excluir esta configuração?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sportconfira_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $config['id'] ?>">
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
