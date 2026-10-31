<?php

function saveAccountLogo(array $file, int $userId): string
{
    if ($userId < 1 || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Selecione uma imagem válida para a logo.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || $fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('A imagem precisa ter no máximo 2 MB.');
    }

    $imageData = file_get_contents($temporaryPath);
    if ($imageData === false) {
        throw new RuntimeException('Não foi possível ler a imagem enviada.');
    }
    $imageInfo = @getimagesizefromstring($imageData);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime]) || (int) ($imageInfo[0] ?? 0) > 4096 || (int) ($imageInfo[1] ?? 0) > 4096) {
        throw new InvalidArgumentException('Use uma imagem PNG, JPG ou WebP de até 4096 × 4096 pixels.');
    }

    $directory = dirname(__DIR__, 2) . '/uploads/branding';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento das logos.');
    }
    if (!file_exists($directory . '/.htaccess')
        && file_put_contents($directory . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível proteger o armazenamento das logos.');
    }

    $filename = $userId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (file_put_contents($destination, $imageData, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar a imagem da logo.');
    }

    return '/uploads/branding/' . $filename;
}

function saveAccountBackground(array $file, int $userId): string
{
    if ($userId < 1) {
        throw new InvalidArgumentException('Selecione uma imagem válida para o fundo.');
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'A imagem excede o limite de upload configurado no servidor.',
            UPLOAD_ERR_FORM_SIZE => 'A imagem de fundo precisa ter no máximo 5 MB.',
            UPLOAD_ERR_PARTIAL => 'O envio da imagem foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Selecione uma imagem válida para o fundo.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor não tem uma pasta temporária disponível para o upload.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo enviado.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do servidor interrompeu o envio da imagem.',
        ];
        throw new InvalidArgumentException(
            $uploadErrors[$uploadError] ?? 'Não foi possível receber a imagem de fundo.'
        );
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('A imagem de fundo precisa ter no máximo 5 MB.');
    }

    $imageData = file_get_contents($temporaryPath);
    if ($imageData === false) {
        throw new RuntimeException('Não foi possível ler a imagem de fundo enviada.');
    }
    $imageInfo = @getimagesizefromstring($imageData);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime]) || (int) ($imageInfo[0] ?? 0) > 4096 || (int) ($imageInfo[1] ?? 0) > 4096) {
        throw new InvalidArgumentException('Use uma imagem PNG, JPG ou WebP de até 4096 × 4096 pixels.');
    }

    $directory = dirname(__DIR__, 2) . '/uploads/backgrounds';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento do fundo.');
    }
    if (!file_exists($directory . '/.htaccess')
        && file_put_contents($directory . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível proteger o armazenamento do fundo.');
    }

    $filename = $userId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (file_put_contents($destination, $imageData, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar a imagem de fundo.');
    }

    return '/uploads/backgrounds/' . $filename;
}

function getAccountLogoUrl(PDO $db, ?int $userId): string
{
    $userId = resolveBrandingUserId($db, $userId);
    $logoPath = $userId === null ? '' : getAccountBrandingPath($db, $userId, 'logo');
    if ($logoPath === '') {
        $logoPath = getDefaultApkBrandingPath($db, 'logo');
    }
    return $logoPath === '' ? '' : getPublicBaseUrl() . $logoPath;
}

function getAccountBackgroundUrl(PDO $db, ?int $userId): string
{
    $userId = resolveBrandingUserId($db, $userId);
    $backgroundPath = $userId === null ? '' : getAccountBrandingPath($db, $userId, 'background_image');
    if ($backgroundPath === '') {
        $backgroundPath = getDefaultApkBrandingPath($db, 'background_image');
    }
    return $backgroundPath === '' ? '' : getPublicBaseUrl() . $backgroundPath;
}

function getAccountBannerBackgroundUrl(PDO $db, int $userId): string
{
    $statement = $db->prepare('SELECT banner_background_image FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $backgroundPath = validateApkBrandingPath(
        (string) ($statement->fetchColumn() ?: ''),
        'background_image'
    );
    return $backgroundPath === '' ? '' : getPublicBaseUrl() . $backgroundPath;
}

function getAccountBrandingPath(PDO $db, int $userId, string $field): string
{
    if (!in_array($field, ['logo', 'background_image'], true)) {
        throw new InvalidArgumentException('Campo de identidade inválido.');
    }
    $statement = $db->prepare('SELECT "' . $field . '", COALESCE(admin, 0) AS admin FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $account = $statement->fetch();
    if (!is_array($account) || (int) $account['admin'] === 1) {
        return '';
    }
    return validateApkBrandingPath((string) ($account[$field] ?? ''), $field);
}

function getDefaultApkBrandingPath(PDO $db, string $field): string
{
    $settings = [
        'logo' => 'apk_default_logo',
        'background_image' => 'apk_default_background',
    ];
    if (!isset($settings[$field])) {
        throw new InvalidArgumentException('Campo de identidade padrão inválido.');
    }

    $statement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->execute([$settings[$field]]);
    $configuredPath = $statement->fetchColumn();
    if ($configuredPath !== false) {
        return validateApkBrandingPath((string) $configuredPath, $field);
    }

    $adminStatement = $db->prepare(
        'SELECT "' . $field . '" FROM users WHERE COALESCE(admin, 0) = 1 ORDER BY id ASC LIMIT 1'
    );
    $adminStatement->execute();
    return validateApkBrandingPath((string) ($adminStatement->fetchColumn() ?: ''), $field);
}

function setDefaultApkBrandingPath(PDO $db, string $field, string $path): void
{
    $settings = [
        'logo' => 'apk_default_logo',
        'background_image' => 'apk_default_background',
    ];
    if (!isset($settings[$field])) {
        throw new InvalidArgumentException('Campo de identidade padrão inválido.');
    }

    $validatedPath = validateApkBrandingPath($path, $field);
    $statement = $db->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
    ');
    $statement->execute([$settings[$field], $validatedPath]);
}

function validateApkBrandingPath(string $path, string $field): string
{
    $directory = $field === 'logo' ? 'branding' : 'backgrounds';
    $path = trim($path);
    $pattern = '~^/uploads/' . $directory . '/[A-Za-z0-9._-]+\.(?:png|jpg|webp)$~iD';
    return preg_match($pattern, $path) === 1 ? $path : '';
}

function getAccountBrandingName(PDO $db, ?int $userId): string
{
    if ($userId !== null) {
        $statement = $db->prepare('SELECT branding_name FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $accountName = trim((string) ($statement->fetchColumn() ?: ''));
        if ($accountName !== '') {
            return $accountName;
        }
    }

    $defaultName = getDefaultApkBrandingName($db);
    if ($defaultName !== '') {
        return $defaultName;
    }

    $userId = resolveBrandingUserId($db, $userId);
    if ($userId === null) {
        return '';
    }

    $statement = $db->prepare('SELECT branding_name FROM users WHERE id = ?');
    $statement->execute([$userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function getDefaultApkBrandingName(PDO $db): string
{
    $statement = $db->prepare(
        "SELECT setting_value FROM app_settings WHERE setting_key = 'apk_default_branding_name'"
    );
    $statement->execute();
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function setDefaultApkBrandingName(PDO $db, string $name): void
{
    $name = trim($name);
    if (preg_match('/^.{0,80}$/us', $name) !== 1) {
        throw new InvalidArgumentException('O nome do aplicativo pode ter no máximo 80 caracteres.');
    }

    $statement = $db->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (\'apk_default_branding_name\', ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
    ');
    $statement->execute([$name]);
}

function getAccountHomeTheme(PDO $db, ?int $userId): string
{
    $userId = resolveBrandingUserId($db, $userId);
    if ($userId === null) {
        return getDefaultHomeTheme($db);
    }

    $statement = $db->prepare('SELECT home_theme FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $theme = trim((string) ($statement->fetchColumn() ?: ''));
    return array_key_exists($theme, getHomeThemeOptions()) ? $theme : getDefaultHomeTheme($db);
}

function resolveBrandingUserId(PDO $db, ?int $userId): ?int
{
    if ($userId !== null) {
        return $userId;
    }

    $defaultReseller = $db->prepare("
        SELECT setting_value
        FROM app_settings
        WHERE setting_key = 'apk_default_reseller_id'
    ");
    $defaultReseller->execute();
    $defaultResellerId = trim((string) ($defaultReseller->fetchColumn() ?: ''));
    if ($defaultResellerId === '') {
        return null;
    }

    $userId = filter_var($defaultResellerId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new RuntimeException('A revenda padrão configurada para o APK é inválida.');
    }

    $statement = $db->prepare('SELECT id FROM users WHERE id = ? AND COALESCE(admin, 0) <> 1');
    $statement->execute([$userId]);
    if ($statement->fetchColumn() === false) {
        throw new RuntimeException('A revenda padrão configurada para o APK não existe.');
    }

    return $userId;
}
