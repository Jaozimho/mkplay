<?php
require_once __DIR__ . '/../api/includes/branding.php';

$resellerPage = !empty($isBannerGeneratorPage) ? 'banner_generator.php' : basename($_SERVER['SCRIPT_NAME'] ?? '');
$resellerNavPrefix = !empty($isBannerGeneratorPage) ? '../reseller/' : '';
$resellerName = htmlspecialchars((string) ($reseller['name'] ?? 'Revenda'), ENT_QUOTES, 'UTF-8');
$resellerLogoUrl = getAccountLogoUrl(getDB(), (int) $reseller['id']);
$resellerLogoAlt = 'Logo da conta ' . (string) ($reseller['name'] ?? 'Revenda');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>assets/panel.css?v=reseller-logo-size-20261025-4">
<div class="reseller-mobile-header">
    <div class="reseller-mobile-brand"><span class="reseller-brand-mark"><?php if ($resellerLogoUrl !== ''): ?><img src="<?= htmlspecialchars($resellerLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($resellerLogoAlt, ENT_QUOTES, 'UTF-8') ?>"><?php else: ?>IB<?php endif; ?></span><span>Revenda</span></div>
    <button class="reseller-mobile-toggle" type="button" aria-expanded="false" aria-controls="reseller-nav-links" aria-label="Abrir menu">☰</button>
</div>
<button class="reseller-nav-backdrop" type="button" aria-label="Fechar menu"></button>
<aside class="reseller-nav">
    <div class="reseller-brand"><span class="reseller-brand-mark"><?php if ($resellerLogoUrl !== ''): ?><img src="<?= htmlspecialchars($resellerLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($resellerLogoAlt, ENT_QUOTES, 'UTF-8') ?>"><?php else: ?>IB<?php endif; ?></span><span>Revenda</span></div>
    <p class="reseller-nav-label">Área do parceiro</p>
    <nav class="reseller-nav-links" id="reseller-nav-links" aria-label="Menu da revenda">
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>index.php"<?= $resellerPage === 'index.php' ? ' class="active"' : '' ?>>&#9638; Meus dispositivos</a>
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>device.php"<?= $resellerPage === 'device.php' ? ' class="active"' : '' ?>>&#43; Adicionar dispositivo</a>
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>credits.php"<?= $resellerPage === 'credits.php' ? ' class="active"' : '' ?>>&#36; Créditos e indicações</a>
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>branding.php"<?= $resellerPage === 'branding.php' ? ' class="active"' : '' ?>>&#9673; Identidade e tema do APK</a>
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>ads.php"<?= $resellerPage === 'ads.php' ? ' class="active"' : '' ?>>&#9654; Meus anúncios</a>
        <a href="<?= !empty($isBannerGeneratorPage) ? '../banner/' : 'banner_generator.php' ?>"<?= $resellerPage === 'banner_generator.php' ? ' class="active" aria-current="page"' : '' ?>>&#9636; Gerador de banners</a>
    </nav>
    <div class="reseller-nav-footer">
        <small><?= $resellerName ?></small>
        <a href="<?= htmlspecialchars($resellerNavPrefix, ENT_QUOTES, 'UTF-8') ?>logout.php">&#10148; Sair</a>
    </div>
</aside>
<script>
    (function () {
        const nav = document.querySelector('.reseller-nav');
        const toggle = document.querySelector('.reseller-mobile-toggle');
        const backdrop = document.querySelector('.reseller-nav-backdrop');
        function setMenuOpen(isOpen) {
            nav.classList.toggle('menu-open', isOpen);
            backdrop.classList.toggle('is-visible', isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.textContent = '☰';
            toggle.setAttribute('aria-label', isOpen ? 'Menu aberto; toque fora para fechar' : 'Abrir menu');
        }
        toggle.addEventListener('click', function () {
            setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });
        backdrop.addEventListener('click', function () {
            setMenuOpen(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setMenuOpen(false);
        });
    })();
</script>
