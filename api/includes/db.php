<?php

function getDB(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $directory = __DIR__ . '/../db';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar a pasta do banco de dados.');
    }

    $dbPath = $directory . '/.ansdb.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    ensureDatabaseSchema($db);
    return $db;
}

function ensureDatabaseSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT '',
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            admin INTEGER NOT NULL DEFAULT 0,
            store_type INTEGER NOT NULL DEFAULT 1,
            mac_amount INTEGER NOT NULL DEFAULT 0,
            parent_user_id INTEGER,
            referred_by_user_id INTEGER,
            referral_code TEXT,
            bonus_mac_amount INTEGER NOT NULL DEFAULT 0,
            referral_points INTEGER NOT NULL DEFAULT 0,
            referral_cash_cents INTEGER NOT NULL DEFAULT 0,
            referral_pix_key TEXT NOT NULL DEFAULT '',
            corp TEXT,
            logo TEXT,
            background_image TEXT,
            banner_background_image TEXT,
            branding_name TEXT NOT NULL DEFAULT '',
            home_theme TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS ibo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mac_address TEXT UNIQUE NOT NULL,
            \"key\" TEXT NOT NULL DEFAULT '',
            username TEXT,
            password TEXT,
            expire_date TEXT,
            dns TEXT,
            epg_url TEXT,
            title TEXT DEFAULT 'NEW DEVICE',
            url TEXT,
            type TEXT,
            playlistpassword TEXT,
            playlist_protected INTEGER NOT NULL DEFAULT 1,
            id_user INTEGER,
            store_type INTEGER DEFAULT 1,
            active INTEGER DEFAULT 1,
            created_at TEXT
        );

        CREATE TABLE IF NOT EXISTS playlist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mac_address TEXT NOT NULL,
            url TEXT NOT NULL,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'm3u',
            is_protected INTEGER NOT NULL DEFAULT 0,
            pin TEXT NOT NULL DEFAULT '',
            FOREIGN KEY(mac_address) REFERENCES ibo(mac_address) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS theme (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS apks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cod TEXT,
            name TEXT,
            url TEXT,
            image TEXT,
            \"desc\" TEXT,
            vers TEXT,
            date TEXT,
            downloadercode NUMERIC
        );

        CREATE TABLE IF NOT EXISTS qrcode (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            qrcode TEXT
        );

        CREATE TABLE IF NOT EXISTS catch (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device TEXT,
            date TEXT
        );

        CREATE TABLE IF NOT EXISTS adstatus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            adstype TEXT
        );

        CREATE TABLE IF NOT EXISTS sports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            header_n TEXT,
            border_c TEXT,
            background_c TEXT,
            text_c TEXT,
            days TEXT,
            api TEXT
        );

        CREATE TABLE IF NOT EXISTS sportconfira (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(25),
            url VARCHAR(200),
            c1 VARCHAR(25),
            c2 VARCHAR(25),
            c3 VARCHAR(25)
        );

        CREATE TABLE IF NOT EXISTS ads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            url TEXT,
            owner_user_id INTEGER
        );

        CREATE TABLE IF NOT EXISTS intro (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT
        );

        CREATE TABLE IF NOT EXISTS portals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            url TEXT
        );

        CREATE TABLE IF NOT EXISTS pix_config (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            pix_key TEXT NOT NULL DEFAULT '',
            merchant_name TEXT NOT NULL DEFAULT '',
            merchant_city TEXT NOT NULL DEFAULT '',
            amount_cents INTEGER NOT NULL DEFAULT 0,
            reseller_credit_amount_cents INTEGER NOT NULL DEFAULT 0,
            duration_days INTEGER NOT NULL DEFAULT 30,
            updated_at TEXT
        );

        CREATE TABLE IF NOT EXISTS pix_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            txid TEXT NOT NULL UNIQUE,
            mac_address TEXT NOT NULL,
            amount_cents INTEGER NOT NULL,
            duration_days INTEGER NOT NULL,
            pix_key TEXT NOT NULL DEFAULT '',
            merchant_name TEXT NOT NULL DEFAULT '',
            merchant_city TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at TEXT,
            processed_by INTEGER
        );

        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS reseller_credit_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            referrer_user_id INTEGER,
            txid TEXT NOT NULL UNIQUE,
            amount_cents INTEGER NOT NULL,
            pix_payload TEXT NOT NULL,
            pix_key TEXT NOT NULL,
            merchant_name TEXT NOT NULL,
            merchant_city TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            referral_validated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at TEXT,
            processed_by INTEGER
        );

        CREATE TABLE IF NOT EXISTS referral_rewards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            credit_order_id INTEGER NOT NULL UNIQUE,
            referrer_user_id INTEGER NOT NULL,
            purchaser_user_id INTEGER NOT NULL,
            bonus_mac_amount INTEGER NOT NULL DEFAULT 10,
            points_amount INTEGER NOT NULL DEFAULT 10,
            cash_amount_cents INTEGER NOT NULL DEFAULT 2000,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS referral_withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            wallet_type TEXT NOT NULL,
            points_amount INTEGER NOT NULL DEFAULT 0,
            cash_amount_cents INTEGER NOT NULL DEFAULT 0,
            pix_key TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at TEXT,
            processed_by INTEGER
        );
    ");

    $requiredColumns = [
        'ibo' => [
            'mac_address' => 'TEXT',
            'key' => "TEXT NOT NULL DEFAULT ''",
            'username' => 'TEXT',
            'password' => 'TEXT',
            'expire_date' => 'TEXT',
            'dns' => 'TEXT',
            'epg_url' => 'TEXT',
            'title' => "TEXT DEFAULT 'NEW DEVICE'",
            'url' => 'TEXT',
            'type' => 'TEXT',
            'playlistpassword' => 'TEXT',
            'playlist_protected' => 'INTEGER NOT NULL DEFAULT 1',
            'id_user' => 'INTEGER',
            'store_type' => 'INTEGER DEFAULT 1',
            'active' => 'INTEGER DEFAULT 1',
            'created_at' => 'TEXT',
        ],
        'playlist' => [
            'type' => "TEXT NOT NULL DEFAULT 'm3u'",
            'is_protected' => 'INTEGER NOT NULL DEFAULT 0',
            'pin' => "TEXT NOT NULL DEFAULT ''",
        ],
        'users' => [
            'name' => "TEXT NOT NULL DEFAULT ''",
            'username' => 'TEXT',
            'password' => "TEXT NOT NULL DEFAULT ''",
            'admin' => 'INTEGER DEFAULT 0',
            'store_type' => 'INTEGER DEFAULT 1',
            'mac_amount' => 'INTEGER DEFAULT 0',
            'parent_user_id' => 'INTEGER',
            'referred_by_user_id' => 'INTEGER',
            'referral_code' => 'TEXT',
            'bonus_mac_amount' => 'INTEGER NOT NULL DEFAULT 0',
            'referral_points' => 'INTEGER NOT NULL DEFAULT 0',
            'referral_cash_cents' => 'INTEGER NOT NULL DEFAULT 0',
            'referral_pix_key' => "TEXT NOT NULL DEFAULT ''",
            'corp' => 'TEXT',
            'logo' => 'TEXT',
            'background_image' => 'TEXT',
            'banner_background_image' => 'TEXT',
            'branding_name' => "TEXT NOT NULL DEFAULT ''",
            'home_theme' => "TEXT NOT NULL DEFAULT ''",
        ],
        'qrcode' => [
            'qrcode' => 'TEXT',
        ],
        'sports' => [
            'header_n' => 'TEXT',
            'border_c' => 'TEXT',
            'background_c' => 'TEXT',
            'text_c' => 'TEXT',
            'days' => 'TEXT',
            'api' => 'TEXT',
        ],
        'sportconfira' => [
            'title' => 'VARCHAR(25)',
            'url' => 'VARCHAR(200)',
            'c1' => 'VARCHAR(25)',
            'c2' => 'VARCHAR(25)',
            'c3' => 'VARCHAR(25)',
        ],
        'ads' => [
            'title' => 'TEXT',
            'url' => 'TEXT',
            'owner_user_id' => 'INTEGER',
        ],
        'portals' => [
            'name' => 'TEXT',
            'url' => 'TEXT',
        ],
        'pix_payments' => [
            'pix_key' => "TEXT NOT NULL DEFAULT ''",
            'merchant_name' => "TEXT NOT NULL DEFAULT ''",
            'merchant_city' => "TEXT NOT NULL DEFAULT ''",
            'processed_at' => 'TEXT',
            'processed_by' => 'INTEGER',
        ],
        'pix_config' => [
            'reseller_credit_amount_cents' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'reseller_credit_orders' => [
            'referral_validated' => 'INTEGER NOT NULL DEFAULT 0',
        ],
    ];

    foreach ($requiredColumns as $table => $columns) {
        $existing = $db->query('PRAGMA table_info("' . $table . '")')->fetchAll();
        $existingNames = array_map('strtolower', array_column($existing, 'name'));
        foreach ($columns as $column => $definition) {
            if (!in_array(strtolower($column), $existingNames, true)) {
                $db->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $column . '" ' . $definition);
            }
        }
    }

    $paymentForeignKeys = $db->query('PRAGMA foreign_key_list("pix_payments")')->fetchAll();
    if ($paymentForeignKeys !== []) {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $db->exec('ALTER TABLE pix_payments RENAME TO pix_payments_legacy_fk');
            $db->exec("
                CREATE TABLE pix_payments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    txid TEXT NOT NULL UNIQUE,
                    mac_address TEXT NOT NULL,
                    amount_cents INTEGER NOT NULL,
                    duration_days INTEGER NOT NULL,
                    pix_key TEXT NOT NULL DEFAULT '',
                    merchant_name TEXT NOT NULL DEFAULT '',
                    merchant_city TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT 'pending',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    processed_at TEXT,
                    processed_by INTEGER
                )
            ");
            $legacyColumns = $db->query('PRAGMA table_info("pix_payments_legacy_fk")')->fetchAll(PDO::FETCH_COLUMN, 1);
            $copyFields = [
                'id' => 'id',
                'txid' => 'txid',
                'mac_address' => 'mac_address',
                'amount_cents' => 'amount_cents',
                'duration_days' => 'duration_days',
                'pix_key' => 'pix_key',
                'merchant_name' => 'merchant_name',
                'merchant_city' => 'merchant_city',
                'status' => 'status',
                'created_at' => 'created_at',
                'processed_at' => in_array('approved_at', $legacyColumns, true)
                    ? 'COALESCE("processed_at", "approved_at")'
                    : '"processed_at"',
                'processed_by' => in_array('approved_by', $legacyColumns, true)
                    ? 'COALESCE("processed_by", "approved_by")'
                    : '"processed_by"',
            ];
            $insertFields = [];
            $selectFields = [];
            foreach ($copyFields as $target => $source) {
                $fallbackColumn = $target === 'processed_at' ? 'processed_at'
                    : ($target === 'processed_by' ? 'processed_by' : $target);
                if (in_array($fallbackColumn, $legacyColumns, true)) {
                    $insertFields[] = '"' . $target . '"';
                    $selectFields[] = $source;
                }
            }
            $db->exec('INSERT INTO pix_payments (' . implode(', ', $insertFields) . ')
                SELECT ' . implode(', ', $selectFields) . ' FROM pix_payments_legacy_fk');
            $db->exec('DROP TABLE pix_payments_legacy_fk');
            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_ibo_active ON ibo(active)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ibo_expire_date ON ibo(expire_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_pix_payments_mac_status ON pix_payments(mac_address, status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_parent_user_id ON users(parent_user_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_credit_orders_user_status ON reseller_credit_orders(user_id, status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_referral_withdrawals_user_status ON referral_withdrawals(user_id, status)');

    $missingReferralCodes = $db->query("
        SELECT id FROM users
        WHERE COALESCE(admin, 0) <> 1
          AND (referral_code IS NULL OR trim(referral_code) = '')
    ")->fetchAll(PDO::FETCH_COLUMN);
    $saveReferralCode = $db->prepare('UPDATE users SET referral_code = ? WHERE id = ?');
    foreach ($missingReferralCodes as $userId) {
        $saveReferralCode->execute([createReferralCode($db), (int) $userId]);
    }
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code)');

    $sportConfigCount = (int) $db->query('SELECT COUNT(*) FROM sportconfira')->fetchColumn();
    if ($sportConfigCount === 0) {
        $stmt = $db->prepare('
            INSERT INTO sportconfira (title, url, c1, c2, c3)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            'Default',
            'https://www.tvsportguide.com/widget/5cc316f797659/?heading=Events&border_color=custom&autoscroll=1&custom_colors=',
            '#1d23dd',
            '#ffffff',
            '#000000',
        ]);
    }

    $introConfigCount = (int) $db->query('SELECT COUNT(*) FROM intro')->fetchColumn();
    if ($introConfigCount === 0) {
        $db->exec("INSERT INTO intro (id, url) VALUES (1, '')");
    }
}

function verifyUserPassword(PDO $db, array $user, string $password): bool
{
    $storedPassword = (string) ($user['password'] ?? '');
    if (password_verify($password, $storedPassword)) {
        return true;
    }

    if (password_get_info($storedPassword)['algoName'] !== 'unknown' || !hash_equals($storedPassword, $password)) {
        return false;
    }

    $upgrade = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');
    $upgrade->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id'], $storedPassword]);
    return true;
}

function getDevices(PDO $db): array
{
    return $db->query('SELECT * FROM ibo ORDER BY id DESC')->fetchAll();
}

function getDeviceById(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibo WHERE id = ?');
    $stmt->execute([$id]);
    $device = $stmt->fetch();
    return $device === false ? null : $device;
}

function saveDevice(PDO $db, array $device, ?int $id = null): void
{
    $values = [
        $device['mac_address'],
        $device['key'],
        $device['username'],
        $device['password'],
        $device['expire_date'],
        $device['dns'],
        $device['epg_url'],
        $device['title'],
        $device['url'],
        $device['type'],
        $device['playlistpassword'],
        $device['playlist_protected'],
        $device['id_user'],
        $device['store_type'],
        $device['active'],
    ];

    if ($id === null) {
        $stmt = $db->prepare('
            INSERT INTO ibo (
                mac_address, "key", username, password, expire_date, dns, epg_url,
                title, url, type, playlistpassword, playlist_protected, id_user, store_type, active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        $stmt->execute($values);
        return;
    }

    $values[] = $id;
    $stmt = $db->prepare('
        UPDATE ibo
        SET mac_address = ?, "key" = ?, username = ?, password = ?, expire_date = ?,
            dns = ?, epg_url = ?, title = ?, url = ?, type = ?, playlistpassword = ?,
            playlist_protected = ?, id_user = ?, store_type = ?, active = ?
        WHERE id = ?
    ');
    $stmt->execute($values);
}

function getManagedUsers(PDO $db): array
{
    return $db->query('
        SELECT users.id, users.name, users.username, users.store_type, users.mac_amount,
               users.parent_user_id, users.referral_code, users.bonus_mac_amount,
               users.referral_points, users.referral_cash_cents, parent.name AS parent_name,
               users.corp, users.logo, users.background_image, COUNT(ibo.id) AS device_count,
               SUM(CASE WHEN ibo.active = 1 AND (
                   ibo.expire_date IS NULL
                   OR trim(COALESCE(ibo.expire_date, \'\')) = \'\'
                   OR CASE
                       WHEN length(trim(ibo.expire_date)) = 10
                           THEN date(ibo.expire_date) >= date(\'now\', \'localtime\')
                       ELSE datetime(ibo.expire_date) > datetime(\'now\', \'localtime\')
                   END
               )
                   THEN 1 ELSE 0 END) AS active_device_count
        FROM users
        LEFT JOIN users AS parent ON parent.id = users.parent_user_id
        LEFT JOIN ibo ON ibo.id_user = users.id
        WHERE COALESCE(users.admin, 0) <> 1
        GROUP BY users.id
        ORDER BY users.id DESC
    ')->fetchAll();
}

function getManagedUser(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('
        SELECT id, name, username, store_type, mac_amount, parent_user_id
        FROM users
        WHERE id = ? AND COALESCE(admin, 0) <> 1
    ');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}

function saveManagedUser(PDO $db, array $user, ?int $id = null, ?int $parentUserId = null): void
{
    if ($id === null) {
        $stmt = $db->prepare('
            INSERT INTO users (name, username, password, admin, store_type, mac_amount, parent_user_id, referral_code)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['name'],
            $user['username'],
            $user['password'],
            $user['store_type'],
            $user['mac_amount'],
            $parentUserId,
            createReferralCode($db),
        ]);
        return;
    }

    $stmt = $db->prepare('
        UPDATE users
        SET name = ?, username = ?, store_type = ?, mac_amount = ?, password = COALESCE(?, password),
            parent_user_id = ?
        WHERE id = ? AND COALESCE(admin, 0) <> 1
    ');
    $stmt->execute([
        $user['name'],
        $user['username'],
        $user['store_type'],
        $user['mac_amount'],
        $user['password'],
        $parentUserId,
        $id,
    ]);
}

function deleteManagedUser(PDO $db, int $id): bool
{
    $db->beginTransaction();
    try {
        $children = $db->prepare('SELECT COUNT(*) FROM users WHERE parent_user_id = ?');
        $children->execute([$id]);
        if ((int) $children->fetchColumn() > 0) {
            $db->rollBack();
            return false;
        }

        $financialHistory = $db->prepare('
            SELECT
                (SELECT COUNT(*) FROM reseller_credit_orders WHERE user_id = ?)
                + (SELECT COUNT(*) FROM referral_withdrawals WHERE user_id = ?)
                + (SELECT COUNT(*) FROM referral_rewards WHERE referrer_user_id = ? OR purchaser_user_id = ?)
        ');
        $financialHistory->execute([$id, $id, $id, $id]);
        if ((int) $financialHistory->fetchColumn() > 0) {
            $db->rollBack();
            return false;
        }

        $count = $db->prepare('SELECT COUNT(*) FROM ibo WHERE id_user = ?');
        $count->execute([$id]);
        if ((int) $count->fetchColumn() > 0) {
            $db->rollBack();
            return false;
        }

        $delete = $db->prepare('DELETE FROM users WHERE id = ? AND COALESCE(admin, 0) <> 1');
        $delete->execute([$id]);
        $deleted = $delete->rowCount() === 1;
        if ($deleted) {
            $clearDefault = $db->prepare("
                UPDATE app_settings
                SET setting_value = ''
                WHERE setting_key = 'apk_default_reseller_id' AND setting_value = ?
            ");
            $clearDefault->execute([(string) $id]);
        }
        $db->commit();
        return $deleted;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function getResellerQuotaSummary(PDO $db, int $userId): array
{
    $stmt = $db->prepare("
        SELECT users.mac_amount + users.bonus_mac_amount AS total_mac_amount,
               COALESCE((
                   SELECT COUNT(*)
                   FROM ibo
                   WHERE ibo.id_user = users.id AND COALESCE(ibo.active, 0) = 1
                     AND (ibo.expire_date IS NULL OR trim(COALESCE(ibo.expire_date, '')) = '' OR CASE
                         WHEN length(trim(ibo.expire_date)) = 10
                             THEN date(ibo.expire_date) >= date('now', 'localtime')
                         ELSE datetime(ibo.expire_date) > datetime('now', 'localtime')
                     END)
               ), 0) AS active_devices,
               COALESCE((
                   SELECT SUM(children.mac_amount)
                   FROM users AS children
                   WHERE children.parent_user_id = users.id
               ), 0) AS allocated_to_children
        FROM users
        WHERE users.id = ? AND COALESCE(users.admin, 0) <> 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new InvalidArgumentException('A conta de revenda não existe.');
    }

    $limit = max(0, (int) $row['total_mac_amount']);
    $active = (int) $row['active_devices'];
    $allocated = (int) $row['allocated_to_children'];
    return [
        'mac_amount' => $limit,
        'active_devices' => $active,
        'allocated_to_children' => $allocated,
        'available' => max(0, $limit - $active - $allocated),
    ];
}

function createReferralCode(PDO $db): string
{
    $exists = $db->prepare('SELECT 1 FROM users WHERE referral_code = ?');
    do {
        $code = bin2hex(random_bytes(12));
        $exists->execute([$code]);
    } while ($exists->fetchColumn() !== false);

    return $code;
}

function resellerReferralUrl(string $referralCode): string
{
    return getPublicBaseUrl() . '/reseller/register.php?ref='
        . rawurlencode($referralCode);
}

function getDeviceStats(PDO $db): array
{
    $stats = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN COALESCE(active, 0) <> 0 THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN COALESCE(active, 0) = 0 THEN 1 ELSE 0 END), 0) AS blocked,
            COALESCE(SUM(CASE WHEN expire_date IS NOT NULL AND CASE
                WHEN length(trim(expire_date)) = 10
                    THEN date(expire_date) < date('now', 'localtime')
                ELSE datetime(expire_date) <= datetime('now', 'localtime')
            END THEN 1 ELSE 0 END), 0) AS expired
        FROM ibo
    ")->fetch();

    return [
        'total' => (int) $stats['total'],
        'active' => (int) $stats['active'],
        'blocked' => (int) $stats['blocked'],
        'expired' => (int) $stats['expired'],
    ];
}

function setDeviceActive(PDO $db, string $macAddress, bool $active): void
{
    $stmt = $db->prepare('UPDATE ibo SET active = ? WHERE mac_address = ?');
    $stmt->execute([$active ? 1 : 0, $macAddress]);
}

function deleteDevice(PDO $db, string $macAddress): void
{
    $stmt = $db->prepare('DELETE FROM ibo WHERE mac_address = ?');
    $stmt->execute([$macAddress]);
}

function getAdStatuses(PDO $db): array
{
    return $db->query('SELECT id, adstype FROM adstatus ORDER BY id DESC')->fetchAll();
}

function createAdStatus(PDO $db, string $adType): void
{
    $stmt = $db->prepare('INSERT INTO adstatus (adstype) VALUES (?)');
    $stmt->execute([$adType]);
}

function updateAdStatus(PDO $db, int $id, string $adType): void
{
    $stmt = $db->prepare('UPDATE adstatus SET adstype = ? WHERE id = ?');
    $stmt->execute([$adType, $id]);
}

function deleteAdStatus(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM adstatus WHERE id = ?');
    $stmt->execute([$id]);
}

function getSports(PDO $db): array
{
    return $db->query('SELECT id, header_n, border_c, background_c, text_c, days, api FROM sports ORDER BY id DESC')->fetchAll();
}

function createSport(PDO $db, array $values): void
{
    $stmt = $db->prepare('
        INSERT INTO sports (header_n, border_c, background_c, text_c, days, api)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $values['header_n'],
        $values['border_c'],
        $values['background_c'],
        $values['text_c'],
        $values['days'],
        $values['api'],
    ]);
}

function updateSport(PDO $db, int $id, array $values): void
{
    $stmt = $db->prepare('
        UPDATE sports
        SET header_n = ?, border_c = ?, background_c = ?, text_c = ?, days = ?, api = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $values['header_n'],
        $values['border_c'],
        $values['background_c'],
        $values['text_c'],
        $values['days'],
        $values['api'],
        $id,
    ]);
}

function deleteSport(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM sports WHERE id = ?');
    $stmt->execute([$id]);
}

function getSportConfigs(PDO $db): array
{
    return $db->query('SELECT id, title, url, c1, c2, c3 FROM sportconfira ORDER BY id DESC')->fetchAll();
}

function createSportConfig(PDO $db, array $values): void
{
    $stmt = $db->prepare('INSERT INTO sportconfira (title, url, c1, c2, c3) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$values['title'], $values['url'], $values['c1'], $values['c2'], $values['c3']]);
}

function updateSportConfig(PDO $db, int $id, array $values): void
{
    $stmt = $db->prepare('
        UPDATE sportconfira SET title = ?, url = ?, c1 = ?, c2 = ?, c3 = ?
        WHERE id = ?
    ');
    $stmt->execute([$values['title'], $values['url'], $values['c1'], $values['c2'], $values['c3'], $id]);
}

function deleteSportConfig(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM sportconfira WHERE id = ?');
    $stmt->execute([$id]);
}

function getAds(PDO $db, ?int $userId = null): array
{
    if ($userId === null) {
        $adminId = $db->query('SELECT id FROM users WHERE COALESCE(admin, 0) = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($adminId !== false) {
            $userId = (int) $adminId;
        }
    }

    if ($userId === null) {
        return $db->query('SELECT id, title, url, owner_user_id FROM ads WHERE owner_user_id IS NULL ORDER BY id DESC')->fetchAll();
    }

    $adminCheck = $db->prepare('SELECT COALESCE(admin, 0) FROM users WHERE id = ?');
    $adminCheck->execute([$userId]);
    $isAdmin = (int) $adminCheck->fetchColumn() === 1;
    $statement = $db->prepare($isAdmin
        ? 'SELECT id, title, url, owner_user_id FROM ads WHERE owner_user_id = ? OR owner_user_id IS NULL ORDER BY id DESC'
        : 'SELECT id, title, url, owner_user_id FROM ads WHERE owner_user_id = ? ORDER BY id DESC');
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

function getThemes(PDO $db): array
{
    return $db->query('SELECT id, name, url FROM theme ORDER BY id ASC')->fetchAll();
}

function getHomeThemeOptions(): array
{
    return [
        'theme_d' => ['name' => 'Tema 1', 'preview' => 'd.jpg'],
        'theme_2' => ['name' => 'Tema 2', 'preview' => '2.jpg'],
        'theme_3' => ['name' => 'Tema 3', 'preview' => '3.jpg'],
        'theme_4' => ['name' => 'Tema 4', 'preview' => '4.jpg'],
        'theme_5' => ['name' => 'Tema 5', 'preview' => '5.jpg'],
        'theme_6' => ['name' => 'Tema 6', 'preview' => '6.jpg'],
        'theme_7' => ['name' => 'Tema 7', 'preview' => '7.jpg'],
        'theme_8' => ['name' => 'Tema 8', 'preview' => '8.jpg'],
        'theme_9' => ['name' => 'Tema 9', 'preview' => '9.jpg'],
    ];
}

function getDefaultHomeTheme(PDO $db): string
{
    $statement = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'home_theme_default'");
    $statement->execute();
    $theme = (string) ($statement->fetchColumn() ?: 'theme_d');
    return array_key_exists($theme, getHomeThemeOptions()) ? $theme : 'theme_d';
}

function setDefaultHomeTheme(PDO $db, string $theme): void
{
    if (!array_key_exists($theme, getHomeThemeOptions())) {
        throw new InvalidArgumentException('Selecione um dos temas disponíveis.');
    }
    $statement = $db->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES ('home_theme_default', ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
    ");
    $statement->execute([$theme]);
}

function normalizeDeviceMac(string $mac): ?string
{
    $compact = strtoupper((string) preg_replace('/[^0-9A-F]/i', '', $mac));
    if (strlen($compact) === 11) {
        $compact = '0' . $compact;
    } elseif (strlen($compact) > 12) {
        $compact = substr($compact, 0, 12);
    }
    if (strlen($compact) !== 12) {
        return null;
    }

    return implode(':', str_split($compact, 2));
}

function isDeviceExpired(?string $expireDate, ?int $now = null): bool
{
    if ($expireDate === null || trim($expireDate) === '') {
        return false;
    }

    $expiration = strtotime($expireDate);
    if ($expiration === false) {
        return false;
    }

    $now ??= time();
    $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expireDate) === 1;
    return $dateOnly
        ? date('Y-m-d', $expiration) < date('Y-m-d', $now)
        : $expiration <= $now;
}

function getPublicBaseUrl(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostParts = $host !== '' ? parse_url('//' . $host) : false;
    $hostname = is_array($hostParts) ? (string) ($hostParts['host'] ?? '') : '';
    $port = is_array($hostParts) ? ($hostParts['port'] ?? null) : null;
    $validHostname = filter_var($hostname, FILTER_VALIDATE_IP) !== false
        || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    if ($validHostname && ($port === null || ($port >= 1 && $port <= 65535))) {
        $forwardedScheme = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if (in_array($forwardedScheme, ['http', 'https'], true)) {
            $scheme = $forwardedScheme;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || (int) ($port ?? 0) === 443
                ? 'https'
                : 'http';
        }
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptName = '/' . ltrim(parse_url($scriptName, PHP_URL_PATH) ?: '', '/');
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        foreach (['/admin/', '/api/', '/reseller/', '/banner/'] as $applicationPath) {
            $position = stripos($scriptName, $applicationPath);
            if ($position !== false) {
                $basePath = rtrim(substr($scriptName, 0, $position), '/');
                break;
            }
        }

        $authorityHost = str_contains($hostname, ':') ? '[' . $hostname . ']' : $hostname;
        $authority = $authorityHost . ($port === null ? '' : ':' . $port);
        return $scheme . '://' . $authority . $basePath;
    }

    $configuredUrl = trim((string) getenv('IBO_PUBLIC_URL'));
    if ($configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        if (is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])) {
            return rtrim($configuredUrl, '/');
        }
        throw new RuntimeException('A configuração IBO_PUBLIC_URL precisa ser uma URL HTTP ou HTTPS válida.');
    }

    throw new RuntimeException('Não foi possível determinar o domínio público da requisição. Configure IBO_PUBLIC_URL com uma URL HTTP ou HTTPS válida.');
}

function getDeviceActivationUrl(array $device, string $mac): string
{
    return getPublicBaseUrl() . '/ativar.php?' . http_build_query([
        'mac' => normalizeDeviceMac($mac) ?? $mac,
        'key' => (string) ($device['key'] ?? ''),
    ], '', '&', PHP_QUERY_RFC3986);
}

function getDeviceActivationQrUrl(): string
{
    return getPublicBaseUrl() . '/api/qr.php';
}

function decodeAppDeviceMac(string $encoded, ?string &$failureReason = null): ?string
{
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $failureReason = 'invalid_encoded_data';
    $encoded = trim($encoded);
    $candidates = [$encoded];

    if (strlen($encoded) >= 2) {
        $length = strlen($encoded);
        $firstPosition = strpos($characters, $encoded[$length - 2]);
        $removeLength = strpos($characters, $encoded[$length - 1]);
        if ($firstPosition !== false && $removeLength !== false) {
            $payload = substr($encoded, 0, $length - 2);
            $candidates[] = substr($payload, 0, $firstPosition)
                . substr($payload, $firstPosition + $removeLength);
        }
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        $jsonCandidates = [];
        $asJson = json_decode($candidate, true);
        if (is_array($asJson)) {
            $jsonCandidates[] = $asJson;
        }

        $base64Input = preg_replace('/\s+/', '', $candidate);
        if (is_string($base64Input)) {
            if (str_ends_with($base64Input, 'aa')) {
                $base64Input = substr($base64Input, 0, -2);
            }
            $normalizedBase64 = strtr($base64Input, '-_', '+/');
            $normalizedBase64 .= str_repeat('=', (4 - strlen($normalizedBase64) % 4) % 4);
            $decodedJson = base64_decode($normalizedBase64, true);
            if ($decodedJson !== false) {
                $decodedData = json_decode($decodedJson, true);
                if (is_array($decodedData)) {
                    $jsonCandidates[] = $decodedData;
                }
            }
        }

        foreach ($jsonCandidates as $data) {
            if (!isset($data['app_device_id']) || !is_string($data['app_device_id'])) {
                $failureReason = 'missing_app_device_id';
                continue;
            }

            $appDeviceId = trim($data['app_device_id']);
            $decodedMac = base64_decode($appDeviceId, true);
            if ($decodedMac !== false) {
                $mac = normalizeDeviceMac($decodedMac);
                if ($mac !== null) {
                    $failureReason = '';
                    return $mac;
                }
            }

            $mac = normalizeDeviceMac($appDeviceId);
            if ($mac !== null) {
                $failureReason = '';
                return $mac;
            }

            $failureReason = 'invalid_app_device_id';
        }
    }

    return null;
}

function findDeviceByMac(PDO $db, string $mac): ?array
{
    $normalized = normalizeDeviceMac($mac);
    if ($normalized === null) {
        return null;
    }

    $compact = str_replace(':', '', $normalized);
    $legacyCompact = str_starts_with($compact, '0') ? substr($compact, 1) : $compact;
    $stmt = $db->prepare('
        SELECT * FROM ibo
        WHERE upper(replace(replace(mac_address, char(58), ""), char(45), "")) IN (?, ?)
        ORDER BY CASE
            WHEN upper(replace(replace(mac_address, char(58), ""), char(45), "")) = ? THEN 0
            ELSE 1
        END, id DESC
        LIMIT 1
    ');
    $stmt->execute([$compact, $legacyCompact, $compact]);
    $device = $stmt->fetch();
    return $device === false ? null : $device;
}

function isDeviceRequestAuthorized(array $device): bool
{
    $deviceKey = (string) ($device['key'] ?? '');
    $providedKey = trim((string) ($_SERVER['HTTP_X_DEVICE_KEY'] ?? ''));

    if ($providedKey === '') {
        $authorization = trim((string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        ));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            $providedKey = trim($matches[1]);
        }
    }

    return $deviceKey !== ''
        && $providedKey !== ''
        && hash_equals($deviceKey, $providedKey);
}

function getDeviceAppResponse(PDO $db, ?array $device, string $mac): array
{
    require_once __DIR__ . '/branding.php';
    require_once __DIR__ . '/ads.php';
    $themes = array_map(
        static fn(array $theme): array => [
            'id' => (int) $theme['id'],
            'name' => $theme['name'],
            'url' => $theme['url'],
        ],
        getThemes($db)
    );

    $expired = isDeviceExpired(isset($device['expire_date']) ? (string) $device['expire_date'] : null);
    $active = $device !== null && (int) ($device['active'] ?? 1) === 1;
    $blocked = $device !== null && !$active;
    $deviceStatus = $device === null
        ? 'unregistered'
        : ($blocked ? 'blocked' : ($expired ? 'expired' : 'active'));
    $activationQrUrl = $device !== null && $expired && $active
        ? getDeviceActivationQrUrl()
        : '';
    $accountId = isset($device['id_user']) && (int) $device['id_user'] > 0
        ? (int) $device['id_user']
        : null;
    $logoUrl = getAccountLogoUrl($db, $accountId);
    $backgroundUrl = getAccountBackgroundUrl($db, $accountId);
    $brandingName = getAccountBrandingName($db, $accountId);
    $homeTheme = getAccountHomeTheme($db, $accountId);
    $ads = getAdApiResponse($db, $accountId, normalizeDeviceMac($mac) ?? $mac);
    $playlistUrl = '';

    if ($device !== null && $active && !$expired) {
        if ((string) ($device['type'] ?? '') === '0' && !empty($device['dns'])) {
            $playlistUrl = rtrim((string) $device['dns'], '/') . '/get.php?' . http_build_query([
                'username' => (string) ($device['username'] ?? ''),
                'password' => (string) ($device['password'] ?? ''),
                'type' => 'm3u_plus',
                'output' => 'ts',
            ], '', '&', PHP_QUERY_RFC3986);
        } else {
            $playlistUrl = trim((string) ($device['url'] ?? ''));
        }
    }

    $hasPlaylist = $playlistUrl !== '';
    $playlistType = (string) ($device['type'] ?? '');
    $playlistName = $blocked
        ? 'Dispositivo bloqueado'
        : ($hasPlaylist ? (string) ($device['title'] ?? 'Playlist') : 'Não configurado');
    $playlistId = $hasPlaylist
        ? md5((string) ($device['password'] ?? '') . (string) ($device['id'] ?? ''))
        : '0';
    $playlistProtected = $device !== null && (int) ($device['playlist_protected'] ?? 1) === 1;
    $playlistPin = $playlistProtected
        ? ((string) ($device['playlistpassword'] ?? '') !== '' ? (string) $device['playlistpassword'] : '0000')
        : '';
    $urls = [[
        'url' => $hasPlaylist ? $playlistUrl : 'http://blocked.local/',
        'epg_url' => $hasPlaylist ? (string) ($device['epg_url'] ?? '') : '',
        'name' => $playlistName,
        'playlist_name' => $playlistName,
        'username' => $hasPlaylist ? (string) ($device['username'] ?? '') : 'blocked',
        'password' => $hasPlaylist ? (string) ($device['password'] ?? '') : 'blocked',
        'playlist_type' => $hasPlaylist && $playlistType === '0' ? 'xc' : 'general',
        'is_protected' => $playlistProtected ? 1 : 0,
        'pin' => $playlistPin,
        'id' => $playlistId,
        'is_blocked' => $blocked,
    ]];

    return [
        'android_version_code' => '2.9',
        'apk_url' => 'https://ksg.jogai.shop/api/app-release.apk',
        'mac_address' => normalizeDeviceMac($mac) ?? $mac,
        'logo' => $logoUrl,
        'logo_url' => $logoUrl,
        'background_url' => $backgroundUrl,
        'branding_name' => $brandingName,
        'home_theme' => $homeTheme,
        'theme_config_url' => getPublicBaseUrl() . '/api/theme_config.php?' . http_build_query([
            'mac' => normalizeDeviceMac($mac) ?? $mac,
        ], '', '&', PHP_QUERY_RFC3986),
        'ads' => $ads,
        'ads_config_url' => getAdDisplayUrl(normalizeDeviceMac($mac) ?? $mac) . '&format=json',
        'portals' => getPortals($db),
        'mac_registered' => $device !== null && !$blocked,
        'active' => $active,
        'is_active' => $active,
        'is_blocked' => $blocked,
        'device_status' => $deviceStatus,
        'status_message' => $blocked
            ? 'Este dispositivo foi bloqueado pelo administrador ou pela revenda.'
            : ($expired ? 'A licença deste dispositivo está vencida.' : ''),
        'urls' => $urls,
        'themes' => $themes,
        'trial_days' => $device !== null && $active && !$expired ? 7 : 0,
        'device_key' => (string) ($device['key'] ?? ''),
        'is_trial' => $expired ? 1 : 0,
        'expire_date' => (string) ($device['expire_date'] ?? ''),
        'qrcode' => $activationQrUrl,
        'qr_url' => $activationQrUrl,
        'notification' => '{}',
        'languages' => [],
    ];
}

function createTheme(PDO $db, string $name, string $url): void
{
    $stmt = $db->prepare('INSERT INTO theme (name, url) VALUES (?, ?)');
    $stmt->execute([$name, $url]);
}

function updateTheme(PDO $db, int $id, string $name, string $url): void
{
    $stmt = $db->prepare('UPDATE theme SET name = ?, url = ? WHERE id = ?');
    $stmt->execute([$name, $url, $id]);
}

function deleteTheme(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM theme WHERE id = ?');
    $stmt->execute([$id]);
}

function getIntroUrl(PDO $db): string
{
    return (string) ($db->query('SELECT url FROM intro ORDER BY id LIMIT 1')->fetchColumn() ?: '');
}

function setIntroUrl(PDO $db, string $url): void
{
    $stmt = $db->query('SELECT id FROM intro ORDER BY id LIMIT 1');
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $insert = $db->prepare('INSERT INTO intro (url) VALUES (?)');
        $insert->execute([$url]);
        return;
    }

    $update = $db->prepare('UPDATE intro SET url = ? WHERE id = ?');
    $update->execute([$url, $id]);
}

function getPortals(PDO $db): array
{
    return $db->query('SELECT id, name, url FROM portals ORDER BY id ASC')->fetchAll();
}

function createPortal(PDO $db, string $name, string $url): void
{
    $stmt = $db->prepare('INSERT INTO portals (name, url) VALUES (?, ?)');
    $stmt->execute([$name, $url]);
}

function updatePortal(PDO $db, int $id, string $name, string $url): void
{
    $stmt = $db->prepare('UPDATE portals SET name = ?, url = ? WHERE id = ?');
    $stmt->execute([$name, $url, $id]);
}

function deletePortal(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM portals WHERE id = ?');
    $stmt->execute([$id]);
}

function createAd(PDO $db, string $title, string $url, int $ownerUserId): void
{
    $stmt = $db->prepare('INSERT INTO ads (title, url, owner_user_id) VALUES (?, ?, ?)');
    $stmt->execute([$title, $url, $ownerUserId]);
}

function updateAd(PDO $db, int $id, string $title, string $url, int $ownerUserId): void
{
    $adminCheck = $db->prepare('SELECT COALESCE(admin, 0) FROM users WHERE id = ?');
    $adminCheck->execute([$ownerUserId]);
    $isAdmin = (int) $adminCheck->fetchColumn() === 1;
    $stmt = $db->prepare($isAdmin
        ? 'UPDATE ads SET title = ?, url = ?, owner_user_id = ? WHERE id = ? AND (owner_user_id = ? OR owner_user_id IS NULL)'
        : 'UPDATE ads SET title = ?, url = ? WHERE id = ? AND owner_user_id = ?');
    if ($isAdmin) {
        $stmt->execute([$title, $url, $ownerUserId, $id, $ownerUserId]);
    } else {
        $stmt->execute([$title, $url, $id, $ownerUserId]);
    }
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('O anúncio não pertence a esta conta.');
    }
}

function deleteAd(PDO $db, int $id, int $ownerUserId): void
{
    $adminCheck = $db->prepare('SELECT COALESCE(admin, 0) FROM users WHERE id = ?');
    $adminCheck->execute([$ownerUserId]);
    $isAdmin = (int) $adminCheck->fetchColumn() === 1;
    $stmt = $db->prepare($isAdmin
        ? 'DELETE FROM ads WHERE id = ? AND (owner_user_id = ? OR owner_user_id IS NULL)'
        : 'DELETE FROM ads WHERE id = ? AND owner_user_id = ?');
    $stmt->execute([$id, $ownerUserId]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('O anúncio não pertence a esta conta.');
    }
}
