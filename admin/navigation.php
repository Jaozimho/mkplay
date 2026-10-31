<?php
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/branding.php';

$adminRoute = basename($_SERVER['SCRIPT_NAME'] ?? '');
$activeRoute = !empty($isBannerGeneratorPage)
    ? 'banner_generator.php'
    : (in_array($adminRoute, ['device_edit.php', 'device_view.php'], true) ? 'devices.php' : $adminRoute);
$adminNavPrefix = !empty($isBannerGeneratorPage) ? '../admin/' : '';
$adminLogoPath = getDefaultApkBrandingPath(getDB(), 'logo');
$adminLogoUrl = $adminLogoPath === '' ? '' : getPublicBaseUrl() . $adminLogoPath;
$adminLogoAlt = 'Logo padrão do IBO Admin';
$adminLinks = [
    ['index.php', 'Dashboard', '&#9638;'],
    ['devices.php', 'Dispositivos', '&#9635;'],
    ['users.php', 'Usuários e revendas', '&#9787;'],
    ['../reseller/login.php', 'Área da revenda', '&#10148;'],
    ['profile.php', 'Meu perfil', '&#9786;'],
    ['ads.php', 'Anúncios e fonte', '&#9654;'],
    ['../banner/', 'Gerador de banners', '&#9636;'],
    ['sports.php', 'Esportes', '&#9733;'],
    ['sportconfira.php', 'Configuração esportiva', '&#9881;'],
    ['themes.php', 'Temas', '&#9673;'],
    ['intro.php', 'Introdução do app', '&#9655;'],
    ['portals.php', 'Portais', '&#9678;'],
    ['api_logs.php', 'Logs da API', '&#9776;'],
    ['qrcode.php', 'Gerador de QR', '&#9638;'],
    ['pix.php', 'Pix e ativações', '&#36;'],
];
?>
<link rel="stylesheet" href="<?= htmlspecialchars($adminNavPrefix, ENT_QUOTES, 'UTF-8') ?>assets/panel.css?v=admin-logo-size-20261025-6">
<div class="admin-mobile-header">
    <div class="admin-mobile-brand"><span class="admin-brand-mark"><?php if ($adminLogoUrl !== ''): ?><img src="<?= htmlspecialchars($adminLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $adminLogoAlt ?>"><?php else: ?>IB<?php endif; ?></span><span>Admin</span></div>
    <button class="admin-mobile-toggle" type="button" aria-expanded="false" aria-controls="admin-nav-links" aria-label="Abrir menu">☰</button>
</div>
<button class="admin-nav-backdrop" type="button" aria-label="Fechar menu"></button>
<aside class="admin-nav">
    <div class="admin-brand"><span class="admin-brand-mark"><?php if ($adminLogoUrl !== ''): ?><img src="<?= htmlspecialchars($adminLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $adminLogoAlt ?>"><?php else: ?>IB<?php endif; ?></span><span>Admin</span></div>
    <p class="admin-nav-label">Menu principal</p>
    <nav class="admin-nav-links" id="admin-nav-links" aria-label="Menu principal">
        <?php foreach ($adminLinks as [$href, $label, $icon]): ?>
            <?php
            $active = !empty($isBannerGeneratorPage)
                ? $href === '../banner/'
                : $href === $activeRoute;
            $linkHref = !empty($isBannerGeneratorPage) && $href === '../banner/'
                ? '../banner/'
                : (!empty($isBannerGeneratorPage) && !str_starts_with($href, '../')
                    ? $adminNavPrefix . $href
                    : $href);
            ?>
            <a href="<?= htmlspecialchars($linkHref, ENT_QUOTES, 'UTF-8') ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>>
                <span class="admin-nav-icon"><?= $icon ?></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="admin-nav-footer">
        <small>Administrador</small>
        <a href="<?= htmlspecialchars($adminNavPrefix, ENT_QUOTES, 'UTF-8') ?>logout.php"><span class="admin-nav-icon">&#10148;</span>Sair</a>
    </div>
</aside>
<script>
    (function () {
        const nav = document.querySelector('.admin-nav');
        const toggle = document.querySelector('.admin-mobile-toggle');
        const backdrop = document.querySelector('.admin-nav-backdrop');
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
<style>
    body.admin-page button,
    body.admin-page input[type="submit"],
    body.admin-page input[type="button"],
    body.admin-page a.button,
    body.admin-page a.btn,
    body.admin-page [role="button"] {
        background-color: #3157d5 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
        font-weight: 600;
        opacity: 1;
        text-shadow: none;
    }

    body.admin-page button,
    body.admin-page input[type="submit"],
    body.admin-page input[type="button"],
    body.admin-page a.button,
    body.admin-page a.btn,
    body.admin-page [role="button"] {
        -webkit-text-fill-color: #fff !important;
        color: #fff !important;
        text-shadow: none !important;
    }

    body.admin-page a.referral-link,
    body.admin-page a.button,
    body.admin-page a.btn,
    body.admin-page a[class*="button"],
    body.admin-page a[role="button"] {
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-weight: 600;
    }

    body.admin-page button.danger,
    body.admin-page button.delete,
    body.admin-page button.reject,
    body.admin-page button.btn-danger,
    body.admin-page a.button.danger,
    body.admin-page a.btn-danger {
        background-color: #b42318 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
    }

    body.admin-page button:disabled,
    body.admin-page input[type="submit"]:disabled,
    body.admin-page input[type="button"]:disabled {
        background-color: #667085 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
        opacity: 1;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('body.admin-page .table-wrap table').forEach(function (table) {
            const labels = Array.from(table.querySelectorAll('thead th'), function (header) {
                return header.textContent.trim();
            });
            table.querySelectorAll('tbody tr').forEach(function (row) {
                Array.from(row.cells).forEach(function (cell, index) {
                    if (!cell.hasAttribute('colspan') && labels[index]) {
                        cell.dataset.label = labels[index];
                    }
                });
            });
        });
    });
</script>
