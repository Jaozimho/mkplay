<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../api/includes/db.php';
$resellerId = filter_var($_SESSION['reseller_user_id'] ?? null, FILTER_VALIDATE_INT);
if ($resellerId === false || $resellerId === null) {
    header('Location: login.php');
    exit;
}

$resellerQuery = getDB()->prepare('
    SELECT id, name, username, password, mac_amount, store_type,
           parent_user_id, referred_by_user_id, referral_code, bonus_mac_amount, referral_points,
           referral_cash_cents, referral_pix_key, logo, background_image, branding_name, home_theme
    FROM users
    WHERE id = ? AND COALESCE(admin, 0) <> 1
');
$resellerQuery->execute([$resellerId]);
$reseller = $resellerQuery->fetch();
if (!$reseller) {
    unset($_SESSION['reseller_user_id'], $_SESSION['reseller_csrf']);
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['reseller_csrf'])) {
    $_SESSION['reseller_csrf'] = bin2hex(random_bytes(32));
}
