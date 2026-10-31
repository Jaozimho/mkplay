<?php
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageRole = $generatorIsAdmin ? 'admin' : 'reseller';
$stylesheetPath = 'assets/banner-generator.css';
$scriptPath = 'assets/banner-generator.js';
$bannerContentType = in_array($bannerContentType ?? '', ['football', 'movie', 'tv'], true)
    ? $bannerContentType
    : 'football';
$bannerInitialTitle = [
    'football' => 'JOGOS DO DIA',
    'movie' => 'DESTAQUE DE FILME',
    'tv' => 'DESTAQUE DE SÉRIE',
][$bannerContentType];
if (empty($_SESSION['banner_background_csrf'])) {
    $_SESSION['banner_background_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101828">
    <base href="<?= $escape($bannerBasePath) ?>">
    <title>Gerador de banners | <?= $generatorIsAdmin ? 'Admin' : 'Área da revenda' ?></title>
    <link rel="stylesheet" href="<?= $escape($stylesheetPath) ?>?v=banner-generator-20261026-44">
</head>
<body class="<?= $pageRole ?>-page banner-generator-page">
<?php
if ($generatorIsAdmin) {
    require __DIR__ . '/../admin/navigation.php';
} else {
    require __DIR__ . '/../reseller/navigation.php';
}
?>
<main class="banner-generator">
    <div id="generator-form">
    <header class="generator-heading">
        <div>
            <span class="generator-kicker">CONTEÚDO PARA SUA MARCA</span>
            <h1>Gerador de banners</h1>
            <p>Escolha os jogos, filmes ou séries. A arte usa a logo cadastrada na sua conta.</p>
        </div>
        <span class="generator-brand">
            <?php if ($generatorLogoUrl !== ''): ?>
                <img src="<?= $escape($generatorLogoUrl) ?>" alt="Logo da sua conta" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                <span hidden>Logo cadastrada</span>
            <?php else: ?>
                <span>Logo não cadastrada</span>
            <?php endif; ?>
        </span>
    </header>

    <div class="generator-message" id="generator-message" role="status" aria-live="polite" hidden></div>

    <section class="generator-panel" aria-label="Escolha do conteúdo">
        <div class="content-tabs" role="tablist" aria-label="Tipo de banner">
            <button class="content-tab<?= $bannerContentType === 'football' ? ' is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $bannerContentType === 'football' ? 'true' : 'false' ?>" data-content-type="football"><span class="tab-icon" aria-hidden="true">⚽</span> Futebol</button>
            <button class="content-tab<?= $bannerContentType === 'movie' ? ' is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $bannerContentType === 'movie' ? 'true' : 'false' ?>" data-content-type="movie"><svg class="tab-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 4v16M17 4v16M3 9h4m-4 6h4m10-6h4m-4 6h4"></path></svg> Filmes</button>
            <button class="content-tab<?= $bannerContentType === 'tv' ? ' is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $bannerContentType === 'tv' ? 'true' : 'false' ?>" data-content-type="tv"><svg class="tab-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="13" rx="2"></rect><path d="m8 21 4-3 4 3"></path></svg> Séries</button>
        </div>

        <div class="content-section" id="football-section"<?= $bannerContentType !== 'football' ? ' hidden' : '' ?>>
            <div class="section-heading">
                <div><h2>Partidas de todas as ligas do Brasil</h2><p>Carregue todos os jogos disponíveis no Brasil na data selecionada, incluindo campeonatos nacionais e estaduais. As partidas são divididas automaticamente em banners de até cinco jogos. Informe o canal ou plataforma manualmente; a API não fornece esse dado.</p></div>
            </div>
            <div class="generator-controls">
                <label for="football-date">Data das partidas
                    <input id="football-date" type="date" value="<?= (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d') ?>">
                </label>
                <button class="generator-button secondary-button" type="button" id="load-football">Carregar partidas</button>
            </div>
            <div class="selection-toolbar" id="football-toolbar" hidden>
                <span id="football-selection-count">0 partidas selecionadas</span>
                <div>
                    <button type="button" class="text-button" id="select-football">Selecionar todas</button>
                    <button type="button" class="text-button" id="clear-football">Limpar</button>
                </div>
            </div>
            <div class="content-results fixture-results" id="football-results" aria-live="polite"></div>
        </div>

        <div class="content-section" id="movie-section"<?= $bannerContentType !== 'movie' ? ' hidden' : '' ?>>
            <div class="section-heading">
                <div><h2>Lançamentos de filmes</h2><p>Escolha um filme com estreia nesta semana.</p></div>
            </div>
            <button class="generator-button secondary-button load-media" type="button" data-media-type="movie">Carregar filmes</button>
            <div class="content-results media-results" id="movie-results" aria-live="polite"></div>
            <article class="media-selected-detail" id="movie-selected-detail" hidden></article>
        </div>

        <div class="content-section" id="tv-section"<?= $bannerContentType !== 'tv' ? ' hidden' : '' ?>>
            <div class="section-heading">
                <div><h2>Lançamentos de séries</h2><p>Escolha uma série com estreia nesta semana.</p></div>
            </div>
            <button class="generator-button secondary-button load-media" type="button" data-media-type="tv">Carregar séries</button>
            <div class="content-results media-results" id="tv-results" aria-live="polite"></div>
            <article class="media-selected-detail" id="tv-selected-detail" hidden></article>
        </div>
    </section>

    <section class="generator-panel background-panel football-only-panel" aria-label="Imagem de fundo"<?= $bannerContentType !== 'football' ? ' hidden' : '' ?>>
        <div class="section-heading">
            <div>
                <span class="step-label">PERSONALIZE A ARTE</span>
                <h2>Imagem de fundo</h2>
                <p>Usa o fundo cadastrado na conta em todos os modelos. Se quiser, envie outra imagem para as próximas artes.</p>
            </div>
        </div>
        <div class="background-controls">
            <label class="background-file-field" for="banner-background">
                <span>Enviar imagem de fundo</span>
                <input id="banner-background" type="file" accept="image/png,image/jpeg,image/webp">
                <small>PNG, JPG ou WebP · até 5 MB. O fundo fica salvo na conta até ser removido ou substituído.</small>
            </label>
            <div class="background-preview" id="background-preview" hidden>
                <img id="background-preview-image" alt="Prévia da imagem de fundo">
                <button class="text-button" id="clear-background" type="button">Remover imagem</button>
            </div>
        </div>
    </section>

    <section class="generator-panel model-panel football-only-panel" aria-label="Modelos de banner"<?= $bannerContentType !== 'football' ? ' hidden' : '' ?>>
        <div class="section-heading">
            <div><span class="step-label">PRÓXIMO PASSO</span><h2>Escolha o modelo do banner</h2><p>O modelo Estádio usa até cinco cartões em uma coluna, no tamanho padrão dos outros banners e no estilo da referência.</p></div>
        </div>
        <div class="model-options">
            <label class="model-option is-selected">
                <input type="radio" name="banner-model" value="lista" checked>
                <span class="model-preview preview-light" aria-hidden="true">
                    <span class="mock-top"><i></i><b>JOGOS DE HOJE</b><small>AO VIVO</small></span>
                    <span class="mock-match"><b class="mock-league">LIGA A</b><span class="mock-team"><i>FC</i><b>TIME DA CASA</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>SC</i><b>TIME VISITANTE</b></span><span class="mock-match-actions"><b>◷ 15:00</b><small>▣</small></span></span>
                    <span class="mock-match"><b class="mock-league">LIGA B</b><span class="mock-team"><i>AC</i><b>CLUBE AZUL</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>EC</i><b>CLUBE VERDE</b></span><span class="mock-match-actions"><b>◷ 17:30</b><small>▣</small></span></span>
                    <span class="mock-match"><b class="mock-league">LIGA C</b><span class="mock-team"><i>RJ</i><b>RIO FC</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>SP</i><b>SAO FC</b></span><span class="mock-match-actions"><b>◷ 20:00</b><small>▣</small></span></span>
                </span>
                <span class="model-option-copy"><strong>Jogos em destaque</strong><small>Cartões de partidas com horário e canais</small></span>
                <span class="model-check" aria-hidden="true">✓</span>
            </label>
            <label class="model-option">
                <input type="radio" name="banner-model" value="noturna">
                <span class="model-preview preview-night" aria-hidden="true">
                    <span class="mock-top"><i></i><b>JOGOS DE HOJE</b><small>AO VIVO</small></span>
                    <span class="mock-match"><b class="mock-league">LIGA A</b><span class="mock-team"><i>FC</i><b>TIME DA CASA</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>SC</i><b>TIME VISITANTE</b></span><span class="mock-match-actions"><b>◷ 15:00</b><small>▣</small></span></span>
                    <span class="mock-match"><b class="mock-league">LIGA B</b><span class="mock-team"><i>AC</i><b>CLUBE AZUL</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>EC</i><b>CLUBE VERDE</b></span><span class="mock-match-actions"><b>◷ 17:30</b><small>▣</small></span></span>
                    <span class="mock-match"><b class="mock-league">LIGA C</b><span class="mock-team"><i>RJ</i><b>RIO FC</b></span><b class="mock-versus">VS</b><span class="mock-team"><i>SP</i><b>SAO FC</b></span><span class="mock-match-actions"><b>◷ 20:00</b><small>▣</small></span></span>
                </span>
                <span class="model-option-copy"><strong>Jogos em destaque noturno</strong><small>Mesmo layout com fundo escuro e neon</small></span>
                <span class="model-check" aria-hidden="true">✓</span>
            </label>
            <label class="model-option">
                <input type="radio" name="banner-model" value="stadium">
                <span class="model-preview preview-stadium" aria-hidden="true">
                    <span class="stadium-preview-title"><b>JOGOS DO DIA</b><strong>FUTEBOL</strong><small>HOJE</small></span>
                    <span class="stadium-preview-match"><i>◉</i><b>FC</b><b>SC</b><small>18:00</small></span>
                    <span class="stadium-preview-match"><i>◉</i><b>AC</b><b>EC</b><small>20:30</small></span>
                    <span class="stadium-preview-match"><i>◉</i><b>RJ</b><b>SP</b><small>21:00</small></span>
                    <span class="stadium-preview-match"><i>◉</i><b>BR</b><b>PT</b><small>22:00</small></span>
                    <span class="stadium-preview-match"><i>◉</i><b>PA</b><b>MG</b><small>22:30</small></span>
                </span>
                <span class="model-option-copy"><strong>Estádio · até 5 jogos</strong><small>Cartões em uma coluna</small></span>
                <span class="model-check" aria-hidden="true">✓</span>
            </label>
            <label class="model-option">
                <input type="radio" name="banner-model" value="headline">
                <span class="model-preview preview-headline" aria-hidden="true">
                    <span class="headline-preview-tag">PROGRAMAÇÃO DE</span>
                    <strong>FU<span>⚽</span>TE<br>BOL</strong>
                    <small>DO DIA · 10 OUT</small>
                    <i class="headline-preview-brand">SUA LOGO</i>
                </span>
                <span class="model-option-copy"><strong>Futebol · chamada gigante</strong><small>Gera a arte sem precisar carregar partidas</small></span>
                <span class="model-check" aria-hidden="true">✓</span>
            </label>
        </div>
    </section>

    <section class="generator-panel content-settings-panel" aria-label="Detalhes do banner">
        <div class="section-heading">
            <div><span class="step-label">FINALIZE SUA ARTE</span><h2>Detalhes do banner</h2><p>O modelo Chamada gigante não precisa de partidas; os demais geram banners com até cinco jogos por imagem.</p></div>
        </div>
        <label class="banner-title-field" for="banner-title">Título da arte
            <input id="banner-title" type="text" maxlength="38" value="<?= $escape($bannerInitialTitle) ?>">
        </label>
        <label class="banner-title-field media-phone-field" for="banner-phone">Telefone para contato (obrigatório para filmes e séries; opcional para futebol)
            <input id="banner-phone" type="tel" inputmode="tel" maxlength="24" placeholder="Ex.: (11) 99999-9999">
        </label>
        <button class="generator-button generate-button" id="generate-banner" type="button" disabled>Gerar banners <span aria-hidden="true">→</span></button>
    </section>

    </div>
    <section class="generator-panel generated-panel" id="generated-panel" hidden>
        <div class="section-heading">
            <div><span class="step-label">ARTES PRONTAS</span><h2>Seus banners estão prontos</h2><p id="generated-description">Baixe e compartilhe suas artes.</p></div>
        </div>
        <div class="generated-share-panel" aria-label="Compartilhar banners">
            <div>
                <strong>Compartilhe suas artes</strong>
                <p>Use o menu do celular para escolher Instagram Stories ou WhatsApp. Em computadores, baixe as imagens para publicar.</p>
            </div>
            <div class="generated-share-actions">
                <button class="generator-button share-button" id="share-all-banners" type="button">Compartilhar todos</button>
                <button class="generator-button share-button share-instagram-button" id="share-instagram-stories" type="button">Instagram Stories</button>
                <button class="generator-button share-button share-whatsapp-button" id="share-whatsapp" type="button">WhatsApp</button>
                <button class="generator-button secondary-button share-button" id="download-all-banners" type="button">Baixar todos</button>
            </div>
            <p class="share-feedback" id="share-feedback" role="status" aria-live="polite" hidden></p>
        </div>
        <div class="generated-results" id="generated-results"></div>
        <button class="generator-button secondary-button back-to-generator" id="back-to-generator" type="button">Voltar ao gerador</button>
    </section>
</main>

<div class="generation-overlay" id="generation-overlay" hidden>
    <div class="generation-dialog" role="status" aria-live="polite" aria-label="Gerando banner">
        <div class="progress-ring" id="progress-ring"><strong id="progress-value">0%</strong></div>
        <div class="generation-progress-copy">
            <strong id="progress-title">Preparando o banner...</strong>
            <span id="progress-detail">Aguarde enquanto sua arte é montada.</span>
        </div>
        <div class="progress-track"><span id="progress-bar"></span></div>
    </div>
</div>

<script>
window.bannerGeneratorConfig = <?= json_encode([
    'apiUrl' => 'api.php',
    'logoUrl' => $generatorLogoUrl,
    'backgroundUrl' => getAccountBannerBackgroundUrl($db, $generatorAccountId),
    'backgroundCsrf' => $_SESSION['banner_background_csrf'],
    'contentType' => $bannerContentType,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= $escape($scriptPath) ?>?v=banner-generator-20261027-01"></script>
</body>
</html>
