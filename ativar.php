<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', '2592000');
    session_set_cookie_params([
        'lifetime' => 2592000,
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/pix.php';
require_once __DIR__ . '/api/includes/branding.php';

if (empty($_SESSION['activation_csrf'])) {
    $_SESSION['activation_csrf'] = bin2hex(random_bytes(32));
}

$db = getDB();
$activationLogoUrl = getPublicBaseUrl() . '/uploads/ativar/1-171f4a61363ca49f26abaab861324226.png';
$loginMac = trim((string) ($_POST['mac'] ?? $_GET['mac'] ?? ''));
$loginKey = trim((string) ($_POST['key'] ?? $_GET['key'] ?? ''));
$error = '';
$paymentPayload = '';
$paymentAmount = 0;
$paymentStatus = '';
$paymentId = filter_var($_GET['payment'] ?? null, FILTER_VALIDATE_INT);
$buildPlaylistUrl = static function (string $type, array $input): ?string {
    if ($type === 'xc') {
        $server = trim((string) ($input['server_url'] ?? ''));
        $username = trim((string) ($input['playlist_username'] ?? ''));
        $password = trim((string) ($input['playlist_password'] ?? ''));
        $parts = filter_var($server, FILTER_VALIDATE_URL) !== false ? parse_url($server) : false;
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || strlen($server) > 2048 || $username === '' || strlen($username) > 255
            || $password === '' || strlen($password) > 255) {
            return null;
        }
        $url = rtrim($server, '/') . '/get.php?' . http_build_query([
            'username' => $username,
            'password' => $password,
            'type' => 'm3u_plus',
            'output' => 'ts',
        ], '', '&', PHP_QUERY_RFC3986);
    } elseif ($type === 'm3u') {
        $url = trim((string) ($input['playlist_url'] ?? ''));
    } else {
        return null;
    }

    $parts = strlen($url) <= 4096 && filter_var($url, FILTER_VALIDATE_URL) !== false
        ? parse_url($url)
        : false;
    return is_array($parts)
        && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        ? $url
        : null;
};
$validPin = static fn(string $pin): bool => preg_match('/^\d{4,12}$/', $pin) === 1;

$sessionDeviceId = filter_var($_SESSION['activation_device_id'] ?? null, FILTER_VALIDATE_INT);
$device = $sessionDeviceId !== false && $sessionDeviceId !== null && $sessionDeviceId > 0
    ? getDeviceById($db, $sessionDeviceId)
    : null;
$authorized = $device !== null;
$deviceKey = $authorized ? (string) ($device['key'] ?? '') : $loginKey;
$macInput = $authorized ? (string) $device['mac_address'] : $loginMac;

if (!$authorized && $_SERVER['REQUEST_METHOD'] === 'GET' && $loginMac !== '' && $loginKey !== '') {
    $linkMac = normalizeDeviceMac($loginMac);
    $linkDevice = $linkMac === null ? null : findDeviceByMac($db, $linkMac);
    if ($linkDevice !== null && hash_equals((string) ($linkDevice['key'] ?? ''), $loginKey)) {
        session_regenerate_id(true);
        $_SESSION['activation_device_id'] = (int) $linkDevice['id'];
        $_SESSION['activation_csrf'] = bin2hex(random_bytes(32));
        header('Location: ativar.php#activation-panel', true, 303);
        exit;
    }
    $error = 'O link de acesso é inválido. Confira o MAC e a chave exibidos no aplicativo.';
}

if (isset($_GET['status']) && $_GET['status'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$authorized || $paymentId === false || $paymentId === null || $paymentId < 1) {
        http_response_code(400);
        echo json_encode(['error' => $authorized ? 'invalid_payment' : 'authentication_required']);
        exit;
    }
    $statusQuery = $db->prepare('
        SELECT status FROM pix_payments
        WHERE id = ? AND mac_address = ?
    ');
    $statusQuery->execute([$paymentId, (string) $device['mac_address']]);
    $statusRow = $statusQuery->fetch();
    if ($statusRow === false) {
        http_response_code(404);
        echo json_encode(['error' => 'payment_not_found']);
        exit;
    }
    $freshDevice = getDeviceById($db, (int) $device['id']);
    echo json_encode([
        'status' => (string) $statusRow['status'],
        'expire_date' => (string) ($freshDevice['expire_date'] ?? ''),
        'active' => (bool) ($freshDevice['active'] ?? false),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['activation_csrf'], $token)) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'unlock_playlist') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        if (!$authorized) {
            http_response_code(401);
            echo json_encode(['error' => 'Entre na sua conta antes de continuar.']);
            exit;
        }
        $device = getDeviceById($db, (int) $device['id']);
        if ($device === null || (int) ($device['active'] ?? 0) !== 1) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Este dispositivo está bloqueado pelo administrador ou pela revenda.',
                'code' => 'device_blocked',
                'status' => 'blocked',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $scope = (string) ($_POST['playlist_scope'] ?? '');
        $currentPin = (string) ($_POST['current_pin'] ?? '');
        if ($scope === 'main') {
            $isProtected = (int) ($device['playlist_protected'] ?? 1) === 1;
            $expectedPin = (string) ($device['playlistpassword'] ?? '');
            if ($expectedPin === '') {
                $expectedPin = '0000';
            }
            if ($isProtected && !hash_equals($expectedPin, $currentPin)) {
                http_response_code(403);
                echo json_encode(['error' => 'PIN incorreto.']);
                exit;
            }
            echo json_encode([
                'name' => (string) ($device['title'] ?? ''),
                'url' => (string) ($device['url'] ?? ''),
                'type' => (string) ($device['type'] ?? '') === '0' ? 'xc' : 'm3u',
                'server' => (string) ($device['dns'] ?? ''),
                'username' => (string) ($device['username'] ?? ''),
                'password' => (string) ($device['password'] ?? ''),
                'protected' => $isProtected ? '1' : '0',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }

        $playlistRowId = filter_var($_POST['playlist_id'] ?? null, FILTER_VALIDATE_INT);
        if ($scope !== 'custom' || $playlistRowId === false || $playlistRowId === null || $playlistRowId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Playlist inválida.']);
            exit;
        }
        $findPlaylist = $db->prepare('
            SELECT name, url, type, is_protected, pin
            FROM playlist
            WHERE id = ? AND mac_address = ?
        ');
        $findPlaylist->execute([$playlistRowId, (string) $device['mac_address']]);
        $customPlaylist = $findPlaylist->fetch();
        if ($customPlaylist === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Playlist não encontrada nesta conta.']);
            exit;
        }
        if ((int) $customPlaylist['is_protected'] === 1
            && !hash_equals((string) $customPlaylist['pin'], $currentPin)) {
            http_response_code(403);
            echo json_encode(['error' => 'PIN incorreto.']);
            exit;
        }
        $server = '';
        $username = '';
        $password = '';
        if ((string) $customPlaylist['type'] === 'xc') {
            $parts = parse_url((string) $customPlaylist['url']);
            $query = [];
            if (is_array($parts)) {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $server = (string) ($parts['scheme'] ?? '') . '://' . (string) ($parts['host'] ?? '');
                if (isset($parts['port'])) {
                    $server .= ':' . (int) $parts['port'];
                }
                $server .= preg_replace('~/get\.php$~i', '', (string) ($parts['path'] ?? ''));
            }
            $username = (string) ($query['username'] ?? '');
            $password = (string) ($query['password'] ?? '');
        }
        echo json_encode([
            'name' => (string) $customPlaylist['name'],
            'url' => (string) $customPlaylist['url'],
            'type' => (string) $customPlaylist['type'],
            'server' => $server,
            'username' => $username,
            'password' => $password,
            'protected' => (int) $customPlaylist['is_protected'] === 1 ? '1' : '0',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    } elseif (($_POST['action'] ?? '') === 'identify') {
        $loginMacNormalized = normalizeDeviceMac($loginMac);
        $loginDevice = $loginMacNormalized === null ? null : findDeviceByMac($db, $loginMacNormalized);
        if ($loginDevice === null || $loginKey === ''
            || !hash_equals((string) ($loginDevice['key'] ?? ''), $loginKey)) {
            http_response_code(403);
            $error = 'MAC ou chave incorretos. Confira os dados exibidos no aplicativo.';
        } else {
            session_regenerate_id(true);
            $_SESSION['activation_device_id'] = (int) $loginDevice['id'];
            $_SESSION['activation_csrf'] = bin2hex(random_bytes(32));
            header('Location: ativar.php#activation-panel', true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset($_SESSION['activation_device_id']);
        $_SESSION['activation_csrf'] = bin2hex(random_bytes(32));
        session_regenerate_id(true);
        header('Location: ativar.php', true, 303);
        exit;
    } elseif (!$authorized) {
        http_response_code(401);
        $error = 'Entre na sua conta antes de continuar.';
    } elseif ((int) ($device['active'] ?? 0) !== 1) {
        http_response_code(403);
        $error = 'Este dispositivo está bloqueado. Entre em contato com o vendedor.';
    } elseif (($_POST['action'] ?? '') === 'save_playlist') {
        $playlistName = trim((string) ($_POST['playlist_name'] ?? ''));
        $playlistType = (string) ($_POST['playlist_type'] ?? 'm3u');
        $playlistUrl = $buildPlaylistUrl($playlistType, $_POST);
        $wasProtected = (int) ($device['playlist_protected'] ?? 1) === 1;
        $oldPin = (string) ($device['playlistpassword'] ?? '');
        if ($oldPin === '') {
            $oldPin = '0000';
        }
        $currentPin = (string) ($_POST['current_pin'] ?? '');
        $protectPlaylist = isset($_POST['protect_playlist']);
        $newPin = trim((string) ($_POST['new_pin'] ?? ''));
        $confirmPin = trim((string) ($_POST['confirm_pin'] ?? ''));
        if ($playlistName === '' || strlen($playlistName) > 120) {
            $error = 'Informe um nome para a playlist (até 120 caracteres).';
        } elseif ($playlistUrl === null) {
            $error = $playlistType === 'xc'
                ? 'Informe um servidor HTTP ou HTTPS, usuário e senha Xtream válidos.'
                : 'Informe uma URL HTTP ou HTTPS válida para a playlist.';
        } elseif ($wasProtected && !hash_equals($oldPin, $currentPin)) {
            $error = 'Informe o PIN atual para alterar esta playlist protegida.';
        } elseif ($protectPlaylist && $newPin !== '' && (!$validPin($newPin) || !hash_equals($newPin, $confirmPin))) {
            $error = 'O PIN deve ter de 4 a 12 números e a confirmação precisa ser igual.';
        } elseif ($protectPlaylist && $newPin === '' && !$wasProtected) {
            $error = 'Informe e confirme um PIN de 4 a 12 números.';
        } else {
            $savedPin = $protectPlaylist
                ? ($newPin !== '' ? $newPin : $oldPin)
                : '';
            $savePlaylist = $db->prepare('
                UPDATE ibo
                SET title = ?, url = ?, type = ?, dns = ?, username = ?, password = ?,
                    playlistpassword = ?, playlist_protected = ?
                WHERE id = ?
            ');
            $savePlaylist->execute([
                $playlistName,
                $playlistUrl,
                $playlistType === 'xc' ? '0' : '1',
                $playlistType === 'xc' ? trim((string) $_POST['server_url']) : '',
                $playlistType === 'xc' ? trim((string) $_POST['playlist_username']) : '',
                $playlistType === 'xc' ? trim((string) $_POST['playlist_password']) : '',
                $savedPin,
                $protectPlaylist ? 1 : 0,
                (int) $device['id'],
            ]);
            $redirectQuery = [];
            if ($paymentId !== false && $paymentId !== null && $paymentId > 0) {
                $redirectQuery['payment'] = $paymentId;
            }
            $queryString = http_build_query($redirectQuery, '', '&', PHP_QUERY_RFC3986);
            $redirect = 'ativar.php' . ($queryString !== '' ? '?' . $queryString : '');
            header('Location: ' . $redirect . ($queryString !== '' ? '&' : '?') . 'playlist_saved=1#activation-panel', true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'save_custom_playlist') {
        $playlistName = trim((string) ($_POST['playlist_name'] ?? ''));
        $playlistType = (string) ($_POST['playlist_type'] ?? 'm3u');
        $playlistUrl = $buildPlaylistUrl($playlistType, $_POST);
        $playlistRowId = filter_var($_POST['playlist_id'] ?? null, FILTER_VALIDATE_INT);
        $existingPlaylist = null;
        if ($playlistRowId !== false && $playlistRowId !== null && $playlistRowId > 0) {
            $findPlaylist = $db->prepare('SELECT id, is_protected, pin FROM playlist WHERE id = ? AND mac_address = ?');
            $findPlaylist->execute([$playlistRowId, (string) $device['mac_address']]);
            $existingPlaylist = $findPlaylist->fetch() ?: null;
        }
        $isEditing = $playlistRowId !== false && $playlistRowId !== null && $playlistRowId > 0;
        $wasProtected = $existingPlaylist !== null && (int) $existingPlaylist['is_protected'] === 1;
        $currentPin = (string) ($_POST['current_pin'] ?? '');
        $protectPlaylist = isset($_POST['protect_playlist']);
        $newPin = trim((string) ($_POST['new_pin'] ?? ''));
        $confirmPin = trim((string) ($_POST['confirm_pin'] ?? ''));

        if ($isEditing && $existingPlaylist === null) {
            $error = 'Playlist não encontrada nesta conta.';
        } elseif ($playlistName === '' || strlen($playlistName) > 120) {
            $error = 'Informe um nome para a playlist (até 120 caracteres).';
        } elseif ($playlistUrl === null) {
            $error = $playlistType === 'xc'
                ? 'Informe um servidor HTTP ou HTTPS, usuário e senha Xtream válidos.'
                : 'Informe uma URL HTTP ou HTTPS válida para a playlist.';
        } elseif ($wasProtected && !hash_equals((string) $existingPlaylist['pin'], $currentPin)) {
            $error = 'Informe o PIN atual para alterar esta playlist protegida.';
        } elseif ($protectPlaylist && $newPin !== '' && (!$validPin($newPin) || !hash_equals($newPin, $confirmPin))) {
            $error = 'O PIN deve ter de 4 a 12 números e a confirmação precisa ser igual.';
        } elseif ($protectPlaylist && $newPin === '' && !$wasProtected) {
            $error = 'Informe e confirme um PIN de 4 a 12 números.';
        } else {
            $savedPin = $protectPlaylist
                ? ($newPin !== '' ? $newPin : (string) $existingPlaylist['pin'])
                : '';
            if ($isEditing) {
                $savePlaylist = $db->prepare('
                    UPDATE playlist SET name = ?, url = ?, type = ?, is_protected = ?, pin = ?
                    WHERE id = ? AND mac_address = ?
                ');
                $savePlaylist->execute([
                    $playlistName,
                    $playlistUrl,
                    $playlistType,
                    $protectPlaylist ? 1 : 0,
                    $savedPin,
                    $playlistRowId,
                    (string) $device['mac_address'],
                ]);
            } else {
                $savePlaylist = $db->prepare('
                    INSERT INTO playlist (mac_address, name, url, type, is_protected, pin)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $savePlaylist->execute([
                    (string) $device['mac_address'],
                    $playlistName,
                    $playlistUrl,
                    $playlistType,
                    $protectPlaylist ? 1 : 0,
                    $savedPin,
                ]);
            }
            header('Location: ativar.php?playlist_saved=1#activation-panel', true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'delete_custom_playlist') {
        $playlistRowId = filter_var($_POST['playlist_id'] ?? null, FILTER_VALIDATE_INT);
        $findPlaylist = $db->prepare('SELECT id, is_protected, pin FROM playlist WHERE id = ? AND mac_address = ?');
        $findPlaylist->execute([$playlistRowId, (string) $device['mac_address']]);
        $playlist = $findPlaylist->fetch();
        if ($playlistRowId === false || $playlistRowId === null || $playlistRowId < 1 || $playlist === false) {
            $error = 'Playlist não encontrada nesta conta.';
        } elseif ((int) $playlist['is_protected'] === 1
            && !hash_equals((string) $playlist['pin'], (string) ($_POST['current_pin'] ?? ''))) {
            $error = 'Informe o PIN atual para excluir esta playlist protegida.';
        } else {
            $deletePlaylist = $db->prepare('DELETE FROM playlist WHERE id = ? AND mac_address = ?');
            $deletePlaylist->execute([$playlistRowId, (string) $device['mac_address']]);
            header('Location: ativar.php?playlist_saved=1#activation-panel', true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'delete_primary_playlist') {
        $primaryPin = (string) ($device['playlistpassword'] ?? '');
        if ($primaryPin === '') {
            $primaryPin = '0000';
        }
        if ((int) ($device['playlist_protected'] ?? 1) === 1
            && !hash_equals($primaryPin, (string) ($_POST['current_pin'] ?? ''))) {
            $error = 'Informe o PIN atual para excluir esta playlist protegida.';
        } else {
            $clearPlaylist = $db->prepare("
                UPDATE ibo
                SET title = 'Playlist', url = '', type = '1', dns = '', username = '', password = '',
                    playlistpassword = '', playlist_protected = 0
                WHERE id = ?
            ");
            $clearPlaylist->execute([(int) $device['id']]);
            header('Location: ativar.php?playlist_saved=1#activation-panel', true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'create_payment') {
        $config = $db->query('SELECT * FROM pix_config WHERE id = 1')->fetch();
        if ($config === false || (string) $config['pix_key'] === ''
            || (int) $config['amount_cents'] <= 0) {
            $error = 'O pagamento Pix ainda não está configurado. Entre em contato com o vendedor.';
        } else {
            try {
                $db->beginTransaction();
                $findPayment = $db->prepare("
                    SELECT * FROM pix_payments
                    WHERE mac_address = ? AND amount_cents = ? AND status = 'pending'
                    ORDER BY id DESC LIMIT 1
                ");
                $findPayment->execute([
                    (string) $device['mac_address'],
                    (int) $config['amount_cents'],
                ]);
                $payment = $findPayment->fetch();
                if ($payment === false) {
                    $txid = strtoupper(bin2hex(random_bytes(12)));
                    $insertPayment = $db->prepare('
                        INSERT INTO pix_payments (
                            txid, mac_address, amount_cents, duration_days, pix_key, merchant_name, merchant_city
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $insertPayment->execute([
                        $txid,
                        (string) $device['mac_address'],
                        (int) $config['amount_cents'],
                        pixSubscriptionDurationDays(),
                        (string) $config['pix_key'],
                        (string) $config['merchant_name'],
                        (string) $config['merchant_city'],
                    ]);
                    $paymentId = (int) $db->lastInsertId();
                } else {
                    $paymentId = (int) $payment['id'];
                    $normalizePayment = $db->prepare('UPDATE pix_payments SET duration_days = ? WHERE id = ?');
                    $normalizePayment->execute([pixSubscriptionDurationDays(), $paymentId]);
                }
                $db->commit();
                $query = http_build_query(['payment' => $paymentId], '', '&', PHP_QUERY_RFC3986);
                header('Location: ativar.php?' . $query . '#activation-panel', true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Activation payment request failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Não foi possível preparar o pagamento. Tente novamente ou fale com o vendedor.';
            }
        }
    } else {
        http_response_code(400);
        $error = 'Ação inválida.';
    }
} elseif ($authorized && $paymentId !== null) {
    if ($paymentId !== false && $paymentId !== null && $paymentId > 0) {
        $query = $db->prepare("
            SELECT payments.*,
                   COALESCE(NULLIF(payments.pix_key, ''), config.pix_key) AS pix_key,
                   COALESCE(NULLIF(payments.merchant_name, ''), config.merchant_name) AS merchant_name,
                   COALESCE(NULLIF(payments.merchant_city, ''), config.merchant_city) AS merchant_city
            FROM pix_payments AS payments
            JOIN pix_config AS config ON config.id = 1
            WHERE payments.id = ? AND payments.mac_address = ?
        ");
        $query->execute([$paymentId, (string) $device['mac_address']]);
        $payment = $query->fetch();
        if ($payment !== false && in_array($payment['status'], ['pending', 'approved', 'rejected'], true)) {
            $paymentAmount = (int) $payment['amount_cents'];
            $paymentStatus = (string) $payment['status'];
            if ($paymentStatus === 'pending') {
                $paymentPayload = generatePixPayload(
                    (string) $payment['pix_key'],
                    (string) $payment['merchant_name'],
                    (string) $payment['merchant_city'],
                    $paymentAmount,
                    (string) $payment['txid']
                );
            }
        }
    }
}

$expiration = is_array($device) ? (string) ($device['expire_date'] ?? '') : '';
$playlistName = is_array($device) ? (string) ($device['title'] ?? '') : '';
$playlistUrl = is_array($device) ? (string) ($device['url'] ?? '') : '';
$playlistType = is_array($device) && (string) ($device['type'] ?? '') === '0' ? 'xc' : 'm3u';
$playlistServerUrl = is_array($device) ? (string) ($device['dns'] ?? '') : '';
$playlistUsername = is_array($device) ? (string) ($device['username'] ?? '') : '';
$playlistPassword = is_array($device) ? (string) ($device['password'] ?? '') : '';
$playlistSaved = ($_GET['playlist_saved'] ?? '') === '1';
$customPlaylists = [];
$activationConfig = $db->query('SELECT amount_cents FROM pix_config WHERE id = 1')->fetch();
if ($authorized) {
    $customPlaylistQuery = $db->prepare('
        SELECT id, name, url, type, is_protected
        FROM playlist
        WHERE mac_address = ?
        ORDER BY id ASC
    ');
    $customPlaylistQuery->execute([(string) $device['mac_address']]);
    $customPlaylists = $customPlaylistQuery->fetchAll();
}
$accountStatus = !$authorized
    ? ''
    : ((int) ($device['active'] ?? 0) !== 1
        ? 'Bloqueada'
        : (isDeviceExpired($expiration) ? 'Expirada' : 'Ativa'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $playlistName = trim((string) ($_POST['playlist_name'] ?? $playlistName));
    $playlistUrl = trim((string) ($_POST['playlist_url'] ?? $playlistUrl));
    $playlistType = (string) ($_POST['playlist_type'] ?? $playlistType);
    $playlistServerUrl = trim((string) ($_POST['server_url'] ?? $playlistServerUrl));
    $playlistUsername = trim((string) ($_POST['playlist_username'] ?? $playlistUsername));
    $playlistPassword = trim((string) ($_POST['playlist_password'] ?? $playlistPassword));
}
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101828">
    <meta name="description" content="Gerencie sua conta, playlist e ativação do KSGPLAY.">
    <title>Conta e ativação | KSGPLAY</title>
    <style>
        :root { color-scheme: light; --ink: #101828; --muted: #667085; --panel: #fff; --line: #eaecf0; --pink: #3157d5; --orange: #101828; --blue: #3157d5; --navy: #101828; --canvas: #f7f8fa; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; color: var(--ink); background: var(--canvas); font: 16px "Segoe UI", Arial, sans-serif; }
        a { color: inherit; text-decoration: none; }
        .site-header { display: flex; width: 100%; min-height: 82px; margin: 0; padding: 0 max(20px, calc((100% - 1200px) / 2)); align-items: center; justify-content: space-between; border-bottom: 1px solid #344054; background: var(--navy); color: #fff; }
        .brand { display: inline-flex; min-width: 0; align-items: center; gap: 12px; color: #fff; font-size: 19px; font-weight: 800; letter-spacing: .02em; }
        .brand-logo { display: block; width: 138px; height: 68px; flex: 0 0 138px; object-fit: contain; }
        .brand-mark { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 11px; background: var(--blue); font-size: 15px; }
        .nav { display: flex; align-items: center; gap: 16px; color: #d0d5dd; font-size: 14px; }
        .nav a:hover { color: #fff; }
        .nav .account-link { padding: 0; border: 0; border-radius: 0; background: transparent; color: #d0d5dd; }
        .logout-form { display: inline-flex; margin: 0; }
        .logout-button { padding: 10px 15px; border: 1px solid #475467; border-radius: 8px; background: transparent; color: #f2f4f7; font-size: 14px; }
        .logout-button:hover { background: #ffffff12; color: #fff; }
        main { width: min(1280px, calc(100% - 40px)); margin: 0 auto; padding: 28px 0 46px; }
        .hero { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(315px, .8fr); gap: 42px; align-items: start; }
        .hero.has-account { grid-template-columns: minmax(0, 1fr); gap: 24px; }
        .hero-copy { padding-top: 18px; }
        .hero.has-account .hero-copy { padding-top: 0; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid #d0d5dd; border-radius: 999px; background: #fff; color: #475467; font-size: 13px; }
        .eyebrow::before { width: 8px; height: 8px; border-radius: 50%; background: #3157d5; content: ""; }
        h1 { max-width: 660px; margin: 18px 0 12px; font-size: clamp(42px, 4.7vw, 72px); line-height: .96; letter-spacing: -.06em; }
        .gradient-text { color: var(--blue); background: none; -webkit-text-fill-color: currentColor; }
        .lead { max-width: 620px; margin: 0; color: var(--muted); font-size: 18px; line-height: 1.55; }
        .feature-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
        .feature { padding: 10px 13px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: #475467; font-size: 13px; }
        .feature span { margin-right: 7px; color: var(--blue); }
        .panel { padding: 22px 22px 18px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        .panel-head { margin-bottom: 14px; }
        .panel-head h2 { margin: 0 0 8px; font-size: 23px; }
        .panel-head p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
        label { display: block; margin: 12px 0 6px; color: #344054; font-size: 14px; font-weight: 600; }
        input, textarea { width: 100%; padding: 12px 13px; border: 1px solid #d0d5dd; border-radius: 8px; outline: none; background: #fff; color: #101828; font: inherit; }
        input:focus, textarea:focus { border-color: #8098f9; box-shadow: 0 0 0 3px #dbe5ff; }
        input::placeholder { color: #98a2b3; }
        button { color: #fff; cursor: pointer; font: inherit; font-weight: 700; }
        .submit { width: 100%; margin-top: 16px; padding: 12px 16px; border: 0; border-radius: 8px; background: var(--blue); box-shadow: none; }
        .submit:hover { background: #2646b2; }
        .renew-form { margin-top: 18px; }
        .renew-help { margin: 10px 0 0; color: var(--muted); font-size: 13px; }
        .notice, .error, .success { margin: 14px 0; padding: 13px 15px; border: 1px solid transparent; border-radius: 9px; line-height: 1.5; }
        .notice { border-color: #b2ccff; background: #eff4ff; color: #1849a9; }
        .error { border-color: #fecdca; background: #fef3f2; color: #b42318; }
        .success { border-color: #abefc6; background: #ecfdf3; color: #067647; }
        .account-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 11px; margin: 24px 0 0; text-align: left; }
        .summary-item { min-width: 0; padding: 13px; border: 1px solid var(--line); border-radius: 10px; background: #fcfcfd; }
        .summary-item span, .summary-item strong { display: block; overflow-wrap: anywhere; }
        .summary-item span { margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .summary-item strong { font-size: 14px; }
        .summary-item .status-active { color: #067647; }
        .summary-item .status-expired, .summary-item .status-blocked { color: #b42318; }
        .account-tabs { display: flex; gap: 10px; margin: 0 0 20px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
        .account-tab, .secondary-button, .row-button { padding: 10px 16px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; color: #344054; }
        .account-tab.is-active { border-color: var(--blue); background: var(--blue); color: #fff; }
        .playlist-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .playlist-table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 11px; }
        .playlist-table { width: 100%; border-collapse: collapse; text-align: left; }
        .playlist-table th, .playlist-table td { padding: 13px 15px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        .playlist-table th { background: #f2f4f7; color: #344054; font-weight: 700; }
        .playlist-table tr:last-child td { border-bottom: 0; }
        .playlist-url { display: block; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); }
        .playlist-protected { color: #b54708; }
        .row-actions { display: flex; justify-content: flex-end; gap: 7px; }
        .row-button { padding: 7px 11px; background: var(--blue); color: #fff; }
        .row-button.danger { background: #b42318; }
        .playlist-form { margin: 0 0 18px; padding: 18px; border: 1px solid var(--line); border-radius: 11px; background: #fcfcfd; }
        .playlist-form[hidden], [hidden] { display: none !important; }
        .playlist-form h3 { margin: 0 0 14px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
        .form-grid .wide { grid-column: 1 / -1; }
        .checkbox-row { display: flex; align-items: center; gap: 9px; }
        .checkbox-row input { width: 18px; height: 18px; margin: 0; padding: 0; border: 0; background: transparent; accent-color: var(--blue); }
        .pin-help { margin: 7px 0 0; color: var(--muted); font-size: 13px; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 9px; }
        .form-actions .submit { width: auto; min-width: 140px; }
        .secondary-button { margin-top: 21px; }
        .activate-copy { padding: 17px; border: 1px solid #b2ccff; border-radius: 10px; background: #eff4ff; color: #1849a9; line-height: 1.65; }
        .activation-status { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 11px; margin: 0 0 18px; }
        .activation-status-item { min-width: 0; padding: 13px; border: 1px solid var(--line); border-radius: 10px; background: #fcfcfd; }
        .activation-status-item span, .activation-status-item strong { display: block; overflow-wrap: anywhere; }
        .activation-status-item span { margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .activation-status-item strong { font-size: 14px; }
        .activation-status-item .status-active { color: #067647; }
        .activation-status-item .status-expired, .activation-status-item .status-blocked { color: #b42318; }
        .activate-price { margin: 16px 0; color: var(--muted); }
        .activate-price strong { color: var(--ink); font-size: 20px; }
        .pin-backdrop { position: fixed; z-index: 20; inset: 0; display: grid; padding: 20px; place-items: center; background: #080510d9; }
        .pin-dialog { width: min(100%, 480px); padding: 24px; border: 1px solid var(--line); border-radius: 12px; background: #fff; color: var(--ink); box-shadow: 0 20px 48px rgba(16, 24, 40, .2); }
        .pin-dialog h2 { margin: 0 0 8px; font-size: 22px; }
        .pin-dialog p { margin: 0 0 12px; color: var(--muted); line-height: 1.5; }
        .pin-dialog .error { color: #b42318; }
        .pin-dialog-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 15px; }
        .pin-dialog-actions button { padding: 10px 14px; border: 0; border-radius: 7px; }
        .pin-dialog-cancel { background: #667085; }
        .pin-dialog-submit { background: var(--blue); }
        .payment { text-align: center; }
        .payment h2 { margin-top: 0; }
        .amount { margin: 14px 0; font-size: 29px; font-weight: 800; }
        .amount small { color: var(--muted); font-size: 14px; font-weight: 500; }
        #pix-qr { display: grid; min-height: 280px; margin: 19px 0; place-items: center; border-radius: 12px; background: #fff; }
        #pix-qr img, #pix-qr canvas { max-width: 100%; height: auto; }
        #pix-copy { width: 100%; margin: 8px 0; padding: 12px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; color: #344054; }
        #pix-code { min-height: 110px; resize: vertical; }
        .page-footer { width: min(1160px, calc(100% - 40px)); margin: 0 auto; padding: 20px 0 30px; border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; text-align: center; }
        @media (max-width: 780px) {
            .hero { grid-template-columns: 1fr; gap: 28px; }
            main { padding-top: 37px; }
            .hero-copy { text-align: center; }
            .lead { margin: 0 auto; }
            .feature-row { justify-content: center; }
        }
        @media (max-width: 520px) {
            .activation-status { grid-template-columns: 1fr; }
        }
        @media (max-width: 520px) {
            .site-header { min-height: 74px; padding-right: 14px; padding-left: 14px; }
            .brand-logo { width: 118px; height: 58px; flex-basis: 118px; }
            main, .page-footer { width: min(100% - 28px, 1160px); }
            .brand { font-size: 16px; }
            .nav { gap: 10px; }
            .nav a:first-child { display: none; }
            .nav .account-link { padding: 0; font-size: 13px; }
            .logout-button { padding: 8px 11px; font-size: 13px; }
            main { padding-top: 30px; }
            .panel { padding: 20px; border-radius: 16px; }
            .account-summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <a class="brand" href="ativar.php" aria-label="KSGPLAY - minha conta">
        <?php if ($activationLogoUrl !== ''): ?>
            <img class="brand-logo" src="<?= $escape($activationLogoUrl) ?>" alt="Logo KSGPLAY">
        <?php else: ?>
            <span class="brand-mark" aria-hidden="true">K</span>
        <?php endif; ?>
    </a>
    <nav class="nav" aria-label="Navegação principal">
        <a href="#activation-panel"<?= $authorized ? ' data-activation-link' : '' ?>><?= $authorized ? 'Ativação' : 'Entrar' ?></a>
        <?php if ($authorized): ?>
            <a class="account-link" href="#account-summary">Minha conta</a>
            <form method="post" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="logout-button">Sair</button>
            </form>
        <?php else: ?>
            <a class="account-link" href="#activation-panel">Minha conta</a>
        <?php endif; ?>
    </nav>
</header>
<main>
    <section class="hero<?= $authorized ? ' has-account' : '' ?>">
        <div class="hero-copy">
            <span class="eyebrow"><?= $authorized ? 'Dispositivo identificado' : 'Gerencie sua conta' ?></span>
            <h1><?= $authorized ? 'Sua conta, ' : 'Gerencie suas ' ?><span class="gradient-text"><?= $authorized ? 'sempre atualizada.' : 'playlists.' ?></span></h1>
            <p class="lead"><?= $authorized
                ? 'Consulte os dados da conta, configure sua playlist e acompanhe a ativação do seu dispositivo em um só lugar.'
                : 'Acesse sua conta com os dados exibidos no aplicativo. Cadastre sua playlist e acompanhe sua assinatura com segurança.' ?></p>
            <div class="feature-row" aria-label="Recursos">
                <span class="feature"><span>✓</span>Ativação simples</span>
                <span class="feature"><span>✓</span>Pagamento Pix</span>
                <span class="feature"><span>✓</span>Status atualizado</span>
            </div>
            <?php if ($authorized): ?>
                <section class="account-summary" id="account-summary" aria-label="Resumo da conta">
                    <div class="summary-item"><span>Endereço MAC</span><strong><?= $escape((string) $device['mac_address']) ?></strong></div>
                    <div class="summary-item"><span>Status</span><strong class="<?= $accountStatus === 'Ativa' ? 'status-active' : 'status-expired' ?>"><?= $escape($accountStatus) ?></strong></div>
                    <div class="summary-item"><span>Validade</span><strong><?= $escape($expiration !== '' ? $expiration : 'Não informado') ?></strong></div>
                    <div class="summary-item"><span>Chave do APK</span><strong><?= $escape($deviceKey !== '' ? $deviceKey : 'Não informada') ?></strong></div>
                </section>
            <?php endif; ?>
        </div>
        <section class="panel" id="activation-panel">
            <div class="panel-head">
                <h2><?= $authorized ? 'Sua conta' : 'Entrar na sua conta' ?></h2>
                <p><?= $authorized ? 'Gerencie suas playlists ou consulte as opções de ativação.' : 'Informe o MAC e a chave que aparecem na tela do aplicativo.' ?></p>
            </div>
        <?php if (!$authorized): ?>
            <?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                <input type="hidden" name="action" value="identify">
                <label for="device-mac">Endereço MAC</label>
                <input id="device-mac" name="mac" maxlength="17" placeholder="00:11:22:33:44:55" value="<?= $escape($macInput) ?>" autocomplete="off" autocapitalize="characters" required>
                <label for="device-key">Chave do dispositivo</label>
                <input id="device-key" name="key" maxlength="255" value="<?= $escape($deviceKey) ?>" autocomplete="off" required>
                <button class="submit" type="submit">Acessar minha conta</button>
            </form>
        <?php else: ?>
            <?php if ($playlistSaved): ?><p class="success">Playlist atualizada com sucesso.</p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif; ?>
            <div class="account-tabs" role="tablist" aria-label="Gerenciamento da conta">
                <button class="account-tab is-active" id="playlist-tab" type="button" role="tab" aria-selected="true" aria-controls="playlist-view">Playlist</button>
                <button class="account-tab" id="activation-tab" type="button" role="tab" aria-selected="false" aria-controls="activation-view">Ativar</button>
            </div>
            <section id="playlist-view" role="tabpanel" aria-labelledby="playlist-tab">
                <div class="playlist-actions">
                    <button class="secondary-button" type="button" data-add-playlist="m3u">Adicionar playlist</button>
                    <button class="secondary-button" type="button" data-add-playlist="xc">Adicionar XC playlist</button>
                </div>
                <?php
                $formPostAction = (string) ($_POST['action'] ?? '');
                $formOpen = $_SERVER['REQUEST_METHOD'] === 'POST'
                    && in_array($formPostAction, ['save_playlist', 'save_custom_playlist'], true);
                $formScope = $formPostAction === 'save_playlist' ? 'main' : 'custom';
                $formRequiresCurrentPin = $formOpen && $formScope === 'main'
                    && (int) ($device['playlist_protected'] ?? 1) === 1;
                if ($formOpen && $formScope === 'custom') {
                    $postedPlaylistId = filter_var($_POST['playlist_id'] ?? null, FILTER_VALIDATE_INT);
                    foreach ($customPlaylists as $customPlaylist) {
                        if ($postedPlaylistId !== false && $postedPlaylistId !== null
                            && (int) $customPlaylist['id'] === $postedPlaylistId
                            && (int) $customPlaylist['is_protected'] === 1) {
                            $formRequiresCurrentPin = true;
                            break;
                        }
                    }
                }
                $mainPlaylistConfigured = $playlistUrl !== '' || $playlistServerUrl !== '';
                ?>
                <form method="post" id="playlist-form" class="playlist-form"<?= $formOpen ? '' : ' hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                    <input type="hidden" name="action" id="playlist-action" value="<?= $formScope === 'main' ? 'save_playlist' : 'save_custom_playlist' ?>">
                    <input type="hidden" name="playlist_id" id="playlist-id" value="<?= $escape((string) ($_POST['playlist_id'] ?? '')) ?>">
                    <input type="hidden" name="playlist_type" id="playlist-type" value="<?= $escape((string) ($_POST['playlist_type'] ?? $playlistType)) ?>">
                    <h3 id="playlist-form-title"><?= $formOpen ? 'Editar playlist' : 'Adicionar playlist' ?></h3>
                    <div class="form-grid">
                        <label class="wide">Nome da playlist
                            <input id="playlist-name" name="playlist_name" maxlength="120" value="<?= $escape($formOpen ? (string) ($_POST['playlist_name'] ?? '') : '') ?>" required>
                        </label>
                        <label class="m3u-field wide">URL da playlist M3U
                            <input id="playlist-url" name="playlist_url" type="url" maxlength="4096" inputmode="url" value="<?= $escape($formOpen ? (string) ($_POST['playlist_url'] ?? '') : '') ?>" placeholder="https://servidor/playlist.m3u">
                        </label>
                        <label class="xc-field wide" hidden>Servidor Xtream
                            <input id="server-url" name="server_url" type="url" maxlength="2048" value="<?= $escape($formOpen ? (string) ($_POST['server_url'] ?? '') : '') ?>" placeholder="https://servidor">
                        </label>
                        <label class="xc-field" hidden>Usuário Xtream
                            <input id="playlist-username" name="playlist_username" maxlength="255" value="<?= $escape($formOpen ? (string) ($_POST['playlist_username'] ?? '') : '') ?>">
                        </label>
                        <label class="xc-field" hidden>Senha Xtream
                            <input id="playlist-password" name="playlist_password" maxlength="255" value="<?= $escape($formOpen ? (string) ($_POST['playlist_password'] ?? '') : '') ?>">
                        </label>
                        <label class="wide checkbox-row">
                            <input id="protect-playlist" type="checkbox" name="protect_playlist" value="1" <?= $formOpen && isset($_POST['protect_playlist']) ? 'checked' : '' ?>>
                            <span>Proteger esta playlist com PIN</span>
                        </label>
                        <label id="current-pin-field" class="wide"<?= $formRequiresCurrentPin ? '' : ' hidden' ?>>PIN atual
                            <input id="current-pin" name="current_pin" type="password" inputmode="numeric" autocomplete="current-password"<?= $formRequiresCurrentPin ? ' required' : '' ?>>
                        </label>
                        <label class="pin-field">PIN (4 a 12 números)
                            <input id="new-pin" name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{4,12}" maxlength="12" autocomplete="new-password">
                        </label>
                        <label class="pin-field">Confirmar PIN
                            <input id="confirm-pin" name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{4,12}" maxlength="12" autocomplete="new-password">
                        </label>
                    </div>
                    <p class="pin-help">Playlists protegidas exigem o PIN para serem alteradas ou removidas.</p>
                    <div class="form-actions">
                        <button class="submit" type="submit">Salvar playlist</button>
                        <button class="secondary-button" type="button" id="cancel-playlist-form">Cancelar</button>
                    </div>
                </form>
                <div class="playlist-table-wrap">
                    <table class="playlist-table">
                        <thead><tr><th>Nome</th><th>URL</th><th>Ações</th></tr></thead>
                        <tbody>
                        <?php if ($mainPlaylistConfigured): ?>
                            <?php $primaryProtected = (int) ($device['playlist_protected'] ?? 1) === 1; ?>
                            <tr>
                                <td><?= $escape($playlistName !== '' ? $playlistName : 'Minha Playlist') ?></td>
                                <td><span class="playlist-url<?= $primaryProtected ? ' playlist-protected' : '' ?>"><?= $primaryProtected ? 'Playlist protegida' : $escape($playlistUrl) ?></span></td>
                                <td><div class="row-actions">
                                    <button class="row-button" type="button" data-edit-playlist="main"
                                        data-name="<?= $escape($playlistName) ?>"
                                        data-type="<?= $playlistType ?>"
                                        data-protected="<?= $primaryProtected ? '1' : '0' ?>"
                                        <?php if (!$primaryProtected): ?>
                                            data-url="<?= $escape($playlistUrl) ?>"
                                            data-server="<?= $escape($playlistServerUrl) ?>"
                                            data-username="<?= $escape($playlistUsername) ?>"
                                            data-password="<?= $escape($playlistPassword) ?>"
                                        <?php endif; ?>>Editar</button>
                                    <form method="post" class="delete-playlist-form" data-protected="<?= $primaryProtected ? '1' : '0' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                                        <input type="hidden" name="action" value="delete_primary_playlist">
                                        <input type="hidden" name="current_pin">
                                        <button class="row-button danger" type="submit">Excluir</button>
                                    </form>
                                </div></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($customPlaylists as $customPlaylist): ?>
                            <?php $customProtected = (int) $customPlaylist['is_protected'] === 1; ?>
                            <tr>
                                <td><?= $escape((string) $customPlaylist['name']) ?></td>
                                <td><span class="playlist-url<?= $customProtected ? ' playlist-protected' : '' ?>"><?= $customProtected ? 'Playlist protegida' : $escape((string) $customPlaylist['url']) ?></span></td>
                                <td><div class="row-actions">
                                    <button class="row-button" type="button" data-edit-playlist="custom"
                                        data-id="<?= (int) $customPlaylist['id'] ?>"
                                        data-name="<?= $escape((string) $customPlaylist['name']) ?>"
                                        data-type="<?= $escape((string) $customPlaylist['type']) ?>"
                                        data-protected="<?= $customProtected ? '1' : '0' ?>"
                                        <?= !$customProtected ? 'data-url="' . $escape((string) $customPlaylist['url']) . '"' : '' ?>>Editar</button>
                                    <form method="post" class="delete-playlist-form" data-protected="<?= $customProtected ? '1' : '0' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                                        <input type="hidden" name="action" value="delete_custom_playlist">
                                        <input type="hidden" name="playlist_id" value="<?= (int) $customPlaylist['id'] ?>">
                                        <input type="hidden" name="current_pin">
                                        <button class="row-button danger" type="submit">Excluir</button>
                                    </form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$mainPlaylistConfigured && $customPlaylists === []): ?>
                            <tr><td colspan="3">Nenhuma playlist cadastrada. Use uma das opções acima para adicionar.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section id="activation-view" role="tabpanel" aria-labelledby="activation-tab" hidden>
                <h3>Ativação e status do APK</h3>
                <div class="activation-status" aria-label="Status da ativação">
                    <div class="activation-status-item">
                        <span>Dispositivo / MAC</span>
                        <strong><?= $escape((string) $device['mac_address']) ?></strong>
                    </div>
                    <div class="activation-status-item">
                        <span>Status do APK</span>
                        <strong class="<?= $accountStatus === 'Ativa' ? 'status-active' : ($accountStatus === 'Expirada' ? 'status-expired' : 'status-blocked') ?>"><?= $escape($accountStatus) ?></strong>
                    </div>
                    <div class="activation-status-item">
                        <span>Validade</span>
                        <strong><?= $escape($expiration !== '' ? $expiration : 'Não informado') ?></strong>
                    </div>
                    <div class="activation-status-item">
                        <span>Chave do APK</span>
                        <strong><?= $escape($deviceKey !== '' ? $deviceKey : 'Não informada') ?></strong>
                    </div>
                </div>
                <p class="activate-copy">O KSGPLAY não fornece conteúdo. Para usar o aplicativo, adicione sua própria playlist. O pagamento é vinculado a este dispositivo.</p>
                <p class="activate-price">Preço configurado: <strong><?= (int) ($activationConfig['amount_cents'] ?? 0) > 0 ? 'R$ ' . number_format((int) $activationConfig['amount_cents'] / 100, 2, ',', '.') : 'Consulte o vendedor' ?></strong> <span>· 12 meses · Pix</span></p>
                <?php if ($paymentStatus === 'approved'): ?>
                    <div class="payment" id="payment-result" data-payment-status="approved">
                        <h2>Pagamento aprovado</h2>
                        <p class="success">Pagamento confirmado pelo vendedor. Seu dispositivo está ativo.</p>
                        <p>Vencimento atualizado: <strong id="approved-expiration"><?= $escape($expiration !== '' ? $expiration : 'Não informado') ?></strong></p>
                        <p>Feche e abra novamente o aplicativo para carregar a assinatura atualizada.</p>
                    </div>
                <?php elseif ($paymentStatus === 'rejected'): ?>
                    <div class="payment">
                        <h2>Pagamento não confirmado</h2>
                        <p class="error">O pedido foi marcado como não pago. Confira o pagamento com o vendedor antes de tentar novamente.</p>
                    </div>
                <?php elseif ($paymentStatus === 'pending' && $paymentPayload !== ''): ?>
                    <div class="payment">
                        <h2>Pagamento Pix</h2>
                        <p class="amount">R$ <?= number_format($paymentAmount / 100, 2, ',', '.') ?> <small>· 12 meses</small></p>
                        <p class="notice" id="payment-waiting">Após pagar, aguarde a conferência manual do vendedor. Esta página verificará a aprovação automaticamente.</p>
                        <div id="pix-qr" aria-label="QR Code Pix"></div>
                        <label for="pix-code">Pix copia e cola</label>
                        <textarea id="pix-code" rows="4" readonly><?= $escape($paymentPayload) ?></textarea>
                        <button id="pix-copy" type="button">Copiar código Pix</button>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['activation_csrf']) ?>">
                    <input type="hidden" name="action" value="create_payment">
                    <p class="help">O pagamento Pix é conferido manualmente pelo vendedor. A aprovação libera 12 meses de assinatura, e esta página atualiza o status automaticamente.</p>
                    <button class="submit" type="submit"><?= $paymentStatus === 'pending' ? 'Ver pagamento Pix' : 'Ativar ou renovar por 12 meses' ?></button>
                </form>
            </section>
        <?php endif; ?>
        </section>
    </section>
</main>
<footer class="page-footer">KSGPLAY · Gerencie sua conta e suas playlists com facilidade.</footer>
<?php if ($authorized): ?>
<div class="pin-backdrop" id="pin-backdrop" hidden>
    <section class="pin-dialog" role="dialog" aria-modal="true" aria-labelledby="pin-dialog-title" aria-describedby="pin-dialog-message">
        <h2 id="pin-dialog-title">Confirmar ação</h2>
        <p id="pin-dialog-message"></p>
        <p class="error" id="pin-dialog-error" role="alert" hidden></p>
        <form id="pin-confirmation-form">
            <label id="dialog-pin-field" hidden>PIN
                <input id="dialog-pin" type="password" inputmode="numeric" pattern="[0-9]{4,12}" maxlength="12" autocomplete="current-password">
            </label>
            <div class="pin-dialog-actions">
                <button class="pin-dialog-cancel" type="button" id="cancel-pin-dialog">Cancelar</button>
                <button class="pin-dialog-submit" type="submit" id="confirm-pin-dialog">Continuar</button>
            </div>
        </form>
    </section>
</div>
<script>
    (function () {
        const playlistTab = document.getElementById('playlist-tab');
        const activationTab = document.getElementById('activation-tab');
        const playlistView = document.getElementById('playlist-view');
        const activationView = document.getElementById('activation-view');
        const playlistForm = document.getElementById('playlist-form');
        const formTitle = document.getElementById('playlist-form-title');
        const formAction = document.getElementById('playlist-action');
        const playlistId = document.getElementById('playlist-id');
        const playlistType = document.getElementById('playlist-type');
        const nameInput = document.getElementById('playlist-name');
        const urlInput = document.getElementById('playlist-url');
        const serverInput = document.getElementById('server-url');
        const usernameInput = document.getElementById('playlist-username');
        const passwordInput = document.getElementById('playlist-password');
        const protectInput = document.getElementById('protect-playlist');
        const currentPinField = document.getElementById('current-pin-field');
        const currentPinInput = document.getElementById('current-pin');
        const newPinInput = document.getElementById('new-pin');
        const confirmPinInput = document.getElementById('confirm-pin');
        const pinBackdrop = document.getElementById('pin-backdrop');
        const pinForm = document.getElementById('pin-confirmation-form');
        const dialogPinField = document.getElementById('dialog-pin-field');
        const dialogPin = document.getElementById('dialog-pin');
        const dialogMessage = document.getElementById('pin-dialog-message');
        const dialogError = document.getElementById('pin-dialog-error');
        const confirmDialogButton = document.getElementById('confirm-pin-dialog');
        let pendingPinAction = null;
        let unlockedPin = '';

        function selectTab(tab) {
            const showActivation = tab === 'activation';
            playlistView.hidden = showActivation;
            activationView.hidden = !showActivation;
            playlistTab.classList.toggle('is-active', !showActivation);
            activationTab.classList.toggle('is-active', showActivation);
            playlistTab.setAttribute('aria-selected', String(!showActivation));
            activationTab.setAttribute('aria-selected', String(showActivation));
        }

        function updatePlaylistFields() {
            const isXtream = playlistType.value === 'xc';
            document.querySelectorAll('.m3u-field').forEach((field) => { field.hidden = isXtream; });
            document.querySelectorAll('.xc-field').forEach((field) => { field.hidden = !isXtream; });
            urlInput.required = !isXtream;
            serverInput.required = isXtream;
            usernameInput.required = isXtream;
            passwordInput.required = isXtream;
        }

        function updateProtectionFields(isProtected) {
            currentPinField.hidden = !isProtected;
            currentPinInput.required = isProtected;
            document.querySelectorAll('.pin-field').forEach((field) => { field.hidden = !protectInput.checked; });
            newPinInput.required = protectInput.checked && !isProtected;
            confirmPinInput.required = protectInput.checked && !isProtected;
        }

        function openPlaylistForm(scope, values) {
            const isMain = scope === 'main';
            const isProtected = values.protected === '1';
            formTitle.textContent = values.id ? 'Editar playlist' : (isMain ? 'Configurar playlist do dispositivo' : 'Adicionar playlist');
            formAction.value = isMain ? 'save_playlist' : 'save_custom_playlist';
            playlistId.value = values.id || '';
            playlistType.value = values.type || 'm3u';
            nameInput.value = values.name || '';
            urlInput.value = values.url || '';
            serverInput.value = values.server || '';
            usernameInput.value = values.username || '';
            passwordInput.value = values.password || '';
            protectInput.checked = isProtected;
            currentPinInput.value = values.currentPin || '';
            newPinInput.value = '';
            confirmPinInput.value = '';
            currentPinField.hidden = !isProtected;
            updateProtectionFields(isProtected);
            updatePlaylistFields();
            playlistForm.hidden = false;
            playlistForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
            nameInput.focus();
        }

        playlistTab.addEventListener('click', () => selectTab('playlist'));
        activationTab.addEventListener('click', () => selectTab('activation'));
        document.querySelectorAll('[data-playlist-link]').forEach((link) => {
            link.addEventListener('click', () => selectTab('playlist'));
        });
        document.querySelectorAll('[data-activation-link]').forEach((link) => {
            link.addEventListener('click', () => selectTab('activation'));
        });
        document.querySelectorAll('[data-add-playlist]').forEach((button) => {
            button.addEventListener('click', () => openPlaylistForm('custom', {
                type: button.dataset.addPlaylist,
                protected: '0'
            }));
        });
        function openUnlockedPlaylist(button, values) {
            if (values.type === 'xc' && !values.server) {
                try {
                    const playlistUrl = new URL(values.url);
                    values.server = playlistUrl.origin + playlistUrl.pathname.replace(/\/get\.php$/i, '');
                    values.username = playlistUrl.searchParams.get('username') || '';
                    values.password = playlistUrl.searchParams.get('password') || '';
                } catch (error) {
                    values.server = '';
                }
            }
            openPlaylistForm(button.dataset.editPlaylist, values);
        }

        function openPinDialog(message, requiresPin, onConfirm) {
            pendingPinAction = onConfirm;
            dialogMessage.textContent = message;
            dialogError.hidden = true;
            dialogError.textContent = '';
            dialogPin.value = '';
            dialogPinField.hidden = !requiresPin;
            dialogPin.required = requiresPin;
            confirmDialogButton.disabled = false;
            confirmDialogButton.textContent = 'Continuar';
            pinBackdrop.hidden = false;
            if (requiresPin) dialogPin.focus();
            else confirmDialogButton.focus();
        }

        function closePinDialog() {
            pinBackdrop.hidden = true;
            pendingPinAction = null;
        }

        playlistTab.addEventListener('click', () => selectTab('playlist'));
        activationTab.addEventListener('click', () => selectTab('activation'));
        document.querySelectorAll('[data-add-playlist]').forEach((button) => {
            button.addEventListener('click', () => openPlaylistForm('custom', {
                type: button.dataset.addPlaylist,
                protected: '0'
            }));
        });
        document.querySelectorAll('[data-edit-playlist]').forEach((button) => {
            button.addEventListener('click', () => {
                const values = {
                    id: button.dataset.id || '',
                    name: button.dataset.name || '',
                    url: button.dataset.url || '',
                    type: button.dataset.type || 'm3u',
                    server: button.dataset.server || '',
                    username: button.dataset.username || '',
                    password: button.dataset.password || '',
                    protected: button.dataset.protected || '0'
                };
                if (values.protected !== '1') {
                    openUnlockedPlaylist(button, values);
                    return;
                }
                openPinDialog('Informe o PIN para abrir esta playlist protegida.', true, async (pin) => {
                    const request = new URLSearchParams({
                        csrf_token: <?= json_encode((string) $_SESSION['activation_csrf']) ?>,
                        action: 'unlock_playlist',
                        playlist_scope: button.dataset.editPlaylist,
                        playlist_id: values.id,
                        current_pin: pin
                    });
                    try {
                        const response = await fetch('ativar.php', {
                            method: 'POST',
                            body: request,
                            cache: 'no-store',
                            headers: { Accept: 'application/json' }
                        });
                        const result = await response.json();
                        if (!response.ok) throw new Error(result.error || 'Não foi possível abrir a playlist.');
                        openUnlockedPlaylist(button, {
                            id: values.id,
                            name: result.name,
                            url: result.url,
                            type: result.type,
                            server: result.server,
                            username: result.username,
                            password: result.password,
                            protected: result.protected,
                            currentPin: ''
                        });
                        currentPinInput.value = pin;
                        unlockedPin = pin;
                        closePinDialog();
                    } catch (error) {
                        dialogError.textContent = error.message || 'Não foi possível abrir a playlist.';
                        dialogError.hidden = false;
                        dialogPin.focus();
                        confirmDialogButton.disabled = false;
                    }
                });
            });
        });
        protectInput.addEventListener('change', () => updateProtectionFields(currentPinField.hidden ? '0' : '1'));
        playlistType.addEventListener('change', updatePlaylistFields);
        document.getElementById('cancel-playlist-form').addEventListener('click', () => {
            playlistForm.hidden = true;
        });
        document.querySelectorAll('.delete-playlist-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const protectedPlaylist = form.dataset.protected === '1';
                openPinDialog(
                    protectedPlaylist
                        ? 'Informe o PIN atual para excluir esta playlist.'
                        : 'Confirme que deseja excluir esta playlist.',
                    protectedPlaylist,
                    async (pin) => {
                        form.querySelector('[name="current_pin"]').value = pin;
                        closePinDialog();
                        form.submit();
                    }
                );
            });
        });
        document.getElementById('cancel-pin-dialog').addEventListener('click', closePinDialog);
        pinBackdrop.addEventListener('click', (event) => {
            if (event.target === pinBackdrop) closePinDialog();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !pinBackdrop.hidden) closePinDialog();
        });
        pinForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!pendingPinAction) return;
            confirmDialogButton.disabled = true;
            confirmDialogButton.textContent = 'Verificando...';
            await pendingPinAction(dialogPinField.hidden ? '' : dialogPin.value);
        });

        <?php if ($formOpen): ?>
        playlistForm.hidden = false;
        updateProtectionFields(currentPinField.hidden ? '0' : '1');
        <?php endif; ?>
        updatePlaylistFields();
        <?php if ($formRequiresCurrentPin && $formOpen && isset($_POST['current_pin'])): ?>
        currentPinInput.value = <?= json_encode((string) $_POST['current_pin']) ?>;
        <?php endif; ?>
        <?php if ($paymentId !== null && $paymentId !== false): ?>
        selectTab('activation');
        <?php endif; ?>
    })();
</script>
<?php endif; ?>
<?php if ($paymentPayload !== '' && $paymentStatus === 'pending'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const target = document.getElementById('pix-qr');
        const button = document.getElementById('pix-copy');
        const code = document.getElementById('pix-code');
        if (typeof window.QRCode === 'function') {
            new window.QRCode(target, { text: code.value, width: 256, height: 256, correctLevel: window.QRCode.CorrectLevel.M });
        } else {
            target.textContent = 'Não foi possível carregar o QR Code. Use o Pix copia e cola abaixo.';
        }
        button.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(code.value);
                button.textContent = 'Código copiado';
            } catch (error) {
                code.focus();
                code.select();
                button.textContent = 'Selecione e copie o código';
            }
        });

        const statusUrl = new URL(window.location.href);
        statusUrl.searchParams.set('status', '1');
        let attempts = 0;
        const pollPayment = async function () {
            if (attempts >= 720) {
                return;
            }
            attempts += 1;
            try {
                const response = await fetch(statusUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error('Não foi possível verificar o pagamento.');
                }
                const result = await response.json();
                if (result.status === 'approved' && result.active) {
                    window.location.reload();
                    return;
                }
                if (result.status === 'rejected') {
                    window.location.reload();
                    return;
                }
            } catch (error) {
                const waiting = document.getElementById('payment-waiting');
                if (waiting) waiting.textContent = 'Aguardando confirmação do pagamento. Esta página tentará atualizar novamente.';
            }
            window.setTimeout(pollPayment, 5000);
        };
        window.setTimeout(pollPayment, 5000);
    });
</script>
<?php endif; ?>
</body>
</html>
