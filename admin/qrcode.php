<?php
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/../api/includes/db.php';

$portals = getPortals(getDB());
$devices = getDevices(getDB());
$selectedQrUrl = getPublicBaseUrl() . '/ativar.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gerador de QR | Admin</title>
    <link rel="stylesheet" href="assets/panel.css">
    <style>
        .top { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 22px; }
        .panel { max-width: 760px; padding: 24px; border: 1px solid #eaecf0; border-radius: 12px; background: #fff; }
        .panel p { color: #667085; line-height: 1.5; }
        label { display: block; margin: 18px 0 8px; color: #344054; font-weight: 600; }
        input, select { display: block; width: 100%; margin-top: 7px; padding: 10px 12px; border: 1px solid #d0d5dd; border-radius: 7px; font: inherit; }
        button, .download { display: inline-block; margin: 16px 8px 0 0; padding: 10px 14px; border: 0; border-radius: 7px; background: #3157d5; color: #fff !important; cursor: pointer; font: inherit; text-decoration: none; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        #qr-output { display: grid; min-height: 280px; margin-top: 24px; place-items: center; border: 1px dashed #d0d5dd; border-radius: 8px; background: #fff; }
        #qr-output canvas, #qr-output img { max-width: 100%; height: auto; }
        #qr-message { color: #b42318; }
        .hint { font-size: 13px; }
        @media (max-width: 600px) { .top { align-items: flex-start; flex-direction: column; } .panel { padding: 16px; } }
    </style>
</head>
<body class="admin-page qr-admin-page">
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header class="top">
        <h1>Gerador de QR Code</h1>
        <a href="index.php">Voltar ao dashboard</a>
    </header>
    <section class="panel">
        <p>Este é o QR universal de ativação para entregar aos clientes. Ele abre a página pública; cada cliente informa o MAC e a chave que aparecem no aplicativo vencido.</p>
        <label for="qr-content">Link universal de ativação</label>
        <input id="qr-content" type="url" inputmode="url" maxlength="2048" value="<?= htmlspecialchars($selectedQrUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://exemplo.com/playlist" autocomplete="url" required>
        <?php if ($portals !== []): ?>
            <label for="portal">Portal cadastrado (opcional)</label>
            <select id="portal">
                <option value="">Escolha um portal</option>
                <?php foreach ($portals as $portal): ?>
                    <option value="<?= htmlspecialchars((string) $portal['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $portal['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button id="generate-qr" type="button">Gerar QR Code</button>
        <a id="download-qr" class="download" hidden download="playlist-qr.png">Baixar imagem PNG</a>
        <p id="qr-message" role="status" aria-live="polite"></p>
        <div id="qr-output" aria-label="Pré-visualização do QR Code"></div>
        <p class="hint">A leitura do QR depende do aplicativo ou leitor utilizado. Use somente links que os clientes possam acessar.</p>
    </section>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('qr-content');
        const portal = document.getElementById('portal');
        const button = document.getElementById('generate-qr');
        const output = document.getElementById('qr-output');
        const message = document.getElementById('qr-message');
        const download = document.getElementById('download-qr');

        if (portal) {
            portal.addEventListener('change', function () {
                if (portal.value !== '') {
                    input.value = portal.value;
                    generateQr();
                }
            });
        }
        function generateQr() {
            const value = input.value.trim();
            let url;
            try {
                url = new URL(value);
            } catch (error) {
                message.textContent = 'Informe uma URL válida.';
                return;
            }

            if (!['http:', 'https:'].includes(url.protocol)) {
                message.textContent = 'A URL precisa começar com http:// ou https://.';
                return;
            }
            if (typeof window.QRCode !== 'function') {
                message.textContent = 'Não foi possível carregar o gerador de QR. Verifique a conexão e recarregue a página.';
                return;
            }

            output.replaceChildren();
            download.hidden = true;
            message.textContent = '';
            new window.QRCode(output, {
                text: url.href,
                width: 256,
                height: 256,
                correctLevel: window.QRCode.CorrectLevel.M
            });

            window.setTimeout(function () {
                const image = output.querySelector('img');
                if (image && image.src) {
                    download.href = image.src;
                    download.hidden = false;
                } else {
                    const canvas = output.querySelector('canvas');
                    if (canvas) {
                        download.href = canvas.toDataURL('image/png');
                        download.hidden = false;
                    }
                }
                if (download.hidden) message.textContent = 'O QR foi criado, mas o navegador não permitiu gerar o arquivo PNG.';
            }, 100);
        }

        button.addEventListener('click', generateQr);
        generateQr();
    });
</script>
</body>
</html>
