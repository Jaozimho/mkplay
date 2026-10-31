<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

if (empty($_SESSION['portals_csrf'])) {
    $_SESSION['portals_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['portals_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if ($action === 'create' || $action === 'update') {
                $name = trim($_POST['name'] ?? '');
                $url = trim($_POST['url'] ?? '');
                if ($name === '' || strlen($name) > 255 || $url === '') {
                    throw new InvalidArgumentException('Informe o nome e a URL do portal.');
                }
                if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Informe uma URL HTTP ou HTTPS válida.');
                }

                if ($action === 'create') {
                    createPortal($db, $name, $url);
                    $success = 'Portal adicionado.';
                } elseif ($id !== false && $id !== null && $id > 0) {
                    updatePortal($db, $id, $name, $url);
                    $success = 'Portal atualizado.';
                } else {
                    throw new InvalidArgumentException('Portal inválido.');
                }
            } elseif ($action === 'delete' && $id !== false && $id !== null && $id > 0) {
                deletePortal($db, $id);
                $success = 'Portal excluído.';
            } else {
                throw new InvalidArgumentException('Ação ou portal inválido.');
            }
            $_SESSION['portals_csrf'] = bin2hex(random_bytes(32));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('Portal admin database error: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Não foi possível salvar o portal.';
        }
    }
}

$portals = getPortals($db);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portais | Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #f7f8fa; color: #1d2939; font: 14px "Segoe UI", Arial, sans-serif; }
        main { max-width: 900px; margin: auto; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 22px; }
        h1 { margin: 0; color: #101828; font-size: 26px; }
        h2 { margin: 0 0 15px; font-size: 17px; }
        a { color: #3157d5; text-decoration: none; }
        .panel { overflow: hidden; border: 1px solid #eaecf0; border-radius: 10px; background: #fff; }
        .section { padding: 20px; border-bottom: 1px solid #eaecf0; }
        label { display: block; margin-bottom: 12px; color: #475467; font-size: 12px; }
        input { display: block; width: 100%; margin-top: 5px; padding: 9px 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; background: #3157d5; color: #fff; cursor: pointer; font: inherit; }
        button.danger { background: #b42318; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eaecf0; text-align: left; vertical-align: top; }
        th { background: #fcfcfd; color: #667085; font-size: 11px; text-transform: uppercase; }
        td form.edit { min-width: 300px; }
        td form.delete { margin-top: 8px; }
        .table-wrap { overflow-x: auto; }
        .message { margin-bottom: 15px; padding: 12px; border-radius: 6px; }
        .error { background: #fef3f2; color: #b42318; }
        .success { background: #ecfdf3; color: #067647; }
        .empty { padding: 28px; color: #667085; text-align: center; }
        .help { margin: 0 0 16px; color: #667085; line-height: 1.55; }
        .portal-url { overflow-wrap: anywhere; }
        .portal-url a { text-decoration: underline; }
        @media (max-width: 600px) { body { padding: 20px 10px; } .top { align-items: flex-start; flex-direction: column; } }
    </style>
    <link rel="stylesheet" href="assets/panel.css">
</head>
<body class="admin-page portals-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top"><h1>Portais</h1><a href="index.php">Voltar ao dashboard</a></header>
    <?php if ($error !== ''): ?><div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <p class="help">Cadastre aqui os servidores IPTV que o aplicativo pode apresentar como opções de portal. Essa lista é enviada no campo <code>portals</code> da resposta do dispositivo e também pode ser consultada em <a href="../api/portals.php" target="_blank" rel="noopener noreferrer"><code>/api/portals.php</code></a>. Ela não altera o tema da tela inicial nem substitui a playlist já vinculada ao dispositivo. Use a URL base HTTP ou HTTPS fornecida pelo seu serviço.</p>
    <section class="panel">
        <div class="section">
            <h2>Adicionar portal</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portals_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <label>Nome<input name="name" maxlength="255" required></label>
                <label>URL base do servidor<input name="url" type="url" maxlength="2048" placeholder="https://servidor.exemplo.com" required></label>
                <button type="submit">Adicionar</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Portal e URL</th></tr></thead>
                <tbody>
                <?php if ($portals === []): ?>
                    <tr>                    <td class="empty" colspan="2">Nenhum portal cadastrado. Adicione um servidor para disponibilizá-lo ao aplicativo.</td></tr>
                <?php else: ?>
                    <?php foreach ($portals as $portal): ?>
                        <?php
                        $portalUrl = (string) ($portal['url'] ?? '');
                        $portalScheme = strtolower((string) parse_url($portalUrl, PHP_URL_SCHEME));
                        $portalUrlIsSafe = filter_var($portalUrl, FILTER_VALIDATE_URL) !== false
                            && in_array($portalScheme, ['http', 'https'], true);
                        ?>
                        <tr>
                            <td><?= (int) $portal['id'] ?></td>
                            <td>
                                <p class="portal-url">
                                    <?php if ($portalUrlIsSafe): ?>
                                        <a href="<?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </p>
                                <form class="edit" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portals_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $portal['id'] ?>">
                                    <label>Nome<input name="name" maxlength="255" value="<?= htmlspecialchars((string) ($portal['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                                    <label>URL base do servidor<input name="url" type="url" maxlength="2048" value="<?= htmlspecialchars((string) ($portal['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                                    <button type="submit">Salvar</button>
                                </form>
                                <form class="delete" method="post" onsubmit="return confirm('Excluir este portal?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portals_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $portal['id'] ?>">
                                    <button class="danger" type="submit">Excluir</button>
                                </form>
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
