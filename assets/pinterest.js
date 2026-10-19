export function initializePinterest($main, navigatePage) {
    let $gallery = $main.querySelector('#pinterest-gallery');
    if (!$gallery) return null;
    let lifecycle = new AbortController();
    let $board = $gallery.querySelector('#pinterest-board');
    let $grid = $gallery.querySelector('#pinterest-grid');
    let $loader = $gallery.querySelector('#pinterest-loader');
    let $message = $gallery.querySelector('#pinterest-message');
    let $retry = $gallery.querySelector('#pinterest-retry');
    let $viewer = $gallery.querySelector('#pinterest-viewer');
    let $image = $gallery.querySelector('#pinterest-image');
    let $previous = $gallery.querySelector('#pinterest-previous');
    let $next = $gallery.querySelector('#pinterest-next');
    let pins = new Map();
    let pinOrder = [];
    let selectedBoard = '';
    let bookmark = null;
    let loading = false;
    let failed = false;
    let currentPin = '';
    let detailRequest = '';
    let retryRequest = loadBoards;
    let observer = new IntersectionObserver(
        entries => {
            if (entries.some(entry => entry.isIntersecting) && !failed && bookmark) loadPins();
        },
        { rootMargin: '400px' }
    );

    async function request(parameters) {
        let response = await fetch(`?${new URLSearchParams(parameters)}`, { signal: lifecycle.signal });
        if (!response.headers.get('content-type')?.includes('application/json')) {
            throw new Error('Pinterest konnte nicht geladen werden. Verbindung und Anmeldung prüfen.');
        }
        let result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Pinterest konnte nicht geladen werden.');
        return result;
    }

    async function loadBoards() {
        selectedBoard = '';
        $board.disabled = true;
        $board.replaceChildren(new Option('Pinnwände werden geladen …', ''));
        $retry.hidden = true;
        $message.textContent = 'Pinnwände werden geladen …';
        try {
            let result = await request({ pinterest: 'boards' });
            if (result.boards.length === 0) {
                $board.replaceChildren(new Option('Keine Pinnwände verfügbar', ''));
                $message.textContent = 'Keine Pinnwände mit diesem Token verfügbar.';
                return;
            }
            let requestedBoard = new URL(location.href).searchParams.get('board');
            selectedBoard = requestedBoard || result.selected;
            if (!result.boards.some(board => board.id === selectedBoard)) {
                throw new Error('Die ausgewählte Pinterest-Pinnwand ist mit diesem Token nicht verfügbar.');
            }
            $board.replaceChildren(...result.boards.map(board => new Option(board.name, board.id)));
            $board.value = selectedBoard;
            $board.disabled = false;
            $gallery.querySelector('#pinterest-count').textContent =
                `${result.boards.find(board => board.id === selectedBoard).pinCount.toLocaleString('de-DE')} Pins laut Pinterest`;
            let $boardLink = $gallery.querySelector('#pinterest-board-link');
            $boardLink.hidden = !result.url || selectedBoard !== result.selected;
            if (result.url) $boardLink.href = result.url;
            await loadPins();
        } catch (error) {
            if (lifecycle.signal.aborted) return;
            selectedBoard = '';
            $board.replaceChildren(new Option('Pinnwände nicht verfügbar', ''));
            $message.textContent = error.message;
            retryRequest = loadBoards;
            $retry.hidden = false;
        } finally {
            $grid.setAttribute('aria-busy', 'false');
        }
    }

    async function loadPins() {
        if (loading) return;
        loading = true;
        failed = false;
        $retry.hidden = true;
        $grid.setAttribute('aria-busy', 'true');
        $message.textContent = 'Pins werden geladen …';
        try {
            let result = await request({ pinterest: 'pins', board: selectedBoard, ...(bookmark ? { bookmark } : {}) });
            for (let pin of result.pins) {
                if (pinOrder.includes(pin.id)) continue;
                pins.set(pin.id, pin);
                pinOrder.push(pin.id);
                let $tile = $gallery.querySelector('#pinterest-tile').content.cloneNode(true);
                let $card = $tile.querySelector('.photo-card');
                let $preview = $card.querySelector('img');
                $card.dataset.pin = pin.id;
                $card.setAttribute('aria-label', `${pin.title} öffnen`);
                $preview.src = pin.thumbnail || '?asset=favicon.svg';
                $preview.alt = pin.title;
                $tile.querySelector('.pinterest-caption').textContent = pin.title;
                $grid.append($tile);
            }
            bookmark = result.bookmark;
            $message.textContent =
                pinOrder.length === 0
                    ? 'Noch keine Pins in dieser Pinnwand.'
                    : `${pinOrder.length.toLocaleString('de-DE')} Pins geladen${bookmark ? '.' : ' · Alle Pins geladen.'}`;
            observer.unobserve($loader);
            if (bookmark) observer.observe($loader);
            syncPhoto();
        } catch (error) {
            if (lifecycle.signal.aborted) return;
            failed = true;
            $message.textContent = error.message;
            retryRequest = loadPins;
            $retry.hidden = false;
        } finally {
            loading = false;
            $grid.setAttribute('aria-busy', 'false');
        }
    }

    function openPin(id, push = true) {
        let pin = pins.get(id);
        if (!pin) return;
        currentPin = id;
        if (push && new URL(location.href).searchParams.get('image') !== id) {
            let url = new URL(location.href);
            url.searchParams.set('image', id);
            if ($viewer.open) history.replaceState(history.state, '', url);
            if (!$viewer.open) history.pushState({ pinterestViewer: true }, '', url);
        }
        $viewer.querySelector('#pinterest-title').textContent = pin.title;
        $viewer.querySelector('#pinterest-description').textContent = pin.description;
        $viewer.querySelector('#pinterest-pin-link').href = pin.link;
        let $source = $viewer.querySelector('#pinterest-source-link');
        $source.hidden = !pin.source;
        if (pin.source) $source.href = pin.source;
        $image.parentElement.dataset.previewState = 'loading';
        $image.parentElement.setAttribute('aria-busy', 'true');
        $image.src = pin.image || '?asset=favicon.svg';
        $image.alt = pin.title;
        let index = pinOrder.indexOf(id);
        $previous.disabled = index <= 0;
        $next.disabled = index < 0 || (index === pinOrder.length - 1 && !bookmark);
        if (!$viewer.open) $viewer.showModal();
    }

    async function syncPhoto() {
        let id = new URL(location.href).searchParams.get('image');
        if (!id) {
            currentPin = '';
            $viewer.close();
            if (retryRequest === syncPhoto) {
                $retry.hidden = true;
                $message.textContent = '';
                retryRequest = loadPins;
            }
            return;
        }
        if (!selectedBoard) return;
        if (pins.has(id)) {
            openPin(id, false);
            return;
        }
        if (detailRequest === id) return;
        detailRequest = id;
        $retry.hidden = true;
        try {
            let pin = await request({ pinterest: 'pin', board: selectedBoard, id });
            if (new URL(location.href).searchParams.get('image') !== id) return;
            pins.set(pin.id, pin);
            openPin(pin.id, false);
        } catch (error) {
            if (!lifecycle.signal.aborted) {
                $message.textContent = error.message;
                retryRequest = syncPhoto;
                $retry.hidden = false;
            }
        } finally {
            if (detailRequest === id) detailRequest = '';
        }
    }

    function closePin() {
        $viewer.close();
        currentPin = '';
        if (history.state?.pinterestViewer) {
            history.back();
            return;
        }
        let url = new URL(location.href);
        url.searchParams.delete('image');
        history.replaceState(null, '', url);
    }

    async function showNeighbour(direction) {
        let previousPin = currentPin;
        let index = pinOrder.indexOf(currentPin);
        if (index < 0) return;
        index += direction;
        if (index === pinOrder.length && bookmark) {
            await loadPins();
        }
        if (currentPin !== previousPin) return;
        if (pinOrder[index]) openPin(pinOrder[index]);
    }

    $gallery.addEventListener(
        'click',
        event => {
            let $card = event.target.closest('[data-pin]');
            if ($card) openPin($card.dataset.pin);
            if (event.target.closest('#pinterest-retry')) retryRequest();
        },
        { signal: lifecycle.signal }
    );
    $board.addEventListener(
        'change',
        () => {
            let url = new URL(location.href);
            url.searchParams.set('board', $board.value);
            url.searchParams.delete('image');
            navigatePage(url.href).catch(() => {});
        },
        { signal: lifecycle.signal }
    );
    $gallery.querySelector('#pinterest-close').addEventListener('click', closePin, { signal: lifecycle.signal });
    $viewer.addEventListener(
        'cancel',
        event => {
            event.preventDefault();
            closePin();
        },
        { signal: lifecycle.signal }
    );
    $viewer.addEventListener(
        'click',
        event => {
            if (event.target === $viewer) closePin();
        },
        { signal: lifecycle.signal }
    );
    $previous.addEventListener('click', () => showNeighbour(-1), { signal: lifecycle.signal });
    $next.addEventListener('click', () => showNeighbour(1), { signal: lifecycle.signal });
    $viewer.addEventListener(
        'keydown',
        event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            showNeighbour(event.key === 'ArrowLeft' ? -1 : 1);
        },
        { signal: lifecycle.signal }
    );
    loadBoards();

    return {
        syncPhoto,
        dispose() {
            lifecycle.abort();
            observer.disconnect();
            $viewer.close();
            pins.clear();
            pinOrder = [];
        }
    };
}
