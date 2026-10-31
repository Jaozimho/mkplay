<?php
require_once __DIR__ . '/includes/api_log.php';
require_once __DIR__ . '/includes/db.php';
apiRegisterRuntimeLogging();
apiStartRequestLogging();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$activationUrl = getPublicBaseUrl() . '/ativar.php';
apiLog('universal_activation_qr_rendered');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title> </title>
    <style>
        * { box-sizing: border-box; }
        html, body { width: 100%; min-height: 100%; margin: 0; background: #fff; }
        body { display: grid; min-height: 100vh; place-items: center; }
        #qr-code { display: grid; width: min(256px, 90vw, 90vh); aspect-ratio: 1; place-items: center; }
        #qr-code img, #qr-code canvas { display: block; width: 100% !important; height: 100% !important; image-rendering: pixelated; }
    </style>
</head>
<body>
<div id="qr-code" aria-label="QR Code universal de ativação"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const target = document.getElementById('qr-code');
        new window.QRCode(target, {
            text: <?= json_encode($activationUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            width: 320,
            height: 320,
            correctLevel: window.QRCode.CorrectLevel.M
        });
    });
</script>
</body>
</html>
