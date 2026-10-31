(function () {
    const config = window.bannerGeneratorConfig || {};
    const apiUrl = config.apiUrl || 'api.php';
    const contentTabs = Array.from(document.querySelectorAll('.content-tab'));
    const sections = {
        football: document.getElementById('football-section'),
        movie: document.getElementById('movie-section'),
        tv: document.getElementById('tv-section')
    };
    const message = document.getElementById('generator-message');
    const generateButton = document.getElementById('generate-banner');
    const overlay = document.getElementById('generation-overlay');
    const progressRing = document.getElementById('progress-ring');
    const progressValue = document.getElementById('progress-value');
    const progressBar = document.getElementById('progress-bar');
    const progressTitle = document.getElementById('progress-title');
    const progressDetail = document.getElementById('progress-detail');
    const footballResults = document.getElementById('football-results');
    const footballToolbar = document.getElementById('football-toolbar');
    const generatedPanel = document.getElementById('generated-panel');
    const generatedResults = document.getElementById('generated-results');
    const generatorForm = document.getElementById('generator-form');
    const shareFeedback = document.getElementById('share-feedback');
    const backgroundInput = document.getElementById('banner-background');
    const backgroundPreview = document.getElementById('background-preview');
    const backgroundPreviewImage = document.getElementById('background-preview-image');
    const phoneField = document.querySelector('.media-phone-field');
    const colorVariants = [
        { key: 'verde', label: 'Verde', accent: '#08a85b', dark: '#075b37', soft: '#c9f4dc' },
        { key: 'amarelo', label: 'Amarelo', accent: '#e9ad00', dark: '#765400', soft: '#fff0b3' },
        { key: 'vermelho', label: 'Vermelho', accent: '#e53935', dark: '#801e24', soft: '#ffd8d5' },
        { key: 'azul', label: 'Azul', accent: '#315fda', dark: '#142c70', soft: '#d6e3ff' }
    ];
    const nightColorVariants = [
        { key: 'roxo', label: 'Roxo', accent: '#7547f6', dark: '#382079', soft: '#e4dcff' },
        { key: 'preto', label: 'Preto', accent: '#353943', dark: '#111318', soft: '#d8dbe2' },
        { key: 'lilas', label: 'Lilás', accent: '#b66cf2', dark: '#60358b', soft: '#f0ddff' },
        { key: 'azul-claro', label: 'Azul-claro', accent: '#56bff5', dark: '#1c5c88', soft: '#d8f1ff' }
    ];
    let contentType = ['football', 'movie', 'tv'].includes(config.contentType) ? config.contentType : 'football';
    let fixtures = [];
    let mediaItems = [];
    let selectedMediaIndex = null;
    let accountLogoUrl = config.logoUrl || '';
    let generatedObjectUrls = [];
    let generatedBannerFiles = [];
    let backgroundObjectUrl = '';
    let defaultBackgroundUrl = config.backgroundUrl || '';
    let backgroundCsrf = config.backgroundCsrf || '';
    let footballLoadPromise = null;

    if (defaultBackgroundUrl) {
        backgroundPreviewImage.src = defaultBackgroundUrl;
        backgroundPreview.hidden = false;
    }

    function showMessage(text, isError) {
        message.textContent = text;
        message.classList.toggle('is-error', Boolean(isError));
        message.hidden = !text;
    }

    async function updateSavedBackground(action, file) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', backgroundCsrf);
        if (file) formData.append('background_image', file);

        const response = await fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new Error('O servidor não retornou uma resposta válida ao salvar a imagem.');
        }
        if (!response.ok) {
            throw new Error(payload.error || 'Não foi possível atualizar o fundo salvo.');
        }
        if (typeof payload.background !== 'string' || typeof payload.csrfToken !== 'string') {
            throw new Error('O servidor retornou uma resposta inválida ao atualizar o fundo.');
        }
        backgroundCsrf = payload.csrfToken;
        defaultBackgroundUrl = payload.background;
        return payload.background;
    }

    function setProgress(percent, title, detail) {
        const bounded = Math.max(0, Math.min(100, Math.round(percent)));
        progressValue.textContent = bounded + '%';
        progressBar.style.width = bounded + '%';
        progressRing.style.setProperty('--progress', (bounded * 3.6) + 'deg');
        progressTitle.textContent = title;
        progressDetail.textContent = detail;
    }

    function startLoading(title, detail) {
        setProgress(0, title, detail);
        overlay.hidden = false;
        document.body.classList.add('is-generating-banner');
    }

    function stopLoading() {
        overlay.hidden = true;
        document.body.classList.remove('is-generating-banner');
    }

    function sleep(milliseconds) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, milliseconds);
        });
    }

    async function postData(data) {
        const controller = new AbortController();
        const timeout = window.setTimeout(function () {
            controller.abort();
        }, 25000);
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(data),
                signal: controller.signal
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.error || 'Não foi possível carregar os dados.');
            }
            return payload;
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('A consulta demorou demais. Tente novamente.');
            }
            throw error;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    function updateGenerateButton() {
        let hasSelection = false;
        if (contentType === 'football') {
            const selectedModel = document.querySelector('input[name="banner-model"]:checked');
            const selectedCount = footballResults.querySelectorAll('input[name="fixture"]:checked').length;
            const modelDoesNotRequireSelection = selectedModel && selectedModel.value === 'headline';
            const groupSize = 5;
            hasSelection = Boolean(modelDoesNotRequireSelection) || selectedCount > 0;
            generateButton.innerHTML = selectedModel && selectedModel.value === 'stadium'
                ? selectedCount > groupSize
                    ? 'Gerar todos os banners (' + Math.ceil(selectedCount / groupSize) + ' grupos de até 5) <span aria-hidden="true">→</span>'
                    : 'Gerar banner com até 5 jogos <span aria-hidden="true">→</span>'
                : !modelDoesNotRequireSelection && selectedCount > groupSize
                ? 'Gerar todos os banners (' + Math.ceil(selectedCount / groupSize) + ' grupos) <span aria-hidden="true">→</span>'
                : 'Gerar banners <span aria-hidden="true">→</span>';
        } else {
            hasSelection = selectedMediaIndex !== null && document.getElementById('banner-phone').value.trim() !== '';
            generateButton.innerHTML = 'Gerar banner <span aria-hidden="true">→</span>';
        }
        generateButton.disabled = !hasSelection || accountLogoUrl === '';
    }

    function updateFootballSelectionCount() {
        const selectedCount = footballResults.querySelectorAll('input[name="fixture"]:checked').length;
        document.getElementById('football-selection-count').textContent =
            selectedCount + (selectedCount === 1 ? ' partida selecionada' : ' partidas selecionadas');
        updateGenerateButton();
    }

    function selectAllFootballFixtures() {
        footballResults.querySelectorAll('input[name="fixture"]').forEach(function (checkbox) {
            checkbox.checked = true;
        });
        updateFootballSelectionCount();
    }

    function updateFootballSelectionVisibility() {
        const selectedModel = document.querySelector('input[name="banner-model"]:checked');
        sections.football.hidden = contentType !== 'football'
            || Boolean(selectedModel && selectedModel.value === 'headline');
    }

    function setAccountLogo(url) {
        accountLogoUrl = typeof url === 'string' ? url : '';
        const brand = document.querySelector('.generator-brand');
        if (accountLogoUrl !== '') {
            brand.innerHTML = '<img src="" alt="Logo da sua conta">';
            brand.firstElementChild.src = accountLogoUrl;
        } else {
            brand.textContent = 'Cadastre uma logo para gerar banners';
            showMessage('Cadastre uma logo na identidade da sua conta para gerar banners personalizados.', false);
        }
        updateGenerateButton();
    }

    function setContentType(type) {
        if (type !== contentType) {
            fixtures = [];
            mediaItems = [];
            selectedMediaIndex = null;
            footballResults.replaceChildren();
            document.getElementById('movie-results').replaceChildren();
            document.getElementById('tv-results').replaceChildren();
            document.getElementById('movie-selected-detail').hidden = true;
            document.getElementById('tv-selected-detail').hidden = true;
            footballToolbar.hidden = true;
            generatedPanel.hidden = true;
            generatedResults.replaceChildren();
            generatedObjectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
            generatedObjectUrls = [];
            generatedBannerFiles = [];
            shareFeedback.hidden = true;
        }
        contentType = type;
        contentTabs.forEach(function (tab) {
            const isActive = tab.dataset.contentType === type;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', String(isActive));
        });
        Object.keys(sections).forEach(function (key) {
            sections[key].hidden = key !== type;
        });
        document.querySelectorAll('.football-only-panel').forEach(function (panel) {
            panel.hidden = type !== 'football';
        });
        updateFootballSelectionVisibility();
        document.querySelector('.background-panel').hidden = type !== 'football';
        document.getElementById('banner-title').value = type === 'football'
            ? 'JOGOS DO DIA'
            : type === 'movie' ? 'DESTAQUE DE FILME' : 'DESTAQUE DE SÉRIE';
        phoneField.hidden = false;
        showMessage('', false);
        updateGenerateButton();
    }

    function createFixtureLogo(url, alt, className, loadImmediately) {
        if (typeof url !== 'string' || url === '') return null;
        const image = document.createElement('img');
        image.className = className;
        image.src = url;
        image.alt = alt;
        image.loading = loadImmediately ? 'eager' : 'lazy';
        image.decoding = 'async';
        image.addEventListener('error', function () {
            image.hidden = true;
        }, { once: true });
        return image;
    }

    function renderFixtures() {
        footballResults.replaceChildren();
        if (fixtures.length === 0) {
            footballResults.innerHTML = '<p class="results-empty">Não há partidas disponíveis para esta data.</p>';
            footballToolbar.hidden = true;
            updateGenerateButton();
            return;
        }

        fixtures.forEach(function (fixture, index) {
            const option = document.createElement('div');
            option.className = 'fixture-option';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'fixture';
            checkbox.value = String(index);
            checkbox.setAttribute('aria-label', 'Selecionar ' + fixture.home + ' contra ' + fixture.away);
            checkbox.addEventListener('change', function () {
                showMessage('', false);
                updateFootballSelectionCount();
            });
            const info = document.createElement('span');
            info.className = 'fixture-info';
            const league = document.createElement('span');
            league.className = 'fixture-league';
            const loadImmediately = index < 12;
            const leagueLogo = createFixtureLogo(fixture.league_logo, 'Logo ' + (fixture.league || 'da liga'), 'fixture-league-logo', loadImmediately);
            if (leagueLogo) league.append(leagueLogo);
            const leagueName = document.createElement('span');
            leagueName.textContent = fixture.league || 'Futebol';
            league.append(leagueName);
            const teams = document.createElement('span');
            teams.className = 'fixture-teams';
            const home = document.createElement('span');
            home.className = 'fixture-team';
            const homeLogo = createFixtureLogo(fixture.home_logo, 'Escudo ' + fixture.home, 'fixture-team-logo', loadImmediately);
            if (homeLogo) home.append(homeLogo);
            const homeName = document.createElement('span');
            homeName.textContent = fixture.home;
            home.append(homeName);
            const versus = document.createElement('span');
            versus.className = 'fixture-versus';
            versus.textContent = '×';
            const away = document.createElement('span');
            away.className = 'fixture-team';
            const awayLogo = createFixtureLogo(fixture.away_logo, 'Escudo ' + fixture.away, 'fixture-team-logo', loadImmediately);
            if (awayLogo) away.append(awayLogo);
            const awayName = document.createElement('span');
            awayName.textContent = fixture.away;
            away.append(awayName);
            teams.append(home, versus, away);
            const meta = document.createElement('span');
            meta.className = 'fixture-meta';
            const time = document.createElement('span');
            time.textContent = fixture.time || '--:--';
            const status = document.createElement('span');
            status.textContent = fixture.status || '';
            meta.append(time, status);
            info.append(league, teams, meta);
            const channelLabel = document.createElement('label');
            channelLabel.className = 'fixture-channel-field';
            channelLabel.textContent = 'Transmissão';
            const channelInput = document.createElement('input');
            channelInput.type = 'text';
            channelInput.maxLength = 32;
            channelInput.value = typeof fixture.channel === 'string' ? fixture.channel : '';
            channelInput.placeholder = 'Informar canal';
            channelInput.setAttribute('aria-label', 'Canal ou plataforma de ' + fixture.home + ' contra ' + fixture.away);
            channelInput.addEventListener('input', function () {
                fixtures[index].channel = channelInput.value.trim();
            });
            channelLabel.append(channelInput);
            option.append(checkbox, info, channelLabel);
            footballResults.append(option);
        });

        footballToolbar.hidden = false;
        updateFootballSelectionCount();
        updateGenerateButton();
    }

    function renderMediaItems() {
        const target = document.getElementById(contentType + '-results');
        const detail = document.getElementById(contentType + '-selected-detail');
        target.replaceChildren();
        detail.replaceChildren();
        detail.hidden = true;
        mediaItems.forEach(function (item, index) {
            const button = document.createElement('button');
            button.className = 'media-option';
            button.type = 'button';
            button.dataset.index = String(index);
            button.setAttribute('aria-pressed', 'false');
            const image = document.createElement('img');
            image.src = item.image;
            image.alt = '';
            image.loading = 'lazy';
            const text = document.createElement('span');
            const title = document.createElement('strong');
            title.textContent = item.title;
            const meta = document.createElement('small');
            meta.textContent = [item.type === 'tv' ? 'Série' : 'Filme', item.year].filter(Boolean).join(' · ');
            text.append(title, meta);
            button.append(image, text);
            button.addEventListener('click', function () {
                selectedMediaIndex = index;
                target.querySelectorAll('.media-option').forEach(function (option) {
                    const selected = option === button;
                    option.classList.toggle('is-selected', selected);
                    option.setAttribute('aria-pressed', String(selected));
                    option.hidden = !selected;
                });
                renderSelectedMediaDetail(detail, item, target);
                showMessage('', false);
                updateGenerateButton();
            });
            target.append(button);
        });
        if (mediaItems.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'results-empty';
            empty.textContent = 'Não há lançamentos nesta semana com pôster e sinopse disponíveis.';
            target.append(empty);
        }
        updateGenerateButton();
    }

    function renderSelectedMediaDetail(target, item, results) {
        target.replaceChildren();
        const heading = document.createElement('h3');
        heading.textContent = 'Selecionado para o banner';
        const title = document.createElement('strong');
        title.textContent = item.title;
        const metadata = document.createElement('small');
        metadata.textContent = [item.type === 'tv' ? 'Série' : 'Filme', item.year, item.release_date]
            .filter(Boolean)
            .join(' · ');
        if (item.overview_language === 'en-US') {
            metadata.textContent += ' · Sinopse original em inglês';
        }
        const overview = document.createElement('p');
        overview.textContent = item.overview || 'Sinopse não informada pelo TMDB.';
        const changeSelection = document.createElement('button');
        changeSelection.className = 'text-button selected-media-change';
        changeSelection.type = 'button';
        changeSelection.textContent = 'Escolher outro título';
        changeSelection.addEventListener('click', function () {
            results.querySelectorAll('.media-option').forEach(function (option) {
                option.hidden = false;
                option.classList.remove('is-selected');
                option.setAttribute('aria-pressed', 'false');
            });
            selectedMediaIndex = null;
            target.hidden = true;
            updateGenerateButton();
        });
        target.append(heading, title, metadata, overview, changeSelection);
        target.hidden = false;
    }

    document.querySelectorAll('.model-option input').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.model-option').forEach(function (option) {
                option.classList.toggle('is-selected', option.contains(radio) && radio.checked);
            });
            updateFootballSelectionVisibility();
            updateGenerateButton();
            if (radio.checked && contentType === 'football') {
                if (radio.value === 'headline') return;
                if (fixtures.length === 0) {
                    loadFootballFixtures(true);
                } else if (footballResults.querySelectorAll('input[name="fixture"]:checked').length === 0) {
                    selectAllFootballFixtures();
                }
            }
        });
    });

    document.getElementById('banner-phone').addEventListener('input', updateGenerateButton);

    contentTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            setContentType(tab.dataset.contentType);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    backgroundInput.addEventListener('change', async function () {
        const file = backgroundInput.files && backgroundInput.files[0];
        if (!file) return;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
            backgroundInput.value = '';
            showMessage('Escolha uma imagem PNG, JPG ou WebP.', true);
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            backgroundInput.value = '';
            showMessage('A imagem de fundo deve ter no máximo 5 MB.', true);
            return;
        }

        const nextObjectUrl = URL.createObjectURL(file);
        try {
            const image = await loadImage(nextObjectUrl);
            if (image.width * image.height > 40000000) {
                throw new Error('A imagem de fundo excede o limite de 40 megapixels.');
            }
            backgroundPreviewImage.src = nextObjectUrl;
            backgroundPreview.hidden = false;
            showMessage('Salvando o fundo na sua conta...', false);
            const savedUrl = await updateSavedBackground('save_background', file);
            if (backgroundObjectUrl) URL.revokeObjectURL(backgroundObjectUrl);
            backgroundObjectUrl = '';
            backgroundPreviewImage.src = savedUrl;
            showMessage('', false);
        } catch (error) {
            URL.revokeObjectURL(nextObjectUrl);
            backgroundPreviewImage.src = defaultBackgroundUrl;
            backgroundPreview.hidden = defaultBackgroundUrl === '';
            backgroundInput.value = '';
            showMessage(error.message || 'Não foi possível abrir a imagem de fundo.', true);
        }
    });

    document.getElementById('clear-background').addEventListener('click', async function () {
        const button = this;
        button.disabled = true;
        showMessage('Removendo o fundo salvo...', false);
        try {
            const fallbackUrl = await updateSavedBackground('remove_background');
            if (backgroundObjectUrl) {
                URL.revokeObjectURL(backgroundObjectUrl);
                backgroundObjectUrl = '';
            }
            backgroundInput.value = '';
            backgroundPreviewImage.src = fallbackUrl;
            backgroundPreview.hidden = fallbackUrl === '';
            showMessage(fallbackUrl ? 'Fundo personalizado removido; o fundo padrão da conta continua ativo.' : '', false);
        } catch (error) {
            showMessage(error.message || 'Não foi possível remover o fundo salvo.', true);
        } finally {
            button.disabled = false;
        }
    });

    async function requestFootballFixtures(selectAll) {
        const button = document.getElementById('load-football');
        button.disabled = true;
        showMessage('', false);
        startLoading('Carregando partidas...', 'Consultando os jogos reais da API-Sports.');
        setProgress(24, 'Carregando partidas...', 'Consultando os jogos reais da API-Sports.');
        try {
            const payload = await postData({
                action: 'football',
                date: document.getElementById('football-date').value
            });
            fixtures = Array.isArray(payload.fixtures) ? payload.fixtures : [];
            setAccountLogo(payload.logo);
            renderFixtures();
            if (selectAll && fixtures.length > 0) {
                selectAllFootballFixtures();
            }
            setProgress(100, 'Partidas carregadas', fixtures.length + ' jogos disponíveis.');
            await sleep(220);
            stopLoading();
            if (fixtures.length === 0) {
                showMessage('A API-Sports não informou partidas brasileiras para a data escolhida.', false);
            }
        } catch (error) {
            stopLoading();
            showMessage(error.message || 'Não foi possível carregar as partidas.', true);
        } finally {
            button.disabled = false;
        }
    }

    async function loadFootballFixtures(selectAll) {
        if (footballLoadPromise) {
            await footballLoadPromise;
            if (selectAll && fixtures.length > 0) {
                selectAllFootballFixtures();
            }
            return;
        }

        footballLoadPromise = requestFootballFixtures(selectAll);
        try {
            await footballLoadPromise;
        } finally {
            footballLoadPromise = null;
        }
    }

    document.getElementById('load-football').addEventListener('click', function () {
        loadFootballFixtures(true);
    });

    document.querySelectorAll('.load-media').forEach(function (button) {
        button.addEventListener('click', async function () {
            const type = button.dataset.mediaType;
            setContentType(type);
            mediaItems = [];
            selectedMediaIndex = null;
            document.getElementById(type + '-results').replaceChildren();
            document.getElementById(type + '-selected-detail').hidden = true;
            updateGenerateButton();
            button.disabled = true;
            showMessage('', false);
            startLoading('Carregando catálogo...', 'Buscando os lançamentos no TMDB.');
            setProgress(24, 'Carregando catálogo...', 'Buscando os lançamentos no TMDB.');
            try {
                const payload = await postData({ action: 'catalog', type: type });
                mediaItems = Array.isArray(payload.items) ? payload.items : [];
                selectedMediaIndex = null;
                setAccountLogo(payload.logo);
                renderMediaItems();
                setProgress(100, 'Catálogo carregado', mediaItems.length + ' títulos disponíveis.');
                await sleep(220);
                stopLoading();
                if (mediaItems.length === 0) {
                    showMessage('Não há títulos disponíveis para esta categoria no TMDB.', false);
                }
            } catch (error) {
                stopLoading();
                showMessage(error.message || 'Não foi possível carregar o catálogo TMDB.', true);
            } finally {
                button.disabled = false;
            }
        });
    });

    document.getElementById('select-football').addEventListener('click', function () {
        selectAllFootballFixtures();
    });

    document.getElementById('clear-football').addEventListener('click', function () {
        footballResults.querySelectorAll('input[name="fixture"]').forEach(function (checkbox) {
            checkbox.checked = false;
        });
        updateFootballSelectionCount();
    });

    const bannerScale = 3;
    const bannerWidth = 1080;
    const bannerHeight = 1350;

    function createCanvas() {
        const canvas = document.createElement('canvas');
        canvas.width = bannerWidth * bannerScale;
        canvas.height = bannerHeight * bannerScale;
        return canvas;
    }

    function roundedRect(context, x, y, width, height, radius, color) {
        context.fillStyle = color;
        context.beginPath();
        context.roundRect(x, y, width, height, radius);
        context.fill();
    }

    function coverImage(context, image, x, y, width, height) {
        const scale = Math.max(width / image.width, height / image.height);
        const sourceWidth = width / scale;
        const sourceHeight = height / scale;
        const sourceX = (image.width - sourceWidth) / 2;
        const sourceY = (image.height - sourceHeight) / 2;
        context.drawImage(image, sourceX, sourceY, sourceWidth, sourceHeight, x, y, width, height);
    }

    function fitImage(context, image, x, y, maxWidth, maxHeight) {
        if (!image || image.width <= 0 || image.height <= 0 || maxWidth <= 0 || maxHeight <= 0) {
            return;
        }
        const scale = Math.min(maxWidth / image.width, maxHeight / image.height);
        const width = image.width * scale;
        const height = image.height * scale;
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(image, x + (maxWidth - width) / 2, y + (maxHeight - height) / 2, width, height);
    }

    const logoBoundsCache = new WeakMap();

    function getLogoBounds(image) {
        const imageWidth = image && (image.naturalWidth || image.width);
        const imageHeight = image && (image.naturalHeight || image.height);
        if (!Number.isFinite(imageWidth)
            || !Number.isFinite(imageHeight)
            || imageWidth <= 0
            || imageHeight <= 0) {
            return null;
        }
        if (logoBoundsCache.has(image)) {
            return logoBoundsCache.get(image);
        }

        const canvas = document.createElement('canvas');
        const scanScale = Math.min(1, 256 / Math.max(imageWidth, imageHeight));
        canvas.width = Math.max(1, Math.round(imageWidth * scanScale));
        canvas.height = Math.max(1, Math.round(imageHeight * scanScale));
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) {
            throw new Error('Não foi possível preparar a logo para o banner.');
        }
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const { data } = context.getImageData(0, 0, canvas.width, canvas.height);
        let left = canvas.width;
        let top = canvas.height;
        let right = -1;
        let bottom = -1;
        for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
                if (data[(y * canvas.width + x) * 4 + 3] > 32) {
                    left = Math.min(left, x);
                    top = Math.min(top, y);
                    right = Math.max(right, x);
                    bottom = Math.max(bottom, y);
                }
            }
        }

        if (right < left || bottom < top) {
            const fullImageBounds = { x: 0, y: 0, width: imageWidth, height: imageHeight };
            logoBoundsCache.set(image, fullImageBounds);
            return fullImageBounds;
        }

        const paddingX = Math.round((right - left + 1) * 0.04);
        const paddingY = Math.round((bottom - top + 1) * 0.04);
        const sourceLeft = Math.max(0, left - paddingX) * imageWidth / canvas.width;
        const sourceTop = Math.max(0, top - paddingY) * imageHeight / canvas.height;
        const sourceRight = Math.min(canvas.width, right + paddingX + 1) * imageWidth / canvas.width;
        const sourceBottom = Math.min(canvas.height, bottom + paddingY + 1) * imageHeight / canvas.height;
        const bounds = {
            x: Math.floor(sourceLeft),
            y: Math.floor(sourceTop),
            width: Math.min(imageWidth, Math.ceil(sourceRight)) - Math.floor(sourceLeft),
            height: Math.min(imageHeight, Math.ceil(sourceBottom)) - Math.floor(sourceTop)
        };
        logoBoundsCache.set(image, bounds);
        return bounds;
    }

    function fitLogoImage(context, image, x, y, maxWidth, maxHeight) {
        const bounds = getLogoBounds(image);
        if (!bounds || bounds.width <= 0 || bounds.height <= 0) {
            return;
        }
        const scale = Math.min(maxWidth / bounds.width, maxHeight / bounds.height);
        const width = bounds.width * scale;
        const height = bounds.height * scale;
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(
            image,
            bounds.x, bounds.y, bounds.width, bounds.height,
            x + (maxWidth - width) / 2, y + (maxHeight - height) / 2, width, height
        );
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            const image = new Image();
            image.onload = function () {
                if (!Number.isFinite(image.naturalWidth)
                    || !Number.isFinite(image.naturalHeight)
                    || image.naturalWidth <= 0
                    || image.naturalHeight <= 0) {
                    reject(new Error('A imagem carregada não contém pixels válidos para o banner.'));
                    return;
                }
                resolve(image);
            };
            image.onerror = function () {
                reject(new Error('Não foi possível carregar uma imagem necessária para o banner.'));
            };
            image.src = url;
        });
    }

    function loadOptionalImage(url) {
        if (typeof url !== 'string' || url === '') return Promise.resolve(null);
        return loadImage(url).catch(function () { return null; });
    }

    function removeLightLogoBackground(image) {
        if (!image
            || !Number.isFinite(image.naturalWidth)
            || !Number.isFinite(image.naturalHeight)
            || image.naturalWidth <= 0
            || image.naturalHeight <= 0) return null;

        const scale = Math.min(1, 512 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) {
            throw new Error('Não foi possível preparar os escudos para o banner.');
        }
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
        const pixels = imageData.data;
        const pixelCount = canvas.width * canvas.height;
        const visited = new Uint8Array(pixelCount);
        const queue = new Uint32Array(pixelCount);
        let queueStart = 0;
        let queueEnd = 0;

        function isLightBackground(index) {
            const offset = index * 4;
            const red = pixels[offset];
            const green = pixels[offset + 1];
            const blue = pixels[offset + 2];
            return pixels[offset + 3] === 0
                || (Math.min(red, green, blue) >= 205
                    && Math.max(red, green, blue) - Math.min(red, green, blue) <= 38);
        }

        function addBackgroundPixel(index) {
            if (visited[index] || !isLightBackground(index)) return;
            visited[index] = 1;
            queue[queueEnd++] = index;
        }

        for (let x = 0; x < canvas.width; x++) {
            addBackgroundPixel(x);
            addBackgroundPixel((canvas.height - 1) * canvas.width + x);
        }
        for (let y = 0; y < canvas.height; y++) {
            addBackgroundPixel(y * canvas.width);
            addBackgroundPixel(y * canvas.width + canvas.width - 1);
        }

        while (queueStart < queueEnd) {
            const index = queue[queueStart++];
            const offset = index * 4;
            if (pixels[offset + 3] > 0) {
                const lightestChannel = Math.min(pixels[offset], pixels[offset + 1], pixels[offset + 2]);
                pixels[offset + 3] = Math.min(255, Math.max(0, (245 - lightestChannel) * (255 / 40)));
            }
            const x = index % canvas.width;
            const y = Math.floor(index / canvas.width);
            if (x > 0) addBackgroundPixel(index - 1);
            if (x + 1 < canvas.width) addBackgroundPixel(index + 1);
            if (y > 0) addBackgroundPixel(index - canvas.width);
            if (y + 1 < canvas.height) addBackgroundPixel(index + canvas.width);
        }

        context.putImageData(imageData, 0, 0);
        return canvas;
    }

    function drawBackground(context, image, night, fallbackColors) {
        if (image) {
            coverImage(context, image, 0, 0, 1080, 1350);
            context.fillStyle = night ? 'rgba(5, 12, 24, .48)' : 'rgba(5, 12, 24, .34)';
            context.fillRect(0, 0, 1080, 1350);
            return;
        }
        const background = context.createLinearGradient(0, 0, 1080, 1350);
        background.addColorStop(0, fallbackColors[0]);
        background.addColorStop(1, fallbackColors[1]);
        context.fillStyle = background;
        context.fillRect(0, 0, 1080, 1350);
    }

    function wrapText(context, text, x, y, maxWidth, lineHeight, maxLines) {
        const words = String(text || '').trim().split(/\s+/).filter(Boolean);
        const lines = [];
        let line = '';
        words.forEach(function (word) {
            const candidate = line ? line + ' ' + word : word;
            if (line && context.measureText(candidate).width > maxWidth) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        });
        if (line) lines.push(line);
        const visibleLines = lines.slice(0, maxLines);
        if (lines.length > maxLines && visibleLines.length) {
            visibleLines[visibleLines.length - 1] = visibleLines[visibleLines.length - 1].replace(/[ .,]+$/, '') + '…';
        }
        visibleLines.forEach(function (value, index) {
            context.fillText(value, x, y + index * lineHeight);
        });
    }

    function drawLogo(context, image) {
        if (!image) return;
        fitLogoImage(context, image, 54, 24, 330, 155);
    }

    function drawFootball(context, games, fixtureImages, logo, night, palette, title, date, backgroundImage, phone, model) {
        if (model === 'stadium') {
            drawFootballStadium(context, games, fixtureImages, logo, palette, title, date, backgroundImage, phone);
            return;
        }
        if (model === 'headline') {
            drawFootballHeadline(context, games, fixtureImages, logo, palette, title, date, backgroundImage, phone);
            return;
        }

        drawBackground(context, backgroundImage, night, night
            ? ['#071421', '#142e3e']
            : [palette.soft, '#f7f9fd']);

        const accent = palette.accent;
        const foreground = night ? '#f1f5fb' : '#17243c';
        fitLogoImage(context, logo, 52, 25, 340, 150);
        roundedRect(context, 470, 34, 556, 142, 20, night ? 'rgba(7,16,31,.82)' : 'rgba(20,24,31,.72)');
        context.textAlign = 'center';
        context.fillStyle = '#ffffff';
        context.font = '900 55px Arial';
        context.fillText(formatBannerDate(date).weekday, 748, 96, 500);
        context.font = '900 45px Arial';
        context.fillText(formatBannerDate(date).day, 748, 148, 500);
        context.fillStyle = accent;
        context.font = '800 17px Arial';
        context.fillText(title.toUpperCase().slice(0, 34), 748, 166, 500);

        const gap = 12;
        const rowHeight = Math.min(190, Math.floor((960 - gap * (games.length - 1)) / games.length));
        const listHeight = games.length * rowHeight + (games.length - 1) * gap;
        const top = 205 + Math.round((960 - listHeight) / 2);
        games.forEach(function (game, index) {
            const y = top + index * (rowHeight + gap);
            const cardX = 40;
            const cardWidth = 1000;
            const leagueWidth = 190;
            const actionWidth = 230;
            roundedRect(context, cardX, y, cardWidth, rowHeight, 24, night ? 'rgba(8,18,31,.82)' : 'rgba(255,255,255,.82)');
            roundedRect(context, cardX, y, leagueWidth, rowHeight, 24, accent);
            context.fillStyle = accent;
            context.fillRect(cardX + leagueWidth - 24, y, 24, rowHeight);
            const leagueCenterX = cardX + leagueWidth / 2;
            const leagueLogo = fixtureImages[index] && fixtureImages[index].league;
            if (leagueLogo) {
                const leagueLogoHeight = Math.max(1, rowHeight - 48);
                fitImage(context, leagueLogo, cardX + 10, y + 5, leagueWidth - 20, leagueLogoHeight);
                context.textAlign = 'center';
                context.fillStyle = '#ffffff';
                context.font = '800 13px Arial';
                wrapText(context, String(game.league || 'LIGA').toUpperCase(), leagueCenterX, y + rowHeight - 32, leagueWidth - 18, 16, 2);
            } else {
                context.textAlign = 'center';
                context.fillStyle = '#ffffff';
                context.font = '800 17px Arial';
                wrapText(context, String(game.league || 'FUTEBOL').toUpperCase(), leagueCenterX, y + rowHeight / 2, leagueWidth - 34, 22, 3);
            }

            const contentLeft = cardX + leagueWidth + 12;
            const contentRight = cardX + cardWidth - actionWidth - 8;
            const versusX = (contentLeft + contentRight) / 2;
            const homeX = contentLeft + 106;
            const awayX = contentRight - 106;
            const logoSize = Math.min(166, rowHeight * .8);
            const logoY = y + Math.round(rowHeight * .04);
            const nameY = y + rowHeight - 13;
            drawTeamLogo(context, fixtureImages[index] && fixtureImages[index].home, homeX, logoY, logoSize, foreground);
            drawTeamLogo(context, fixtureImages[index] && fixtureImages[index].away, awayX, logoY, logoSize, foreground);
            context.fillStyle = foreground;
            context.font = '800 20px Arial';
            context.textAlign = 'center';
            context.fillText(String(game.home || 'MANDANTE').toUpperCase().slice(0, 24), homeX, nameY, 220);
            context.fillText(String(game.away || 'VISITANTE').toUpperCase().slice(0, 24), awayX, nameY, 220);
            context.fillStyle = accent;
            context.font = '900 60px Arial';
            context.fillText('VS', versusX, y + Math.round(rowHeight * .55));

            const actionX = cardX + cardWidth - actionWidth + 8;
            const pillWidth = actionWidth - 20;
            const controlGap = 8;
            const statusHeight = 30;
            const controlsHeight = 58 + controlGap + 52 + controlGap + statusHeight;
            const timeY = y + Math.max(6, Math.round((rowHeight - controlsHeight) / 2));
            roundedRect(context, actionX, timeY, pillWidth, 58, 29, accent);
            context.fillStyle = '#fff';
            context.textAlign = 'center';
            context.font = '800 36px Arial';
            context.fillText('◷ ' + String(game.time || '--:--'), actionX + pillWidth / 2, timeY + 40);
            const televisionY = timeY + 58 + controlGap;
            roundedRect(context, actionX, televisionY, pillWidth, 52, 26, accent);
            drawMonitorIcon(context, actionX + pillWidth / 2, televisionY + 26);
            const statusY = televisionY + 52 + controlGap;
            roundedRect(context, actionX, statusY, pillWidth, statusHeight, statusHeight / 2, accent);
            context.textAlign = 'center';
            context.fillStyle = '#fff';
            context.font = '700 12px Arial';
            const statusText = formatFixtureStatus(game);
            context.fillText(statusText, actionX + pillWidth / 2, statusY + 20, pillWidth - 12);
        });

        if (phone) {
            drawWhatsappFooter(context, phone);
        }
        context.textAlign = 'center';
        context.fillStyle = night ? '#e7edf5' : '#526078';
        context.font = '700 17px Arial';
        context.fillText('PARTIDAS E HORÁRIOS · ' + formatBannerDate(date).day, 540, phone ? 1324 : 1308);
    }

    function drawFootballStadium(context, games, fixtureImages, logo, palette, title, date, backgroundImage, phone) {
        const canvasHeight = 1350;
        if (backgroundImage) {
            coverImage(context, backgroundImage, 0, 0, 1080, canvasHeight);
            context.fillStyle = 'rgba(24, 12, 3, .48)';
        } else {
            const background = context.createLinearGradient(0, 0, 0, canvasHeight);
            background.addColorStop(0, '#130b05');
            background.addColorStop(.34, '#714116');
            background.addColorStop(.68, '#241308');
            background.addColorStop(1, '#050403');
            context.fillStyle = background;
        }
        context.fillRect(0, 0, 1080, canvasHeight);
        drawStadiumLights(context);

        const horizon = context.createLinearGradient(0, 950, 0, canvasHeight);
        horizon.addColorStop(0, 'rgba(0, 0, 0, 0)');
        horizon.addColorStop(.36, 'rgba(4, 3, 2, .5)');
        horizon.addColorStop(1, 'rgba(0, 0, 0, .92)');
        context.fillStyle = horizon;
        context.fillRect(0, 950, 1080, canvasHeight - 950);
        context.save();
        context.strokeStyle = 'rgba(255, 178, 61, .22)';
        context.lineWidth = 2;
        for (let index = 0; index < 9; index++) {
            const x = 170 + index * 92;
            context.beginPath();
            context.moveTo(x, canvasHeight);
            context.lineTo(540 + (x - 540) * .32, 950);
            context.stroke();
        }
        context.restore();

        fitLogoImage(context, logo, 28, 12, 270, 132);
        context.textAlign = 'center';
        context.fillStyle = '#fff';
        context.shadowColor = 'rgba(0, 0, 0, .78)';
        context.shadowBlur = 8;
        context.font = 'italic 500 31px Arial';
        context.fillText(title.toUpperCase().slice(0, 28) || 'JOGOS DO DIA', 540, 48, 550);
        const headline = context.createLinearGradient(0, 65, 0, 160);
        headline.addColorStop(0, '#fff5d5');
        headline.addColorStop(.4, '#ffd36e');
        headline.addColorStop(.72, '#b87523');
        headline.addColorStop(1, '#fff1bb');
        context.fillStyle = headline;
        context.strokeStyle = 'rgba(255, 249, 229, .9)';
        context.lineWidth = 3;
        context.font = 'italic 900 82px Arial';
        context.strokeText('FUTEBOL', 540, 140, 650);
        context.fillText('FUTEBOL', 540, 140, 650);
        context.fillStyle = '#fff';
        context.font = 'italic 900 27px Arial';
        const dateParts = String(date).split('-');
        const shortDate = dateParts.length === 3 ? dateParts[2] + '/' + dateParts[1] : formatBannerDate(date).day;
        context.fillText(shortDate, 540, 174);
        context.shadowColor = 'transparent';

        const cardHeight = 158;
        const rowStep = 199;
        const listTop = 220;
        const cardWidth = 1032;
        const left = 24;
        games.forEach(function (game, index) {
            const x = left;
            const y = listTop + index * rowStep;
            drawStadiumFixtureCard(context, game, fixtureImages[index], x, y, cardWidth, cardHeight, palette);
            context.textAlign = 'center';
            context.fillStyle = '#fff';
            context.font = 'italic 800 28px Arial';
            context.shadowColor = 'rgba(0, 0, 0, .9)';
            context.shadowBlur = 7;
            context.fillText(String(game.home || 'MANDANTE') + ' x ' + String(game.away || 'VISITANTE'), x + cardWidth / 2, y + cardHeight + 30, cardWidth - 16);
            context.shadowColor = 'transparent';
        });

        if (phone) {
            context.fillStyle = '#fff';
            context.font = '800 28px Arial';
            context.textAlign = 'center';
            context.fillText(phone, 540, 1320, 800);
        } else if (games.length < 5) {
            context.fillStyle = 'rgba(255, 255, 255, .86)';
            context.font = '700 20px Arial';
            context.textAlign = 'center';
            context.fillText('PARTIDAS E HORÁRIOS · ' + shortDate, 540, 1315);
        }
    }

    function drawFootballHeadline(context, games, fixtureImages, logo, palette, title, date, backgroundImage, phone) {
        if (backgroundImage) {
            coverImage(context, backgroundImage, 0, 0, 1080, 1350);
            context.fillStyle = 'rgba(12, 8, 4, .62)';
        } else {
            const background = context.createLinearGradient(0, 0, 0, 1350);
            background.addColorStop(0, '#25170b');
            background.addColorStop(.44, '#89531f');
            background.addColorStop(.72, '#24170c');
            background.addColorStop(1, '#090807');
            context.fillStyle = background;
        }
        context.fillRect(0, 0, 1080, 1350);
        drawStadiumLights(context);
        fitLogoImage(context, logo, 790, 26, 235, 116);

        roundedRect(context, 92, 42, 560, 62, 31, '#fff');
        context.textAlign = 'center';
        context.fillStyle = '#151515';
        context.font = '900 27px Arial';
        context.fillText('PROGRAMAÇÃO DE', 372, 82, 520);

        const headlineColor = context.createLinearGradient(0, 190, 0, 900);
        headlineColor.addColorStop(0, palette.accent);
        headlineColor.addColorStop(.2, palette.accent);
        headlineColor.addColorStop(.52, palette.dark);
        headlineColor.addColorStop(.78, palette.accent);
        headlineColor.addColorStop(1, palette.accent);
        const headlineWords = [
            { text: 'FU', x: 330, y: 472, width: 610, size: 300 },
            { text: 'TE', x: 785, y: 716, width: 620, size: 320 },
            { text: 'BOL', x: 375, y: 975, width: 840, size: 310 }
        ];
        headlineWords.forEach(function (word) {
            drawHeadlineWord(context, word.text, word, headlineColor, false);
        });
        drawSoccerBall(context, 455, 602, 254);
        headlineWords.forEach(function (word) {
            drawHeadlineWord(context, word.text, word, '#fff', true);
        });
        roundedRect(context, 42, 1015, 530, 68, 34, '#fff');
        context.fillStyle = '#161616';
        context.font = 'italic 900 32px Arial';
        const dateCaption = title.toUpperCase().replace(/^JOGOS\s+/i, '') || 'DO DIA';
        context.textAlign = 'center';
        context.fillText(dateCaption + ' · ' + formatBannerDate(date).day, 307, 1059, 500);

        if (phone) drawWhatsappFooter(context, phone);
    }

    function drawHeadlineWord(context, word, layout, color, outlineOnly) {
        context.save();
        context.translate(layout.x, layout.y);
        context.transform(1, 0, -.075, 1, 0, 0);
        context.textAlign = 'center';
        context.textBaseline = 'alphabetic';
        context.font = 'italic 900 ' + layout.size + 'px "Arial Black", Impact, sans-serif';
        context.lineJoin = 'round';
        context.miterLimit = 2.5;
        if (outlineOnly) {
            context.shadowColor = 'rgba(0, 0, 0, .8)';
            context.shadowBlur = 3;
            context.shadowOffsetX = 2;
            context.shadowOffsetY = 3;
            context.lineWidth = 18;
            context.strokeStyle = color;
            context.strokeText(word, 0, 0, layout.width);
        } else {
            context.shadowColor = 'rgba(0, 0, 0, .72)';
            context.shadowBlur = 8;
            context.shadowOffsetX = 3;
            context.shadowOffsetY = 7;
            context.lineWidth = 5;
            context.strokeStyle = 'rgba(34, 22, 10, .88)';
            context.strokeText(word, 0, 0, layout.width);
            context.fillStyle = color;
            context.fillText(word, 0, 0, layout.width);
        }
        context.restore();
    }

    function traceStadiumFixtureCard(context, x, y, width, height) {
        const slant = height * .28;
        context.beginPath();
        context.moveTo(x + slant, y);
        context.lineTo(x + width - slant * .42, y);
        context.quadraticCurveTo(x + width, y, x + width - slant * .08, y + height * .28);
        context.lineTo(x + width - slant * .34, y + height * .78);
        context.quadraticCurveTo(x + width - slant * .42, y + height, x + width - slant * .72, y + height);
        context.lineTo(x + slant * .2, y + height);
        context.quadraticCurveTo(x, y + height, x + slant * .1, y + height * .76);
        context.lineTo(x + slant * .35, y + height * .2);
        context.quadraticCurveTo(x + slant * .42, y, x + slant, y);
        context.closePath();
    }

    function drawStadiumFixtureCard(context, game, images, x, y, width, height, palette) {
        const leagueLogo = images && images.league;
        const homeLogo = images && images.home;
        const awayLogo = images && images.away;
        context.save();
        context.shadowColor = 'rgba(0, 0, 0, .48)';
        context.shadowBlur = 14;
        context.shadowOffsetY = 5;
        const card = context.createLinearGradient(x, y, x + width, y + height);
        card.addColorStop(0, '#fff');
        card.addColorStop(.48, '#deded9');
        card.addColorStop(.76, '#f4f3ef');
        card.addColorStop(1, '#fff');
        traceStadiumFixtureCard(context, x, y, width, height);
        context.fillStyle = card;
        context.fill();
        context.shadowColor = 'transparent';
        context.strokeStyle = 'rgba(255,255,255,.82)';
        context.lineWidth = 3;
        context.stroke();
        context.restore();

        const actionWidth = width * .31;
        const leagueWidth = width * .2;
        const homeX = x + width * .44;
        const awayX = x + width * .537;
        const logoSize = Math.min(148, height - 20);
        const logoY = y + (height - logoSize) / 2;
        if (leagueLogo) {
            fitLogoImage(context, leagueLogo, x + 20, y + 12, leagueWidth - 40, height - 24);
            context.textAlign = 'center';
            context.fillStyle = '#292929';
            context.font = '900 14px Arial';
            context.fillText(String(game.league_short || 'LIGA').toUpperCase(), x + leagueWidth / 2, y + height - 8, leagueWidth - 20);
        } else {
            context.textAlign = 'center';
            context.fillStyle = '#333';
            context.font = '900 21px Arial';
            context.fillText(String(game.league_short || 'LIGA').toUpperCase(), x + leagueWidth / 2, y + height / 2 + 7, leagueWidth - 18);
        }
        context.save();
        traceStadiumFixtureCard(context, x, y, width, height);
        context.clip();
        drawTeamLogo(context, awayLogo, awayX, logoY, logoSize, '#151515');
        drawTeamLogo(context, homeLogo, homeX, logoY, logoSize, '#151515');
        context.restore();

        const badgeX = x + width - actionWidth + 9;
        const badgeWidth = actionWidth - 20;
        const badge = context.createLinearGradient(badgeX, y, badgeX, y + height);
        badge.addColorStop(0, palette.soft);
        badge.addColorStop(.52, palette.accent);
        badge.addColorStop(1, palette.dark);
        const badgeGap = 7;
        const timeHeight = Math.min(62, height * .36);
        const statusHeight = Math.min(48, height * .28);
        const badgeInset = Math.round((height - timeHeight - badgeGap - statusHeight) / 2);
        roundedRect(context, badgeX, y + badgeInset, badgeWidth, timeHeight, timeHeight / 2, badge);
        context.textAlign = 'center';
        context.fillStyle = '#fff';
        context.font = 'italic 900 44px Arial';
        context.fillText(String(game.time || '--:--'), badgeX + badgeWidth / 2, y + badgeInset + timeHeight * .73, badgeWidth - 16);
        const statusY = y + badgeInset + timeHeight + badgeGap;
        roundedRect(context, badgeX, statusY, badgeWidth, statusHeight, statusHeight / 2, badge);
        context.fillStyle = '#fff';
        context.font = '900 19px Arial';
        const channel = String(game.channel || 'TRANSMISSÃO NÃO INFORMADA');
        context.font = channel.length > 18 ? '900 16px Arial' : 'italic 900 37px Arial';
        context.fillText(channel.toUpperCase(), badgeX + badgeWidth / 2, statusY + statusHeight * .67, badgeWidth - 18);
    }

    function drawGoldFixtureCard(context, game, images, x, y, width, height) {
        const leagueLogo = images && images.league;
        const homeLogo = images && images.home;
        const awayLogo = images && images.away;
        context.save();
        context.shadowColor = 'rgba(0, 0, 0, .52)';
        context.shadowBlur = 13;
        context.shadowOffsetY = 5;
        const card = context.createLinearGradient(x, y, x + width, y + height);
        card.addColorStop(0, '#f8f8f5');
        card.addColorStop(.52, '#d7d7d2');
        card.addColorStop(1, '#fff');
        roundedRect(context, x, y, width, height, Math.min(34, height * .24), card);
        context.restore();

        const leagueWidth = width * .17;
        const actionWidth = width * .25;
        const contentLeft = x + leagueWidth;
        const contentRight = x + width - actionWidth;
        const contentWidth = contentRight - contentLeft;
        const homeX = contentLeft + contentWidth * .28;
        const awayX = contentLeft + contentWidth * .72;
        const logoSize = Math.min(height * .56, contentWidth * .25);
        const logoY = y + Math.max(6, height * .06);
        if (leagueLogo) {
            fitLogoImage(context, leagueLogo, x + 8, y + 6, leagueWidth - 16, height * .6);
        } else {
            context.textAlign = 'center';
            context.fillStyle = '#242424';
            context.font = '900 13px Arial';
            wrapText(context, String(game.league || 'ESTADUAL').toUpperCase(), x + leagueWidth / 2, y + height * .36, leagueWidth - 12, 16, 3);
        }
        context.textAlign = 'center';
        context.fillStyle = '#242424';
        context.font = '800 10px Arial';
        wrapText(context, String(game.league || 'ESTADUAL').toUpperCase(), x + leagueWidth / 2, y + height - 7, leagueWidth - 10, 12, 2);

        drawTeamLogo(context, homeLogo, homeX, logoY, logoSize, '#151515');
        drawTeamLogo(context, awayLogo, awayX, logoY, logoSize, '#151515');
        const teamFontSize = Math.max(12, Math.min(19, height * .105));
        context.fillStyle = '#222';
        context.font = '900 ' + teamFontSize + 'px Arial';
        context.fillText(String(game.home || 'MANDANTE').toUpperCase(), homeX, y + height - 9, contentWidth * .42);
        context.fillText(String(game.away || 'VISITANTE').toUpperCase(), awayX, y + height - 9, contentWidth * .42);
        context.fillStyle = '#a87421';
        context.font = '900 ' + Math.max(24, Math.min(38, height * .23)) + 'px Arial';
        context.fillText('×', (homeX + awayX) / 2, y + height * .56);

        const actionX = x + width - actionWidth + 9;
        const badgeWidth = actionWidth - 20;
        const badge = context.createLinearGradient(actionX, y, actionX, y + height);
        badge.addColorStop(0, '#f7c75f');
        badge.addColorStop(1, '#a76d18');
        const timeHeight = Math.min(58, height * .36);
        roundedRect(context, actionX, y + 8, badgeWidth, timeHeight, timeHeight / 2, badge);
        context.fillStyle = '#fff';
        context.font = 'italic 900 ' + Math.max(20, Math.min(36, height * .22)) + 'px Arial';
        context.fillText(String(game.time || '--:--'), actionX + badgeWidth / 2, y + 8 + timeHeight * .72, badgeWidth - 12);
        const statusY = y + 15 + timeHeight;
        const statusHeight = Math.min(45, Math.max(27, height - timeHeight - 22));
        roundedRect(context, actionX, statusY, badgeWidth, statusHeight, statusHeight / 2, badge);
        context.font = '900 ' + Math.max(10, Math.min(16, statusHeight * .35)) + 'px Arial';
        context.fillText(formatFixtureStatus(game), actionX + badgeWidth / 2, statusY + statusHeight * .66, badgeWidth - 12);
    }

    function drawStadiumLights(context) {
        const lightGradient = context.createLinearGradient(0, 195, 0, 395);
        lightGradient.addColorStop(0, 'rgba(255, 199, 103, .52)');
        lightGradient.addColorStop(1, 'rgba(255, 177, 72, 0)');
        context.fillStyle = lightGradient;
        context.fillRect(0, 180, 1080, 220);
        context.save();
        context.shadowColor = 'rgba(255, 202, 118, .95)';
        context.shadowBlur = 22;
        context.fillStyle = '#ffe0a0';
        for (let index = 0; index < 18; index++) {
            const x = 28 + index * 61;
            const y = 235 + Math.sin(index * 1.5) * 34;
            context.fillRect(x, y, 4, 3);
        }
        context.restore();
    }

    function drawSoccerBall(context, centerX, centerY, radius) {
        context.save();
        context.shadowColor = 'rgba(0, 0, 0, .6)';
        context.shadowBlur = 28;
        context.shadowOffsetY = 10;
        const ball = context.createRadialGradient(centerX - radius * .35, centerY - radius * .4, radius * .1, centerX, centerY, radius);
        ball.addColorStop(0, '#fff');
        ball.addColorStop(.72, '#e9e9e9');
        ball.addColorStop(1, '#9d9d9d');
        context.beginPath();
        context.arc(centerX, centerY, radius, 0, Math.PI * 2);
        context.fillStyle = ball;
        context.fill();
        context.clip();
        context.shadowColor = 'transparent';

        function polygon(points, color) {
            context.beginPath();
            points.forEach(function (point, index) {
                if (index === 0) context.moveTo(point[0], point[1]);
                else context.lineTo(point[0], point[1]);
            });
            context.closePath();
            context.fillStyle = color;
            context.fill();
            context.strokeStyle = 'rgba(35, 35, 35, .72)';
            context.lineWidth = 4;
            context.stroke();
        }

        function regularPolygon(x, y, size, sides, rotation) {
            const points = [];
            for (let index = 0; index < sides; index++) {
                const angle = rotation + index * Math.PI * 2 / sides;
                points.push([x + Math.cos(angle) * size, y + Math.sin(angle) * size]);
            }
            polygon(points, '#202124');
        }

        regularPolygon(centerX, centerY, radius * .31, 5, -Math.PI / 2);
        for (let index = 0; index < 5; index++) {
            const angle = -Math.PI / 2 + index * Math.PI * 2 / 5;
            regularPolygon(
                centerX + Math.cos(angle) * radius * .76,
                centerY + Math.sin(angle) * radius * .76,
                radius * .22,
                5,
                angle
            );
        }
        context.restore();
        context.beginPath();
        context.arc(centerX, centerY, radius, 0, Math.PI * 2);
        context.strokeStyle = 'rgba(255,255,255,.85)';
        context.lineWidth = 5;
        context.stroke();
    }

    function formatBannerDate(date) {
        const parts = String(date).split('-').map(Number);
        if (parts.length !== 3 || parts.some(function (part) { return !Number.isFinite(part); })) {
            return { weekday: 'JOGOS DO DIA', day: String(date) };
        }
        const value = new Date(parts[0], parts[1] - 1, parts[2]);
        return {
            weekday: new Intl.DateTimeFormat('pt-BR', { weekday: 'long' }).format(value).toUpperCase(),
            day: new Intl.DateTimeFormat('pt-BR', { day: '2-digit' }).format(value)
                + ' ' + new Intl.DateTimeFormat('pt-BR', { month: 'short' }).format(value).replace('.', '').toUpperCase()
        };
    }

    function formatFixtureStatus(game) {
        const status = String(game.status || 'NS').toUpperCase();
        const score = game.home_score !== null && game.home_score !== undefined
            && game.away_score !== null && game.away_score !== undefined
            ? ' · ' + game.home_score + ' - ' + game.away_score
            : '';
        const labels = {
            NS: 'AGUARDANDO',
            TBD: 'HORÁRIO A DEFINIR',
            LIVE: 'AO VIVO',
            '1H': 'AO VIVO · 1º TEMPO',
            '2H': 'AO VIVO · 2º TEMPO',
            HT: 'INTERVALO',
            ET: 'PRORROGAÇÃO',
            P: 'PÊNALTIS',
            FT: 'ENCERRADO',
            AET: 'ENCERRADO · PRORROGAÇÃO',
            PEN: 'ENCERRADO · PÊNALTIS',
            PST: 'ADIADO',
            CANC: 'CANCELADO',
            SUSP: 'SUSPENSO',
            ABD: 'INTERROMPIDO',
            AWD: 'W.O.',
            WO: 'W.O.'
        };
        return (labels[status] || status) + score;
    }

    function drawWhatsappFooter(context, phone) {
        context.beginPath();
        context.arc(345, 1280, 24, 0, Math.PI * 2);
        context.fillStyle = '#22c96b';
        context.fill();
        context.fillStyle = '#fff';
        context.font = '900 26px Arial';
        context.textAlign = 'center';
        context.fillText('◔', 345, 1289);
        context.fillStyle = '#111';
        context.font = '900 38px Arial';
        context.fillText(phone, 590, 1293, 460);
    }

    function drawTeamLogo(context, image, centerX, y, size, foreground, fallbackLabel) {
        if (image) {
            fitLogoImage(context, image, centerX - size / 2, y, size, size);
            return;
        }
        if (fallbackLabel && drawReferenceTeamCrest(context, fallbackLabel, centerX, y, size)) {
            return;
        }
        context.beginPath();
        if (fallbackLabel) {
            context.moveTo(centerX, y + size * .06);
            context.lineTo(centerX + size * .4, y + size * .18);
            context.lineTo(centerX + size * .34, y + size * .66);
            context.lineTo(centerX, y + size * .95);
            context.lineTo(centerX - size * .34, y + size * .66);
            context.lineTo(centerX - size * .4, y + size * .18);
            context.closePath();
        } else {
            context.arc(centerX, y + size / 2, size / 2, 0, Math.PI * 2);
        }
        context.fillStyle = 'rgba(255,255,255,.92)';
        context.fill();
        context.strokeStyle = foreground;
        context.lineWidth = 3;
        context.stroke();
        context.fillStyle = foreground;
        context.font = fallbackLabel ? '900 ' + Math.max(12, size * .2) + 'px Arial' : '800 20px Arial';
        context.textAlign = 'center';
        context.fillText(fallbackLabel || 'FC', centerX, y + (fallbackLabel ? size * .58 : size / 2 + 7), size * .7);
    }

    function drawReferenceTeamCrest(context, label, centerX, y, size) {
        const colors = {
            BRU: ['#173f79', '#f5c744'],
            PAY: ['#1763a5', '#fff'],
            VAS: ['#171717', '#fff'],
            REM: ['#172f67', '#fff'],
            MIA: ['#111', '#e5a5ac'],
            DC: ['#121212', '#d62c3b'],
            SPFC: ['#fff', '#d92332'],
            VIT: ['#171717', '#d62832'],
            RIV: ['#fff', '#c92336'],
            EST: ['#fff', '#193a79']
        };
        const crestColors = colors[label];
        if (!crestColors) return false;

        context.save();
        context.translate(centerX - size / 2, y);
        context.scale(size / 100, size / 100);
        context.lineJoin = 'round';
        context.lineWidth = 5;
        context.strokeStyle = '#171717';
        context.fillStyle = crestColors[0];

        if (label === 'MIA') {
            context.beginPath();
            context.arc(50, 50, 44, 0, Math.PI * 2);
            context.fill();
            context.stroke();
            context.fillStyle = crestColors[1];
            context.font = '900 16px Arial';
            context.textAlign = 'center';
            context.fillText('MIAMI', 50, 57);
            context.restore();
            return true;
        }

        context.beginPath();
        context.moveTo(14, 10);
        context.lineTo(86, 10);
        context.lineTo(82, 62);
        context.lineTo(50, 94);
        context.lineTo(18, 62);
        context.closePath();
        context.fill();
        context.stroke();

        if (label === 'BRU') {
            context.fillStyle = crestColors[1];
            context.fillRect(18, 15, 64, 17);
            context.fillStyle = '#fff';
            context.fillRect(29, 32, 13, 47);
            context.fillRect(58, 32, 13, 47);
        } else if (label === 'PAY') {
            context.fillStyle = crestColors[1];
            context.fillRect(22, 16, 56, 12);
            context.fillRect(22, 41, 56, 10);
            context.fillRect(22, 64, 56, 10);
            context.fillStyle = '#e0bd46';
            for (let star = 0; star < 3; star++) {
                context.beginPath();
                context.arc(38 + star * 12, 34, 3, 0, Math.PI * 2);
                context.fill();
            }
        } else if (label === 'VAS') {
            context.fillStyle = crestColors[1];
            context.beginPath();
            context.moveTo(22, 61);
            context.lineTo(69, 19);
            context.lineTo(79, 27);
            context.lineTo(32, 69);
            context.closePath();
            context.fill();
            context.fillStyle = '#d92332';
            context.fillRect(58, 17, 19, 7);
            context.fillRect(64, 11, 7, 19);
        } else if (label === 'REM') {
            context.fillStyle = crestColors[1];
            context.fillRect(25, 18, 10, 54);
            context.fillRect(45, 18, 10, 64);
            context.fillRect(65, 18, 10, 54);
        } else if (label === 'DC') {
            context.fillStyle = crestColors[1];
            context.fillRect(19, 17, 62, 16);
            context.fillRect(19, 58, 62, 16);
        } else if (label === 'SPFC') {
            context.fillStyle = '#171717';
            context.fillRect(18, 15, 64, 19);
            context.fillStyle = '#d92332';
            context.beginPath();
            context.moveTo(20, 40);
            context.lineTo(80, 40);
            context.lineTo(50, 80);
            context.closePath();
            context.fill();
            context.fillStyle = '#fff';
            context.font = '900 16px Arial';
            context.textAlign = 'center';
            context.fillText('SPFC', 50, 29);
        } else if (label === 'VIT') {
            context.fillStyle = '#d92332';
            context.fillRect(23, 17, 12, 55);
            context.fillRect(45, 17, 12, 63);
            context.fillRect(67, 17, 12, 55);
        } else if (label === 'RIV') {
            context.fillStyle = crestColors[1];
            context.beginPath();
            context.moveTo(25, 19);
            context.lineTo(36, 19);
            context.lineTo(75, 67);
            context.lineTo(68, 76);
            context.closePath();
            context.fill();
            context.fillStyle = '#c92336';
            context.font = 'italic 900 12px Arial';
            context.textAlign = 'center';
            context.fillText('CARP', 51, 53);
        } else if (label === 'EST') {
            context.fillStyle = crestColors[1];
            context.fillRect(24, 17, 11, 55);
            context.fillRect(45, 17, 11, 63);
            context.fillRect(66, 17, 11, 55);
        }

        if (label !== 'SPFC' && label !== 'RIV' && label !== 'MIA') {
            context.fillStyle = (label === 'BRU' || label === 'DC') ? '#fff' : '#171717';
            context.font = '900 17px Arial';
            context.textAlign = 'center';
            context.fillText(label, 50, 54);
        }
        context.restore();
        return true;
    }

    function drawMonitorIcon(context, centerX, centerY) {
        context.save();
        context.strokeStyle = '#fff';
        context.lineWidth = 4;
        context.strokeRect(centerX - 17, centerY - 11, 34, 21);
        context.beginPath();
        context.moveTo(centerX, centerY + 10);
        context.lineTo(centerX, centerY + 17);
        context.moveTo(centerX - 9, centerY + 18);
        context.lineTo(centerX + 9, centerY + 18);
        context.stroke();
        context.restore();
    }

    const mediaModelNames = [
        'Pôster e sinopse',
        'Estreia em destaque',
        'Composição dividida',
        'Pôster central'
    ];

    function drawPoster(context, image, x, y, width, height, radius) {
        context.save();
        context.beginPath();
        context.roundRect(x, y, width, height, radius || 0);
        context.clip();
        coverImage(context, image, x, y, width, height);
        context.restore();
    }

    function drawMediaLogo(context, logo, x, y, width, height) {
        fitLogoImage(context, logo, x, y, width, height);
    }

    function drawMedia(context, item, logo, palette, title, phone, modelIndex) {
        const dark = '#080d1c';
        const foreground = '#ffffff';
        const muted = '#e8eaf0';
        const genres = Array.isArray(item.genres) ? item.genres.join(' · ') : '';
        const releaseLabel = item.year ? 'LANÇAMENTO ' + item.year : 'LANÇAMENTO DA SEMANA';
        const synopsisLabel = item.overview_language === 'en-US' ? 'SINOPSE ORIGINAL · EN' : 'SINOPSE';
        const overview = item.overview || 'Confira esta novidade e conheça mais sobre o título.';

        if (modelIndex === 0) {
            context.fillStyle = dark;
            context.fillRect(0, 0, 1080, 1350);
            drawMediaLogo(context, logo, 55, 30, 300, 150);
            context.textAlign = 'center';
            context.fillStyle = foreground;
            context.font = '900 44px Arial';
            context.fillText('NOVIDADE NA GRADE', 640, 140);
            drawPoster(context, item.image, 82, 225, 420, 650, 8);
            context.textAlign = 'left';
            context.fillStyle = foreground;
            context.font = '900 48px Arial';
            wrapText(context, item.title.toUpperCase(), 545, 285, 440, 54, 3);
            context.fillStyle = palette.accent;
            context.font = '800 28px Arial';
            context.fillText(releaseLabel, 545, 510);
            context.font = '600 23px Arial';
            wrapText(context, genres, 545, 565, 430, 31, 2);
            context.font = '900 27px Arial';
            context.fillText(synopsisLabel, 545, 680);
            context.fillStyle = muted;
            context.font = '400 22px Arial';
            wrapText(context, overview, 545, 727, 430, 31, 8);
            drawMediaContact(context, palette, phone, 1210);
        } else if (modelIndex === 1) {
            context.fillStyle = dark;
            context.fillRect(0, 0, 1080, 1350);
            drawPoster(context, item.backdropImage || item.image, 0, 0, 1080, 760, 0);
            const shade = context.createLinearGradient(0, 290, 0, 770);
            shade.addColorStop(0, 'rgba(5,7,12,0)');
            shade.addColorStop(1, 'rgba(5,7,12,.94)');
            context.fillStyle = shade;
            context.fillRect(0, 0, 1080, 760);
            drawMediaLogo(context, logo, 55, 30, 300, 145);
            roundedRect(context, 0, 690, 1080, 660, 0, dark);
            context.textAlign = 'center';
            context.fillStyle = palette.accent;
            context.font = '900 35px Arial';
            context.fillText(title.toUpperCase().slice(0, 32) || 'ESTREIA', 540, 810);
            context.fillStyle = foreground;
            context.font = '900 58px Arial';
            wrapText(context, item.title.toUpperCase(), 540, 900, 900, 64, 2);
            context.fillStyle = palette.accent;
            context.font = '700 25px Arial';
            context.fillText([item.year, genres].filter(Boolean).join('  ·  '), 540, 1070);
            context.fillStyle = muted;
            context.font = '400 21px Arial';
            wrapText(context, overview, 540, 1120, 820, 27, 3);
            drawMediaContact(context, palette, phone, 1230);
        } else if (modelIndex === 2) {
            context.fillStyle = dark;
            context.fillRect(0, 0, 1080, 1350);
            drawPoster(context, item.image, 0, 0, 520, 1350, 0);
            context.fillStyle = palette.dark;
            context.fillRect(520, 0, 18, 1350);
            context.fillStyle = dark;
            context.fillRect(538, 0, 542, 1350);
            drawMediaLogo(context, logo, 580, 35, 440, 170);
            context.textAlign = 'left';
            context.fillStyle = palette.accent;
            context.font = '900 31px Arial';
            wrapText(context, title.toUpperCase() || 'DESTAQUE', 580, 245, 430, 40, 2);
            context.fillStyle = foreground;
            context.font = '900 46px Arial';
            wrapText(context, item.title.toUpperCase(), 580, 360, 430, 52, 3);
            context.fillStyle = muted;
            context.font = '700 27px Arial';
            context.fillText(item.year || '', 580, 555);
            context.fillStyle = palette.accent;
            context.font = '600 22px Arial';
            wrapText(context, genres, 580, 615, 430, 30, 2);
            context.font = '900 27px Arial';
            context.fillText(synopsisLabel, 580, 755);
            context.fillStyle = muted;
            context.font = '400 21px Arial';
            wrapText(context, overview, 580, 805, 430, 29, 9);
            drawMediaContact(context, palette, phone, 1230);
        } else {
            context.fillStyle = dark;
            context.fillRect(0, 0, 1080, 1350);
            drawMediaLogo(context, logo, 380, 20, 320, 160);
            context.textAlign = 'center';
            context.fillStyle = palette.accent;
            context.font = '900 38px Arial';
            context.fillText(title.toUpperCase().slice(0, 32) || 'SUA PRÓXIMA SESSÃO', 540, 205);
            if (item.type === 'tv' && item.backdropImage) {
                drawPoster(context, item.backdropImage, 48, 240, 984, 910, 18);
                const shade = context.createLinearGradient(0, 780, 0, 1150);
                shade.addColorStop(0, 'rgba(8,13,28,0)');
                shade.addColorStop(1, 'rgba(8,13,28,.96)');
                context.fillStyle = shade;
                context.fillRect(48, 780, 984, 370);
            } else {
                roundedRect(context, 48, 240, 984, 910, 18, '#f4f5f7');
                drawPoster(context, item.image, 280, 255, 520, 740, 5);
                roundedRect(context, 48, 1005, 984, 145, 0, dark);
            }
            context.fillStyle = foreground;
            context.font = '900 46px Arial';
            wrapText(context, item.title.toUpperCase(), 540, 1060, 900, 52, 2);
            context.fillStyle = palette.accent;
            context.font = '700 23px Arial';
            context.fillText([item.year, genres].filter(Boolean).join(' · '), 540, 1140);
            drawMediaContact(context, palette, phone, 1230);
        }

        context.textAlign = 'center';
        context.fillStyle = muted;
        context.font = '600 15px Arial';
        context.fillText('IMAGEM E INFORMAÇÕES: TMDB', 540, 1330);
    }

    function drawMediaContact(context, palette, phone, y) {
        if (!phone) return;
        roundedRect(context, 300, y - 30, 480, 72, 12, palette.accent);
        context.textAlign = 'center';
        context.fillStyle = '#ffffff';
        context.font = '800 18px Arial';
        context.fillText('CONTATO', 540, y - 3);
        context.font = '800 25px Arial';
        context.fillText(phone, 540, y + 25);
    }

    async function canvasToBlob(canvas) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('O navegador não conseguiu exportar o banner.'));
                }
            }, 'image/png');
        });
    }

    function renderGeneratedBanners(banners) {
        generatedObjectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        generatedObjectUrls = [];
        generatedBannerFiles = [];
        generatedResults.replaceChildren();

        banners.forEach(function (banner) {
            const objectUrl = URL.createObjectURL(banner.blob);
            generatedObjectUrls.push(objectUrl);
            const fileName = 'banner-' + banner.slug + '-'
                + (banner.modelIndex === undefined ? banner.palette.key : 'modelo-' + (banner.modelIndex + 1) + '-' + banner.palette.key)
                + (banner.footballModel ? '-' + banner.footballModel : '')
                + '.png';
            generatedBannerFiles.push({
                blob: banner.blob,
                fileName: fileName,
                title: banner.label || banner.palette.label
            });

            const card = document.createElement('article');
            card.className = 'generated-banner-card';
            const heading = document.createElement('h3');
            heading.textContent = banner.label || banner.palette.label;
            heading.className = 'generated-banner-title color-' + banner.palette.key;
            const preview = document.createElement('div');
            preview.className = 'generated-preview';
            if (banner.footballModel === 'stadium') {
                preview.classList.add('is-stadium-preview');
            }
            const image = document.createElement('img');
            image.src = objectUrl;
            image.alt = 'Banner ' + (banner.label || banner.palette.label);
            preview.append(image);
            const download = document.createElement('a');
            download.className = 'generator-button generate-button download-button';
            download.href = objectUrl;
            download.download = fileName;
            download.textContent = 'Baixar ' + (banner.label || banner.palette.label);
            card.append(heading, preview, download);
            generatedResults.append(card);
        });
        generatorForm.hidden = true;
        generatedPanel.hidden = false;
        shareFeedback.hidden = true;
    }

    function downloadAllBanners() {
        generatedBannerFiles.forEach(function (banner, index) {
            const link = document.createElement('a');
            link.href = generatedObjectUrls[index];
            link.download = banner.fileName;
            link.hidden = true;
            document.body.append(link);
            link.click();
            link.remove();
        });
    }

    function setShareFeedback(text, isError) {
        shareFeedback.textContent = text;
        shareFeedback.classList.toggle('is-error', Boolean(isError));
        shareFeedback.hidden = !text;
    }

    async function shareGeneratedBanners(destination) {
        if (generatedBannerFiles.length === 0) {
            setShareFeedback('Gere os banners antes de compartilhar.', true);
            return;
        }

        const files = generatedBannerFiles.map(function (banner) {
            return new File([banner.blob], banner.fileName, { type: 'image/png' });
        });
        const shareTitle = destination === 'instagram'
            ? 'Banners para Instagram Stories'
            : destination === 'whatsapp'
                ? 'Compartilhar banners no WhatsApp'
                : 'Compartilhar banners';
        const shareData = {
            files: files,
            title: shareTitle,
            text: 'Meus banners estão prontos para compartilhar.'
        };
        const supportsFileShare = typeof navigator.share === 'function'
            && (typeof navigator.canShare !== 'function' || navigator.canShare({ files: files }));

        if (supportsFileShare) {
            try {
                await navigator.share(shareData);
                setShareFeedback('Compartilhamento concluído.', false);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setShareFeedback('Não foi possível abrir o compartilhamento: ' + error.message, true);
                }
            }
            return;
        }

        if (destination === 'whatsapp') {
            const whatsappLink = document.createElement('a');
            whatsappLink.href = 'https://wa.me/?text=' + encodeURIComponent('Meus banners estão prontos. Estou anexando as imagens.');
            whatsappLink.target = '_blank';
            whatsappLink.rel = 'noopener noreferrer';
            whatsappLink.click();
        }
        downloadAllBanners();
        setShareFeedback(destination === 'instagram'
            ? 'As imagens foram baixadas. Abra o Instagram, crie um Story e selecione os banners baixados.'
            : destination === 'whatsapp'
                ? 'As imagens foram baixadas. Anexe os arquivos baixados à conversa do WhatsApp.'
                : 'As imagens foram baixadas para você compartilhar no Instagram Stories ou WhatsApp.',
        false);
    }

    document.getElementById('share-all-banners').addEventListener('click', function () {
        shareGeneratedBanners('all');
    });
    document.getElementById('share-instagram-stories').addEventListener('click', function () {
        shareGeneratedBanners('instagram');
    });
    document.getElementById('share-whatsapp').addEventListener('click', function () {
        shareGeneratedBanners('whatsapp');
    });
    document.getElementById('download-all-banners').addEventListener('click', downloadAllBanners);
    document.getElementById('back-to-generator').addEventListener('click', function () {
        generatedPanel.hidden = true;
        generatorForm.hidden = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    generateButton.addEventListener('click', async function () {
        showMessage('', false);
        if (accountLogoUrl === '') {
            showMessage('Cadastre uma logo na identidade da sua conta antes de gerar o banner.', true);
            return;
        }

        const selectedFootballModel = contentType === 'football'
            ? document.querySelector('input[name="banner-model"]:checked').value
            : '';
        const headlineOnly = selectedFootballModel === 'headline';
        const stadiumOnly = selectedFootballModel === 'stadium';
        if (contentType === 'football' && !headlineOnly && fixtures.length === 0) {
            await loadFootballFixtures(true);
            if (fixtures.length === 0) return;
        } else if (contentType === 'football' && !headlineOnly
            && footballResults.querySelectorAll('input[name="fixture"]:checked').length === 0) {
            selectAllFootballFixtures();
        }

        const night = selectedFootballModel === 'noturna';
        const footballModelNames = {
            lista: 'Jogos em destaque',
            noturna: 'Jogos em destaque noturno',
            stadium: 'Estádio · arte quadrada',
            headline: 'Futebol · chamada gigante'
        };
        const phone = document.getElementById('banner-phone').value.trim();
        let backgroundImage = null;
        const title = document.getElementById('banner-title').value.trim()
            || (contentType === 'football' ? 'JOGOS DO DIA' : 'DESTAQUE');
        let games = [];
        let selectedMedia = null;
        if (contentType === 'football') {
            games = headlineOnly ? [] : Array.from(footballResults.querySelectorAll('input[name="fixture"]:checked'))
                .map(function (checkbox) { return fixtures[Number(checkbox.value)]; })
                .filter(Boolean);
            if (!headlineOnly && !stadiumOnly && games.length === 0) {
                showMessage('Selecione pelo menos uma partida para gerar os banners.', true);
                return;
            }
        } else {
            selectedMedia = mediaItems[selectedMediaIndex];
            if (!selectedMedia) {
                showMessage('Selecione um filme ou uma série antes de gerar.', true);
                return;
            }
        }

        generateButton.disabled = true;
        startLoading('Preparando o banner...', 'Carregando a logo cadastrada na sua conta.');
        try {
            setProgress(18, 'Preparando o banner...', 'Carregando a logo cadastrada na sua conta.');
            const logo = await loadImage(accountLogoUrl);
            const selectedBackgroundUrl = backgroundObjectUrl || defaultBackgroundUrl;
            if (selectedBackgroundUrl) {
                setProgress(28, 'Preparando o fundo...', 'Carregando a imagem escolhida.');
                backgroundImage = await loadImage(selectedBackgroundUrl);
            }
            let mediaImage = null;
            let mediaBackdropImage = null;
            if (selectedMedia) {
                setProgress(36, 'Carregando a arte...', 'Preparando a imagem do título selecionado.');
                mediaImage = await loadImage(selectedMedia.image);
                if (selectedMedia.type === 'tv' && typeof selectedMedia.backdrop === 'string' && selectedMedia.backdrop !== '') {
                    setProgress(40, 'Carregando a cena...', 'Preparando a imagem horizontal da série.');
                    mediaBackdropImage = await loadImage(selectedMedia.backdrop);
                }
            }
            const effectiveBackground = contentType === 'football' ? backgroundImage : null;
            const effectiveNight = contentType === 'football' && night;
            const footballImages = selectedMedia || headlineOnly ? [] : await Promise.all(games.map(async function (game) {
                const images = await Promise.all([
                    loadOptionalImage(game.league_logo),
                    loadOptionalImage(game.home_logo),
                    loadOptionalImage(game.away_logo)
                ]);
                return {
                    league: removeLightLogoBackground(images[0]),
                    home: removeLightLogoBackground(images[1]),
                    away: removeLightLogoBackground(images[2])
                };
            }));
            const missingFixtureImages = footballImages.reduce(function (count, images) {
                return count + Number(!images.league) + Number(!images.home) + Number(!images.away);
            }, 0);
            await sleep(100);
            const slug = (selectedMedia ? selectedMedia.title : 'futebol')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            const banners = [];
            const activeColorVariants = selectedMedia
                ? night ? nightColorVariants : colorVariants
                : night ? nightColorVariants : colorVariants;
            const fixtureGroups = selectedMedia ? [games] : headlineOnly ? [[]] : [];
            const fixtureGroupSize = 5;
            if (!selectedMedia && !headlineOnly) {
                for (let offset = 0; offset < games.length; offset += fixtureGroupSize) {
                    fixtureGroups.push(games.slice(offset, offset + fixtureGroupSize));
                }
            }
            const totalBanners = selectedMedia ? activeColorVariants.length : fixtureGroups.length * activeColorVariants.length;
            let completedBanners = 0;
            for (let groupIndex = 0; groupIndex < fixtureGroups.length; groupIndex++) {
                const groupGames = fixtureGroups[groupIndex];
                const groupImages = selectedMedia ? [] : footballImages.slice(groupIndex * fixtureGroupSize, groupIndex * fixtureGroupSize + groupGames.length);
                for (let index = 0; index < activeColorVariants.length; index++) {
                    const palette = activeColorVariants[index];
                    completedBanners++;
                    const progress = 42 + Math.round((completedBanners / totalBanners) * 50);
                    const groupLabel = headlineOnly ? '' : 'Grupo ' + (groupIndex + 1);
                    setProgress(
                        progress,
                        'Gerando ' + (selectedMedia ? 'modelo ' : groupLabel ? groupLabel + ' · ' : '') + palette.label.toLowerCase() + '...',
                        'Criando banner ' + completedBanners + ' de ' + totalBanners + '.'
                    );
                    const canvas = createCanvas();
                    const context = canvas.getContext('2d');
                    if (!context) {
                        throw new Error('Este navegador não oferece suporte à geração de imagens.');
                    }
                    context.scale(bannerScale, bannerScale);
                    if (selectedMedia) {
                        drawMedia(context, Object.assign({}, selectedMedia, {
                            image: mediaImage,
                            backdropImage: mediaBackdropImage
                        }), logo, palette, title, phone, index);
                    } else {
                        drawFootball(
                            context,
                            groupGames,
                            groupImages,
                            logo,
                            effectiveNight,
                            palette,
                            title,
                            document.getElementById('football-date').value,
                            effectiveBackground,
                            phone,
                            selectedFootballModel
                        );
                    }
                    banners.push({
                        palette: palette,
                        blob: await canvasToBlob(canvas),
                        slug: selectedMedia ? (slug || 'personalizado')
                            : headlineOnly ? 'futebol-chamada'
                                : 'futebol-grupo-' + (groupIndex + 1),
                        modelIndex: selectedMedia ? index : undefined,
                        footballModel: selectedMedia ? '' : selectedFootballModel,
                        label: selectedMedia
                            ? 'Modelo ' + (index + 1) + ' · ' + mediaModelNames[index] + ' · ' + palette.label
                            : stadiumOnly
                                ? 'Estádio · jogos'
                                    + (fixtureGroups.length > 1 ? ' · Grupo ' + (groupIndex + 1) : '')
                                    + ' · ' + palette.label
                                : footballModelNames[selectedFootballModel]
                                    + (groupLabel ? ' · ' + groupLabel : '')
                                    + ' · ' + palette.label
                    });
                }
            }
            renderGeneratedBanners(banners);
            document.getElementById('generated-description').textContent = selectedMedia
                ? 'Quatro composições diferentes para o mesmo título, com as cores verde, amarela, vermelha e azul.'
                : headlineOnly
                    ? 'Quatro versões da chamada de futebol, sem carregar partidas.'
                    : stadiumOnly
                    ? 'Quatro versões de cores com as partidas reais divididas em grupos de até cinco jogos.'
                        : footballModelNames[selectedFootballModel] + ': ' + games.length
                    + (games.length === 1 ? ' partida em ' : ' partidas em ') + fixtureGroups.length
                    + (fixtureGroups.length === 1 ? ' grupo de até 5. Cada grupo tem quatro versões' : ' grupos de até 5. Cada grupo tem quatro versões')
                    + (selectedFootballModel === 'stadium'
                        ? ' em formato vertical, com cinco cartões de tamanho padrão.'
                        : night ? ' noturnas nas cores roxo, preto, lilás e azul-claro.' : ' nas cores verde, amarela, vermelha e azul.');
            setProgress(100, banners.length + ' banners prontos!', selectedMedia
                ? 'Cada imagem usa um modelo diferente de filme/série.'
                : headlineOnly
                    ? 'Arte gerada sem carregar partidas.'
                    : games.length + (games.length === 1 ? ' partida dividida em ' : ' partidas divididas em ')
                    + fixtureGroups.length + (fixtureGroups.length === 1 ? ' grupo.' : ' grupos.'));
            await sleep(450);
            stopLoading();
            if (!selectedMedia && missingFixtureImages > 0) {
                showMessage(
                    'Não foi possível carregar ' + missingFixtureImages + ' imagem(ns) de times ou ligas; usamos substitutos onde necessário.',
                    false
                );
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            stopLoading();
            showMessage(error.message || 'Não foi possível gerar o banner.', true);
        } finally {
            updateGenerateButton();
        }
    });

    window.addEventListener('beforeunload', function () {
        generatedObjectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        if (backgroundObjectUrl) URL.revokeObjectURL(backgroundObjectUrl);
    });
})();
