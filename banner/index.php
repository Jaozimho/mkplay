<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$bannerContentType = $bannerContentType ?? 'football';
$bannerBasePath = $bannerBasePath
    ?? (rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/');

require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$db = getDB();
$generatorAccountId = null;
$generatorIsAdmin = false;

$sessionAdminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!empty($_SESSION['logged_in']) && $sessionAdminId !== false && $sessionAdminId !== null) {
    $adminCheck = $db->prepare('SELECT id FROM users WHERE id = ? AND COALESCE(admin, 0) = 1');
    $adminCheck->execute([$sessionAdminId]);
    if ($adminCheck->fetchColumn() !== false) {
        $generatorAccountId = (int) $sessionAdminId;
        $generatorIsAdmin = true;
    }
}

if ($generatorAccountId === null) {
    $sessionResellerId = filter_var($_SESSION['reseller_user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($sessionResellerId !== false && $sessionResellerId !== null) {
        $resellerCheck = $db->prepare('
            SELECT id, name FROM users
            WHERE id = ? AND COALESCE(admin, 0) <> 1
        ');
        $resellerCheck->execute([$sessionResellerId]);
        $resellerAccount = $resellerCheck->fetch(PDO::FETCH_ASSOC);
        if ($resellerAccount) {
            $generatorAccountId = (int) $resellerAccount['id'];
            $reseller = $resellerAccount;
        }
    }
}

if ($generatorAccountId === null) {
    $loginPath = $bannerBasePath . (!empty($_SESSION['logged_in']) ? '../admin/login.php' : '../reseller/login.php');
    header('Location: ' . $loginPath);
    exit;
}

$generatorLogoUrl = getAccountLogoUrl($db, $generatorAccountId);
$isBannerGeneratorPage = true;
require __DIR__ . '/page.php';
