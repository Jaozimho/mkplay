<?php
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/../admin/require_admin.php';

    if (empty($_SESSION['device_import_csrf'])) {
        $_SESSION['device_import_csrf'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['device_import_csrf'], $token)) {
            http_response_code(400);
            exit('Sessão expirada. Atualize a página e tente novamente.');
        }
        if (($_POST['action'] ?? '') !== 'import_devices') {
            http_response_code(400);
            exit('Ação inválida.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Método não permitido.');
    }
}

function importOutput(string $message, bool $isError = false): void
{
    global $isCli;
    if ($isCli) {
        fwrite($isError ? STDERR : STDOUT, $message . PHP_EOL);
        return;
    }
    $class = $isError ? 'error' : 'success';
    echo '<p class="' . $class . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}

function isSqliteBusy(Throwable $exception): bool
{
    if (!$exception instanceof PDOException) {
        return false;
    }

    $driverCode = $exception->errorInfo[1] ?? null;
    return in_array((int) $driverCode, [5, 6], true)
        || str_contains(strtolower($exception->getMessage()), 'database is locked')
        || str_contains(strtolower($exception->getMessage()), 'database is busy');
}

if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = htmlspecialchars($_SESSION['device_import_csrf'], ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Importar dispositivos</title><style>body{font:16px Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 18px;color:#1d2939}form{margin:24px 0}button{padding:11px 16px;background:#3157d5;color:white;border:0;border-radius:6px;cursor:pointer}.error{color:#b42318}.success{color:#067647}</style>';
    echo '<h1>Importar dispositivos antigos</h1><p>Os registros serão adicionados ao painel. MACs já cadastrados não serão alterados.</p>';
    echo '<form method="post"><input type="hidden" name="csrf_token" value="' . $token . '"><input type="hidden" name="action" value="import_devices"><button type="submit">Executar importação</button></form>';
    echo '<p><a href="../admin/devices.php">Voltar aos dispositivos</a></p></html>';
    exit;
}

$sourcePath = dirname(__DIR__) . '/bancoant/ansdb.db';
if (!is_file($sourcePath) || !is_readable($sourcePath)) {
    http_response_code(500);
    importOutput("Banco antigo não encontrado ou sem permissão de leitura: {$sourcePath}", true);
    exit;
}

$targetPath = dirname(__DIR__) . '/api/db/.ansdb.db';
if (!is_file($targetPath) || filesize($targetPath) === 0 || !is_writable($targetPath)) {
    http_response_code(500);
    importOutput("Banco existente do painel não encontrado, vazio ou sem permissão de gravação: {$targetPath}", true);
    exit;
}

$inserted = 0;
$duplicates = 0;
$skipped = 0;
$currentMac = '';

try {
    $source = new PDO('sqlite:' . $sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $source->exec('PRAGMA busy_timeout = 5000');

    $sourceTables = $source->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ibo', $sourceTables, true)) {
        throw new RuntimeException('A tabela ibo não existe no banco antigo.');
    }

    $sourceColumns = $source->query('PRAGMA table_info("ibo")')->fetchAll();
    $columnNames = array_fill_keys(array_map(
        static fn (array $column): string => strtolower((string) $column['name']),
        $sourceColumns
    ), true);
    foreach (['mac_address', 'key'] as $requiredColumn) {
        if (!isset($columnNames[$requiredColumn])) {
            throw new RuntimeException("A coluna obrigatória {$requiredColumn} não existe na tabela ibo antiga.");
        }
    }

    $target = getDB();
    $target->exec('PRAGMA foreign_keys = ON');
    $target->exec('PRAGMA busy_timeout = 30000');

    $targetTables = $target->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ibo', $targetTables, true)) {
        throw new RuntimeException('A tabela ibo não existe no banco existente do painel. Nenhuma tabela será criada.');
    }

    $targetColumns = $target->query('PRAGMA table_info("ibo")')->fetchAll();
    $targetColumnNames = array_fill_keys(array_map(
        static fn (array $column): string => strtolower((string) $column['name']),
        $targetColumns
    ), true);
    foreach (['mac_address', 'key', 'title', 'url', 'type'] as $requiredColumn) {
        if (!isset($targetColumnNames[$requiredColumn])) {
            throw new RuntimeException("A coluna obrigatória {$requiredColumn} não existe na tabela ibo do painel.");
        }
    }

    $rows = $source->query('SELECT * FROM ibo ORDER BY id')->fetchAll();
    $insert = $target->prepare('
        INSERT INTO ibo (
            mac_address, "key", username, password, expire_date, dns, epg_url,
            title, url, type, playlistpassword, playlist_protected, id_user,
            store_type, active, created_at
        )
        SELECT
            :mac_address, :device_key, :username, :password, :expire_date, :dns, :epg_url,
            :title, :url, :type, :playlistpassword, :playlist_protected, NULL,
            :store_type, :active, :created_at
        WHERE NOT EXISTS (
            SELECT 1 FROM ibo
            WHERE upper(replace(replace(mac_address, \':\', \'\'), \'-\', \'\')) = :compact_mac
        )
    ');

    foreach ($rows as $row) {
        $sourceRow = [];
        foreach ($row as $column => $value) {
            $sourceRow[strtolower((string) $column)] = $value;
        }

        $rawMac = trim((string) ($sourceRow['mac_address'] ?? ''));
        $compactMac = strtoupper((string) preg_replace('/[:-]/', '', $rawMac));
        if (!preg_match('/^[0-9A-F]{12}$/', $compactMac)) {
            $skipped++;
            continue;
        }
        $mac = implode(':', str_split($compactMac, 2));
        $currentMac = $mac;
        $key = trim((string) ($sourceRow['key'] ?? ''));
        if ($key === '') {
            $skipped++;
            continue;
        }

        $type = (string) ($sourceRow['type'] ?? '1');
        if (!in_array($type, ['0', '1'], true)) {
            $skipped++;
            continue;
        }

        $parameters = [
            ':mac_address' => $mac,
            ':device_key' => $key,
            ':username' => $sourceRow['username'] ?? null,
            ':password' => $sourceRow['password'] ?? null,
            ':expire_date' => $sourceRow['expire_date'] ?? null,
            ':dns' => $sourceRow['dns'] ?? null,
            ':epg_url' => $sourceRow['epg_url'] ?? null,
            ':title' => trim((string) ($sourceRow['title'] ?? '')) ?: 'NEW DEVICE',
            ':url' => $sourceRow['url'] ?? null,
            ':type' => $type,
            ':playlistpassword' => $sourceRow['playlistpassword'] ?? null,
            ':playlist_protected' => (int) ($sourceRow['playlist_protected'] ?? 1) === 0 ? 0 : 1,
            ':store_type' => in_array((int) ($sourceRow['store_type'] ?? 1), [1, 2], true)
                ? (int) ($sourceRow['store_type'] ?? 1)
                : 1,
            ':active' => (int) ($sourceRow['active'] ?? 1) === 0 ? 0 : 1,
            ':created_at' => trim((string) ($sourceRow['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            ':compact_mac' => $compactMac,
        ];

        for ($attempt = 1; ; $attempt++) {
            try {
                $insert->execute($parameters);
                if ($insert->rowCount() === 1) {
                    $inserted++;
                } else {
                    $duplicates++;
                }
                break;
            } catch (Throwable $exception) {
                if (!isSqliteBusy($exception) || $attempt >= 5) {
                    throw $exception;
                }
                usleep($attempt * 500000);
            }
        }
    }

    importOutput(sprintf(
        "Importação concluída. Inseridos: %d; já existentes: %d; ignorados por dados inválidos: %d.",
        $inserted,
        $duplicates,
        $skipped
    ));
} catch (Throwable $exception) {
    http_response_code(500);
    $progress = sprintf(
        'Inseridos antes da falha: %d; já existentes: %d; inválidos: %d.',
        $inserted,
        $duplicates,
        $skipped
    );
    $failedMac = $currentMac !== '' ? " MAC onde parou: {$currentMac}." : '';
    importOutput("Importação interrompida. {$progress}{$failedMac} Motivo: {$exception->getMessage()}", true);
    exit(1);
}
