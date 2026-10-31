<?php
$productName = 'Gerador Kesung';
$whatsappNumber = '5541996997323';
$displayPhone = '(43) 98857-4313';
$price = '';
$year = date('Y');
$whatsapp = static fn (string $message): string =>
    'https://wa.me/' . $whatsappNumber . '?text=' . rawurlencode($message);
$buyLink = $whatsapp("Olá! Quero o acesso vitalício ao {$productName}.");
$questionLink = $whatsapp("Olá! Tenho uma dúvida sobre o {$productName}.");
$demoLink = $whatsapp("Olá! Quero ver uma demonstração do {$productName}.");
$registerLink = $whatsapp("Olá! Quero criar uma conta no {$productName}.");
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$requestPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$assetPrefix = preg_match('~/landpag(?:/|$)~i', $requestPath) === 1 ? '../' : '';
$assetUrl = static fn (string $path): string => $assetPrefix . ltrim($path, '/');

$showcaseCards = [
    ['image' => 'assets/banner-cards.svg', 'name' => 'Cards', 'description' => 'Uma linha por jogo, com horário e canal em destaque.'],
    ['image' => 'assets/banner-arena.svg', 'name' => 'Arena', 'description' => 'Placar escuro com a competição acima de cada jogo.'],
    ['image' => 'assets/banner-tabela.svg', 'name' => 'Tabela', 'description' => 'Horários em coluna, direto como uma tabela de jogos.'],
];
$showcaseDirectory = __DIR__ . '/uploads/banners-home';
if (is_dir($showcaseDirectory)) {
    $files = glob($showcaseDirectory . '/*') ?: [];
    usort($files, 'strnatcasecmp');
    $allowedTypes = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
    foreach ($files as $file) {
        if (!is_file($file) || filesize($file) > 12 * 1024 * 1024) {
            continue;
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $image = @getimagesize($file);
        if (!$image || !isset($allowedTypes[$extension]) || $image[2] !== $allowedTypes[$extension] || $image[0] > 10000 || $image[1] > 10000) {
            continue;
        }
        $index = count(array_filter($showcaseCards, static fn (array $card): bool => !str_ends_with($card['image'], '.svg')));
        if ($index >= 3) {
            break;
        }
        $showcaseCards[$index] = [
            'image' => 'uploads/banners-home/' . rawurlencode(basename($file)),
            'name' => 'Banner ' . ($index + 1),
            'description' => 'Arte personalizada com a sua marca.',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a1633">
    <meta name="description" content="Crie banners personalizados e vídeos com trailers automáticos em segundos, com seu logotipo e telefone. Jogos, esportes, filmes e séries.">
    <meta property="og:title" content="<?= $escape($productName) ?>">
    <meta property="og:description" content="Banners e vídeos com trailer automático, já com a sua logo e o seu telefone.">
    <meta property="og:image" content="<?= $escape($assetUrl('assets/banner-cards.svg')) ?>">
    <title><?= $escape($productName) ?> — Banners de jogos e vídeos com trailer</title>
    <link rel="icon" type="image/svg+xml" href="<?= $escape($assetUrl('assets/banner-cards.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{--navy:#0a1633;--navy2:#101f47;--blue:#1f8bff;--yellow:#ffb81c;--purple:#7b2fe0;--green:#2fe02a;--paper:#f4f6fc;--white:#fff;--ink:#0a1633;--muted:#5b6585;--line:rgba(10,22,51,.12);--display:'Bebas Neue',Impact,sans-serif;--cond:'Barlow Condensed',sans-serif;--body:'Barlow',system-ui,sans-serif}
        *{box-sizing:border-box;margin:0;padding:0}html{scroll-behavior:smooth;scroll-padding-top:84px}body{font-family:var(--body);color:var(--ink);background:var(--paper);font-size:18px;line-height:1.55;-webkit-font-smoothing:antialiased}img{max-width:100%;display:block}a{color:inherit}:focus-visible{outline:3px solid var(--yellow);outline-offset:3px;border-radius:8px}.wrap{width:min(1160px,92%);margin-inline:auto}h1,h2,h3{font-family:var(--display);font-weight:400;letter-spacing:.01em;line-height:1}h2{font-size:clamp(2.4rem,5vw,3.8rem)}h3{font-size:1.7rem}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.6rem;min-height:44px;padding:0 20px;border-radius:999px;font-family:var(--cond);font-weight:700;font-size:1.08rem;letter-spacing:.03em;text-transform:uppercase;text-decoration:none;border:1px solid transparent;transition:transform .15s,box-shadow .15s}.btn:hover{transform:translateY(-2px)}.btn-green{background:var(--green);color:#052b04;box-shadow:0 8px 24px rgba(47,224,42,.28)}.btn-wa{background:#25d366;color:#052b04}.btn-line{border-color:rgba(255,255,255,.4);color:var(--white)}.btn-line:hover{background:rgba(255,255,255,.1)}.pill{display:inline-block;background:var(--purple);color:var(--white);border-radius:999px;padding:.25rem 1.1rem;font-family:var(--cond);font-weight:700;font-size:1.1rem;letter-spacing:.04em;text-transform:uppercase}
        .top{position:sticky;top:0;z-index:30;background:rgba(10,22,51,.96);backdrop-filter:blur(10px);padding-top:env(safe-area-inset-top,0)}.top .wrap{display:flex;align-items:center;justify-content:space-between;min-height:76px;gap:20px}.brand{display:flex;align-items:center;gap:11px;color:#fff;text-decoration:none;font-family:var(--display);font-size:1.8rem;letter-spacing:.04em}.brand-mark{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:linear-gradient(140deg,var(--blue),var(--purple));font:700 23px var(--cond);box-shadow:0 8px 24px #1f8bff55}.top nav{display:flex;align-items:center;gap:1.5rem}.top nav a{color:rgba(255,255,255,.82);text-decoration:none;font-family:var(--cond);font-weight:700;font-size:1.1rem;letter-spacing:.04em;text-transform:uppercase}.top nav a:hover{color:var(--yellow)}
        .hero{background:radial-gradient(900px 500px at 85% 20%,rgba(123,47,224,.45),transparent 60%),radial-gradient(700px 400px at 0% 100%,rgba(31,139,255,.25),transparent 60%),var(--navy);color:var(--white);padding:4rem 0 5rem;overflow:hidden}.hero .wrap{display:grid;grid-template-columns:1fr 1fr;gap:3rem;align-items:center}.hero h1{font-size:clamp(3.2rem,7vw,5.8rem)}.hero h1 span{color:var(--yellow)}.lead{margin:1.4rem 0 2rem;font-size:1.2rem;color:rgba(255,255,255,.78);max-width:30em}.cta{display:flex;flex-wrap:wrap;gap:.9rem}.hero small{display:block;margin-top:1.2rem;color:rgba(255,255,255,.6);font-size:1rem}.fan{position:relative;height:490px}.fan img{position:absolute;width:55%;height:auto;aspect-ratio:4/5;object-fit:cover;border-radius:18px;box-shadow:0 28px 60px rgba(0,0,0,.55);border:3px solid rgba(255,255,255,.12)}.fan .f1{left:0;top:50px;transform:rotate(-7deg)}.fan .f3{right:0;top:30px;transform:rotate(7deg)}.fan .f2{left:50%;top:0;transform:translateX(-50%);z-index:2;width:58%}
        .band{background:var(--purple);padding:1rem 0}.band .wrap{display:flex;flex-wrap:wrap;gap:.6rem 2rem;justify-content:center;font-family:var(--display);font-size:1.7rem;color:var(--white);letter-spacing:.04em}
        section{padding:5rem 0}.head{text-align:center;margin:0 auto 2.5rem;max-width:760px}.head p{margin-top:1rem;color:var(--muted)}.models{display:grid;grid-template-columns:repeat(3,1fr);gap:1.4rem}.model{text-align:center;padding:1rem 1rem 1.4rem;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(10,22,51,.06)}.model figure{overflow:hidden;border-radius:12px;background:var(--navy);margin-bottom:1.2rem}.model figure img{width:100%;aspect-ratio:4/5;object-fit:cover}.model p{margin:.8rem auto 0;color:var(--muted);max-width:26em}
        .rows{display:grid;grid-template-columns:repeat(2,1fr);border:1px solid var(--line);border-radius:16px;overflow:hidden;background:#fff}.row{display:grid;grid-template-columns:150px 1fr;align-items:center;min-height:90px;border-bottom:1px solid var(--line)}.row:nth-child(odd){border-right:1px solid var(--line)}.row b{height:100%;display:flex;align-items:center;padding:1rem 1.3rem;background:#edf0fb;font-family:var(--cond);font-size:1.25rem}.row span{padding:1rem 1.3rem;color:var(--muted);font-size:1rem}
        .steps{background:#e9edf7}.steps ol{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;list-style:none;counter-reset:step}.steps li{counter-increment:step;padding:1.5rem;border:1px solid var(--line);border-radius:16px;background:#fff}.steps li:before{content:"0" counter(step);display:block;margin-bottom:1.2rem;color:var(--purple);font:2.6rem var(--display)}.steps li p{margin-top:.6rem;color:var(--muted)}
        .offer{background:var(--navy);color:#fff}.offer .box{display:grid;grid-template-columns:1.3fr .7fr;align-items:center;gap:3rem;padding:2.2rem;border:1px solid #ffffff24;border-radius:22px;background:linear-gradient(135deg,#101f47,#11172f)}.offer h2{font-size:clamp(2.3rem,4vw,3.2rem)}.offer ul{margin:1.4rem 0 0 1.2rem;color:rgba(255,255,255,.8)}.offer li{margin:.5rem 0}.price{text-align:center;padding:1.4rem;border-radius:18px;background:#fff;color:var(--ink)}.price-mark{display:grid;place-items:center;width:68px;height:68px;margin:0 auto 1rem;border-radius:20px;background:linear-gradient(140deg,var(--blue),var(--purple));color:#fff;font:700 34px var(--cond)}.big{font:2rem var(--display);color:var(--purple)}.price p{margin:.3rem 0 1rem;color:var(--muted);font-size:1rem}.faq{max-width:850px;margin:auto}.faq details{padding:1.1rem 1.3rem;border-bottom:1px solid var(--line);background:#fff}.faq summary{cursor:pointer;font-weight:700;list-style:none;display:flex;justify-content:space-between;gap:1rem}.faq summary::-webkit-details-marker{display:none}.faq summary:after{content:"+";font-size:1.6rem;line-height:1;color:var(--purple)}.faq details[open] summary:after{content:"−"}.faq details p{margin-top:.8rem;color:var(--muted)}
        .published{padding:4rem 0;background:#e9edf7}.published h2{text-align:center;font-size:clamp(2rem,4vw,3rem)}.published>div>p{text-align:center;margin:.7rem 0 2rem;color:var(--muted)}.published-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem}.published-grid img{width:100%;aspect-ratio:4/5;object-fit:cover;border:1px solid #d8deeb;border-radius:13px;background:#fff;box-shadow:0 8px 20px rgba(10,22,51,.07)}
        .final{background:var(--navy);color:#fff;text-align:center;padding:5rem 0}.final .brand-mark{margin:0 auto 1.2rem;width:64px;height:64px;font-size:32px}.final p{margin:.8rem 0 1.8rem;color:rgba(255,255,255,.75)}footer{background:#060e22;color:rgba(255,255,255,.6);padding:1.6rem 0 calc(1.6rem + env(safe-area-inset-bottom,0));font-size:.95rem;text-align:center}.admin-link{display:block;margin-top:1.2rem;color:rgba(255,255,255,.6);font-size:.9rem}
        @media(max-width:900px){.top nav{gap:.8rem}.top nav a{font-size:.95rem}.top nav a[href^="#"]{display:none}.hero .wrap,.offer .box{grid-template-columns:1fr}.fan{height:420px;max-width:450px;margin-inline:auto;width:100%}.models,.steps ol{grid-template-columns:1fr}.model{max-width:420px;margin-inline:auto;width:100%}.row{grid-template-columns:1fr}.row b{padding:.8rem 1rem}.row:nth-child(odd){border-right:0}.published-grid{grid-template-columns:1fr;max-width:420px;margin:auto}}
        @media(max-width:520px){body{font-size:16px}.top .wrap{min-height:66px}.top nav{gap:.65rem}.top nav a{font-size:.82rem}.brand{font-size:1.4rem}.brand-mark{width:38px;height:38px}.hero{padding:3rem 0}.hero .wrap{gap:1rem}.fan{height:340px}.cta .btn{width:100%}.offer .box{padding:1.25rem}.band .wrap{font-size:1.35rem}}
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a class="brand" href="#" aria-label="<?= $escape($productName) ?>"><span class="brand-mark" aria-hidden="true">K</span><span><?= $escape($productName) ?></span></a>
        <nav aria-label="Navegação principal">
            <a href="#modelos">Modelos</a><a href="#recursos">Recursos</a><a href="#faq">Dúvidas</a>
            <a href="<?= $escape($registerLink) ?>" target="_blank" rel="noopener">Criar conta</a><a href="<?= $escape($buyLink) ?>" target="_blank" rel="noopener">WhatsApp</a>
        </nav>
    </div>
</header>
<main>
    <div class="hero"><div class="wrap">
        <div>
            <h1>Jogos do dia e trailers com a <span>sua marca</span></h1>
            <p class="lead">Escolha o conteúdo e o <?= $escape($productName) ?> monta o banner e o vídeo com o seu logotipo e o seu telefone. Em segundos, pronto para postar.</p>
            <div class="cta"><a class="btn btn-green" href="<?= $escape($buyLink) ?>" target="_blank" rel="noopener">Quero o acesso vitalício</a><a class="btn btn-line" href="<?= $escape($demoLink) ?>" target="_blank" rel="noopener">Ver demonstração</a></div>
            <small>Pagamento único. Sem mensalidade.</small>
        </div>
        <div class="fan" aria-label="Exemplos de banners">
            <img class="f1" src="<?= $escape($assetUrl($showcaseCards[1]['image'])) ?>" alt="Exemplo de banner Arena" width="400" height="500">
            <img class="f3" src="<?= $escape($assetUrl($showcaseCards[2]['image'])) ?>" alt="Exemplo de banner Tabela" width="400" height="500">
            <img class="f2" src="<?= $escape($assetUrl($showcaseCards[0]['image'])) ?>" alt="Exemplo de banner Cards" width="400" height="500">
        </div>
    </div></div>
    <div class="band"><div class="wrap"><span>Jogos</span><span>Esportes</span><span>Filmes</span><span>Séries</span><span>Propagandas</span></div></div>
    <section id="modelos"><div class="wrap">
        <div class="head"><h2>Três exemplos de banners</h2><p>Conheça diferentes estilos para divulgar seus jogos e conteúdos. Os exemplos podem ser substituídos pelas suas artes na pasta <strong>uploads/banners-home</strong>.</p></div>
        <div class="models"><?php foreach ($showcaseCards as $card): ?><article class="model"><figure><img src="<?= $escape($assetUrl($card['image'])) ?>" alt="Prévia do modelo <?= $escape($card['name']) ?>" loading="lazy"></figure><span class="pill"><?= $escape($card['name']) ?></span><p><?= $escape($card['description']) ?></p></article><?php endforeach; ?></div>
    </div></section>
    <section id="recursos" style="padding-top:0"><div class="wrap">
        <div class="head"><h2>O que você leva no <?= $escape($productName) ?></h2></div>
        <div class="rows">
            <div class="row"><b>Sua logo</b><span>Envie o logotipo uma vez e ele entra em todos os banners e vídeos.</span></div>
            <div class="row"><b>Seu telefone</b><span>O cliente vê o número na arte e chama você direto no WhatsApp.</span></div>
            <div class="row"><b>Trailer automático</b><span>Vídeos com o trailer do filme ou da série, já com a sua identidade.</span></div>
            <div class="row"><b>Rápido</b><span>Escolha o conteúdo, gere e baixe. Sem editor e sem aprender programa.</span></div>
            <div class="row"><b>No celular</b><span>Gere e compartilhe pelo celular ou pelo computador, onde estiver.</span></div>
            <div class="row"><b>Pagamento único</b><span>Você paga uma vez e tem acesso vitalício, sem cobrança mensal.</span></div>
        </div>
    </div></section>
    <section class="steps"><div class="wrap"><div class="head"><h2>Como começar</h2></div><ol>
        <li><h3>Fale com a gente</h3><p>Chame no WhatsApp e libere o seu acesso.</p></li>
        <li><h3>Configure sua marca</h3><p>Cadastre seu logotipo e seu telefone uma única vez.</p></li>
        <li><h3>Gere e divulgue</h3><p>Escolha o conteúdo, baixe o banner ou o vídeo e poste.</p></li>
    </ol></div></section>
    <section class="offer"><div class="wrap box">
        <div><h2>Acesso vitalício por um pagamento único</h2><ul><li>Banners ilimitados com a sua marca</li><li>Vídeos com trailer automático</li><li>Jogos, esportes, filmes, séries e propagandas</li><li>Atendimento direto pelo WhatsApp</li></ul></div>
        <div class="price"><span class="price-mark" aria-hidden="true">K</span><div class="big"><?= $price !== '' ? $escape($price) : 'Consulte no WhatsApp' ?></div><p>Pagamento único · acesso vitalício</p><a class="btn btn-wa" href="<?= $escape($buyLink) ?>" target="_blank" rel="noopener">Garantir meu acesso</a></div>
    </div></section>
    <section id="faq"><div class="wrap"><div class="head"><h2>Dúvidas frequentes</h2></div><div class="faq">
        <details><summary>Preciso pagar mensalidade?</summary><p>Não. O pagamento é único e o acesso é vitalício.</p></details>
        <details><summary>Meu logotipo e meu telefone aparecem nas artes?</summary><p>Sim. Você cadastra os dois e eles entram automaticamente em todos os banners e vídeos.</p></details>
        <details><summary>Posso usar no celular?</summary><p>Sim. Funciona no celular e no computador.</p></details>
        <details><summary>Que tipo de conteúdo posso criar?</summary><p>Banners e vídeos de jogos, esportes, filmes, séries e propagandas.</p></details>
        <details><summary>Como compro?</summary><p>Clique em qualquer botão de WhatsApp desta página e fale com a gente no <?= $escape($displayPhone) ?>.</p></details>
    </div></div></section>
    <section class="published"><div class="wrap"><h2>Modelos disponíveis</h2><p>Prévia dos estilos de arte incluídos no gerador.</p><div class="published-grid"><?php foreach ($showcaseCards as $card): ?><img src="<?= $escape($assetUrl($card['image'])) ?>" alt="Modelo <?= $escape($card['name']) ?>" loading="lazy"><?php endforeach; ?></div></div></section>
    <section class="final"><div class="wrap"><span class="brand-mark" aria-hidden="true">K</span><h2>Pronto para divulgar com a sua marca?</h2><p>Chame no WhatsApp e comece hoje.</p><a class="btn btn-wa" href="<?= $escape($questionLink) ?>" target="_blank" rel="noopener">Falar no WhatsApp</a><a class="admin-link" href="<?= $escape($assetUrl('admin/login.php')) ?>">Acesso administrativo</a></div></section>
</main>
<footer><div class="wrap">© <?= $escape($year) ?> <?= $escape($productName) ?>. Todos os direitos reservados.</div></footer>
</body>
</html>
