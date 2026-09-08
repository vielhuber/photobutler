export function initializeGallery() {
    let lifecycle = new AbortController();
    let disposed = false;
    let galleryVersion = 0;
    let $main = document.querySelector('.main');
    let $sidebar = document.querySelector('#sidebar');
    let $sidebarResize = document.querySelector('#sidebar-resize');
    let sidebarWidth = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sidebar-width'));
    let sidebarDragOffset = 0;
    try {
        let savedWidth = Number(localStorage.getItem('photobutler.sidebarWidth'));
        if (Number.isFinite(savedWidth) && savedWidth > 0) sidebarWidth = savedWidth;
    } catch {}

    function resizeSidebar(width) {
        let maximum = Math.max(210, Math.min(640, innerWidth - 360));
        let clampedWidth = Math.round(Math.max(210, Math.min(maximum, width)));
        document.documentElement.style.setProperty('--sidebar-width', `${clampedWidth}px`);
        $sidebarResize.setAttribute('aria-valuenow', clampedWidth);
        $sidebarResize.setAttribute('aria-valuemax', maximum);
        return clampedWidth;
    }

    function saveSidebarWidth() {
        try {
            localStorage.setItem('photobutler.sidebarWidth', String(sidebarWidth));
        } catch {}
    }

    resizeSidebar(sidebarWidth);
    window.addEventListener('resize', () => resizeSidebar(sidebarWidth), { signal: lifecycle.signal });
    $sidebarResize.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        event.preventDefault();
        $sidebarResize.focus();
        sidebarDragOffset = event.clientX - $sidebar.getBoundingClientRect().width;
        $sidebarResize.setPointerCapture(event.pointerId);
        document.documentElement.classList.add('sidebar-resizing');
    });
    $sidebarResize.addEventListener('pointermove', event => {
        if (!$sidebarResize.hasPointerCapture(event.pointerId)) return;
        sidebarWidth = resizeSidebar(event.clientX - sidebarDragOffset);
    });
    $sidebarResize.addEventListener('lostpointercapture', () => {
        document.documentElement.classList.remove('sidebar-resizing');
        saveSidebarWidth();
    });
    $sidebarResize.addEventListener('keydown', event => {
        let width = $sidebar.getBoundingClientRect().width;
        let widths = { ArrowLeft: width - 10, ArrowRight: width + 10, Home: 210, End: 640 };
        if (!(event.key in widths)) return;
        event.preventDefault();
        sidebarWidth = resizeSidebar(widths[event.key]);
        saveSidebarWidth();
    });

    function updateColumnSelector() {
        document.querySelector('#gallery-columns').value = getComputedStyle(document.documentElement)
            .getPropertyValue('--gallery-columns')
            .trim();
    }
    updateColumnSelector();
    $main.addEventListener('change', event => {
        if (event.target.id !== 'gallery-columns' || !['5', '6', '7'].includes(event.target.value)) return;
        document.documentElement.style.setProperty('--gallery-columns', event.target.value);
        try {
            localStorage.setItem('photobutler.galleryColumns', event.target.value);
        } catch {}
    });

    let previewSelector = '[data-preview-state] > img';
    function updatePreview($image) {
        let state =
            !$image.getAttribute('src') || !$image.complete ? 'loading' : $image.naturalWidth > 0 ? 'ready' : 'error';
        $image.parentElement.dataset.previewState = state;
        $image.parentElement.setAttribute('aria-busy', String(state === 'loading'));
    }
    function updatePreviews() {
        document.querySelectorAll(previewSelector).forEach(updatePreview);
    }
    for (let eventName of ['load', 'error']) {
        document.addEventListener(
            eventName,
            event => {
                if (event.target.matches?.(previewSelector)) updatePreview(event.target);
            },
            { capture: true, signal: lifecycle.signal }
        );
    }
    updatePreviews();

    let $viewer = document.querySelector('#viewer');
    let $cards = [...document.querySelectorAll('[data-photo]')];
    let $image = document.querySelector('#viewer-image');
    let $title = document.querySelector('#viewer-title');
    let $date = document.querySelector('#viewer-date');
    let $description = document.querySelector('#viewer-description');
    let $status = document.querySelector('#viewer-status');
    let $favorite = document.querySelector('#viewer-favorite');
    let $tags = document.querySelector('#viewer-tags');
    let $message = document.querySelector('#viewer-message');
    let $download = document.querySelector('#viewer-download');
    let $previous = document.querySelector('.viewer-previous');
    let $next = document.querySelector('.viewer-next');
    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let currentPhoto = null;
    let currentIndex = 0;
    let requestNumber = 0;

    async function openPhoto(id, updateHistory = true) {
        id = Number(id);
        if (!Number.isSafeInteger(id) || id < 1) return;
        currentIndex = $cards.findIndex($card => Number($card.dataset.photo) === id);
        if (updateHistory) {
            let url = new URL(location.href);
            let alreadyOpen = url.searchParams.has('image');
            url.searchParams.set('image', id);
            if (alreadyOpen) history.replaceState(history.state, '', url);
            if (!alreadyOpen) history.pushState({ photoViewer: true }, '', url);
        }
        let request = ++requestNumber;
        currentPhoto = null;
        $message.textContent = '';
        $title.textContent = 'Lädt …';
        $description.textContent = '';
        $date.textContent = '';
        $status.textContent = '';
        $tags.value = '';
        $favorite.disabled = true;
        $download.removeAttribute('href');
        $previous.disabled = currentIndex <= 0;
        $next.disabled = currentIndex < 0 || currentIndex === $cards.length - 1;
        $image.onerror = () => {
            $image.onerror = null;
            $image.src = `?photo=${id}&size=display`;
        };
        $image.src = `?photo=${id}&size=original`;
        updatePreview($image);
        if (!$viewer.open) $viewer.showModal();
        try {
            let response = await fetch(`?detail=${id}`, { signal: lifecycle.signal });
            if (!response.ok)
                throw new Error('Das Foto konnte nicht geladen werden. Bitte neu anmelden oder die Seite neu laden.');
            let photo = await response.json();
            if (request !== requestNumber) return;
            currentPhoto = photo;
            $title.textContent = photo.name;
            $date.textContent = `${photo.taken.slice(0, 10)} · ${photo.album} · ${photo.width} × ${photo.height}`;
            $description.textContent = photo.description;
            $status.textContent = {
                pending: 'KI-Tags ausstehend',
                error: 'KI-Fehler · nach einer Stunde erneut starten',
                done: 'KI-Tags vorhanden'
            }[photo.status];
            $image.alt = photo.description || photo.name;
            $tags.value = photo.tags.join(', ');
            $favorite.textContent = photo.favorite ? '♥ Favorit entfernen' : '♡ Als Favorit';
            $favorite.disabled = false;
            $download.href = `?photo=${photo.id}&size=original&download=1`;
        } catch (error) {
            if (request === requestNumber) {
                $title.textContent = 'Foto nicht verfügbar';
                $message.textContent = error.message;
            }
        }
    }

    async function savePhoto(action) {
        if (!currentPhoto) return;
        let photo = currentPhoto;
        let body = new FormData();
        body.set('csrf', csrf);
        body.set('action', action);
        body.set('id', photo.id);
        body.set('tags', $tags.value);
        body.set('favorite', photo.favorite ? '0' : '1');
        $favorite.disabled = true;
        try {
            let response = await fetch('./', { method: 'POST', body, signal: lifecycle.signal });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Speichern fehlgeschlagen. Sitzung und Tags prüfen (maximal 20 Tags, je 60 Zeichen).');
            let updated = await response.json();
            let $marker = document.querySelector(`[data-favorite="${updated.id}"]`);
            if ($marker) $marker.textContent = updated.favorite ? '♥' : '';
            if (currentPhoto?.id !== updated.id) return;
            currentPhoto = updated;
            $favorite.textContent = updated.favorite ? '♥ Favorit entfernen' : '♡ Als Favorit';
            $tags.value = updated.tags.join(', ');
            $message.textContent = 'Gespeichert.';
        } catch (error) {
            if (currentPhoto?.id === photo.id) $message.textContent = error.message;
        } finally {
            $favorite.disabled = currentPhoto === null;
        }
    }

    let $grid = document.querySelector('.photo-grid');
    $main.addEventListener('click', event => {
        let $card = event.target.closest('[data-photo]');
        if ($card) openPhoto($card.dataset.photo);
        if (event.target.closest('#scan-start')) runWorker('scan');
        if (event.target.closest('#photo-retry')) loadMorePhotos();
    });

    let $photoLoader = document.querySelector('#photo-loader');
    let $photoLoadMessage = document.querySelector('#photo-load-message');
    let $photoRetry = document.querySelector('#photo-retry');
    let photosLoading = false;
    let photoLoadFailed = false;
    let photoObserver = new IntersectionObserver(
        entries => {
            if (entries.some(entry => entry.isIntersecting) && !photoLoadFailed) loadMorePhotos();
        },
        { rootMargin: '400px' }
    );

    async function loadMorePhotos() {
        if (photosLoading || !$photoLoader.dataset.next) return;
        let version = galleryVersion;
        photosLoading = true;
        photoLoadFailed = false;
        $photoRetry.hidden = true;
        $photoLoadMessage.textContent = 'Weitere Fotos werden geladen …';
        try {
            let response = await fetch($photoLoader.dataset.next, { signal: lifecycle.signal });
            if (!response.ok) throw new Error();
            let $page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (version !== galleryVersion) return;
            if (!$page.querySelector('.photo-grid')) throw new Error();
            let knownPhotos = new Set($cards.map($card => $card.dataset.photo));
            let $added = [...$page.querySelectorAll('[data-photo]')].filter(
                $card => !knownPhotos.has($card.dataset.photo)
            );
            $grid.append(...$added);
            updatePreviews();
            $cards.push(...$added);
            $next.disabled = currentIndex < 0 || currentIndex === $cards.length - 1;
            $photoLoader.dataset.next = $page.querySelector('#photo-loader')?.dataset.next || '';
            $photoLoadMessage.textContent = $photoLoader.dataset.next ? '' : 'Alle Fotos geladen.';
            photoObserver.unobserve($photoLoader);
            if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
        } catch {
            if (version !== galleryVersion || disposed) return;
            photoLoadFailed = true;
            $photoLoadMessage.textContent = 'Fotos konnten nicht geladen werden. Verbindung und Anmeldung prüfen.';
            $photoRetry.hidden = false;
        } finally {
            if (version === galleryVersion) photosLoading = false;
        }
    }

    if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
    function closePhoto() {
        if (history.state?.photoViewer) {
            history.back();
            return;
        }
        let url = new URL(location.href);
        url.searchParams.delete('image');
        history.replaceState(null, '', url);
        requestNumber++;
        currentPhoto = null;
        $viewer.close();
    }

    document.querySelector('.viewer-close').addEventListener('click', closePhoto);
    $viewer.addEventListener('cancel', event => {
        event.preventDefault();
        closePhoto();
    });
    $previous.addEventListener('click', () => openPhoto($cards[currentIndex - 1]?.dataset.photo));
    $next.addEventListener('click', () =>
        openPhoto(currentIndex >= 0 ? $cards[currentIndex + 1]?.dataset.photo : null)
    );
    $viewer.addEventListener('click', event => {
        if (event.target === $viewer) closePhoto();
    });
    $viewer.addEventListener('keydown', event => {
        if (['TEXTAREA', 'INPUT'].includes(event.target.tagName)) return;
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            openPhoto($cards[currentIndex - 1]?.dataset.photo);
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            openPhoto(currentIndex >= 0 ? $cards[currentIndex + 1]?.dataset.photo : null);
        }
    });
    $favorite.addEventListener('click', () => savePhoto('favorite'));
    document.querySelector('#tag-form').addEventListener('submit', event => {
        event.preventDefault();
        savePhoto('tags');
    });

    let $scanStart = document.querySelector('#scan-start');
    let $tagStart = document.querySelector('#tag-start');
    let $workerStop = document.querySelector('#worker-stop');
    let $workerProgress = document.querySelector('#worker-progress');
    let $workerMessage = document.querySelector('#worker-message');
    let $workerRefresh = document.querySelector('#worker-refresh');
    let $tagCount = document.querySelector('#tag-count');
    let $tagPending = document.querySelector('#tag-pending');
    let workerRunning = false;
    let workerStopRequested = false;

    async function runWorker(action) {
        if (workerRunning || disposed) return;
        workerRunning = true;
        workerStopRequested = false;
        $scanStart.disabled = true;
        $tagStart.disabled = true;
        $workerStop.disabled = false;
        $workerStop.hidden = false;
        $workerProgress.hidden = false;
        $workerMessage.textContent = action === 'scan' ? 'Fotos werden eingelesen …' : 'KI-Tags werden erstellt …';
        if (action === 'scan') $workerProgress.removeAttribute('value');
        let processed = 0;
        let tagAfterScan = false;
        try {
            do {
                let body = new FormData();
                body.set('csrf', csrf);
                body.set('action', action);
                let response = await fetch('./', { method: 'POST', body, signal: lifecycle.signal });
                if (response.status === 409) {
                    $workerMessage.textContent = 'Ein anderer Lauf ist aktiv. Warte auf Fortsetzung …';
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    continue;
                }
                if (response.status === 401 || response.status === 403) throw new Error('Bitte neu anmelden.');
                if (!response.headers.get('content-type')?.includes('application/json'))
                    throw new Error('Keine gültige Antwort. Bitte die Seite neu laden.');
                let result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Verarbeitung fehlgeschlagen.');
                processed += result.processed;
                $tagCount.textContent = `${result.stats.tagged} Fotos mit KI-Tags`;
                $tagPending.textContent = `${result.stats.total - result.stats.tagged} noch offen${result.stats.errors ? ` · ${result.stats.errors} mit Fehler` : ''}`;
                $workerProgress.max = Math.max(1, result.stats.total);
                if (action === 'tag') $workerProgress.value = result.stats.tagged;
                $workerRefresh.hidden = false;
                let summary = `${processed} Fotos ${action === 'scan' ? 'eingelesen' : 'getaggt'}.`;
                $workerMessage.textContent = summary;
                if (workerStopRequested) break;
                if (!result.more) {
                    tagAfterScan = action === 'scan' && result.stats.queued > 0;
                    $workerMessage.textContent = `Fertig. ${summary}`;
                    if (action === 'tag' && result.stats.errors > 0)
                        $workerMessage.textContent = `${summary} KI-Fehler: frühestens nach einer Stunde erneut starten.`;
                    break;
                }
            } while (!workerStopRequested);
        } catch (error) {
            $workerMessage.textContent = error.message;
        } finally {
            if (workerStopRequested)
                $workerMessage.textContent = `Gestoppt. ${processed} Fotos ${action === 'scan' ? 'eingelesen' : 'getaggt'}.`;
            workerRunning = false;
            $scanStart.disabled = false;
            $tagStart.disabled = false;
            $workerStop.hidden = true;
            if (action === 'scan') $workerProgress.value = parseInt($tagCount.textContent, 10);
        }
        if (tagAfterScan && !workerStopRequested && !disposed) runWorker('tag');
    }

    $tagStart.addEventListener('click', () => runWorker('tag'));
    $workerStop.addEventListener('click', () => {
        workerStopRequested = true;
        $workerStop.disabled = true;
        $workerMessage.textContent = 'Wird nach der laufenden Verarbeitung gestoppt …';
    });

    if (Number($tagPending.dataset.queued) > 0) runWorker('tag');

    return {
        syncPhoto() {
            let id = new URL(location.href).searchParams.get('image');
            if (id) {
                openPhoto(id, false);
                return;
            }
            requestNumber++;
            currentPhoto = null;
            $viewer.close();
        },
        update($page) {
            galleryVersion++;
            photoObserver.disconnect();
            $main.replaceChildren(...$page.querySelector('.main').childNodes);
            for (let selector of ['nav[aria-label="Bibliothek"]', '.nav-heading', '.album-nav']) {
                document.querySelector(selector).replaceChildren(...$page.querySelector(selector).childNodes);
            }
            updatePreviews();
            updateColumnSelector();
            $grid = document.querySelector('.photo-grid');
            $cards = [...$grid.querySelectorAll('[data-photo]')];
            $photoLoader = document.querySelector('#photo-loader');
            $photoLoadMessage = document.querySelector('#photo-load-message');
            $photoRetry = document.querySelector('#photo-retry');
            $scanStart = document.querySelector('#scan-start');
            $scanStart.disabled = workerRunning;
            photosLoading = false;
            photoLoadFailed = false;
            $workerRefresh.hidden = true;
            requestNumber++;
            currentPhoto = null;
            $viewer.close();
            if (!workerRunning) {
                $tagCount.textContent = $page.querySelector('#tag-count').textContent;
                $tagPending.textContent = $page.querySelector('#tag-pending').textContent;
                $workerProgress.max = $page.querySelector('#worker-progress').max;
                $workerProgress.value = $page.querySelector('#worker-progress').value;
            }
            if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
        },
        dispose() {
            disposed = true;
            workerStopRequested = true;
            galleryVersion++;
            photoObserver.disconnect();
            lifecycle.abort();
        }
    };
}
