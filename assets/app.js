import { initializeJobs } from './?asset=jobs.js';
import { DetailPreloader } from './?asset=preloader.js';

export function initializeGallery(navigatePage) {
    let lifecycle = new AbortController();
    let disposed = false;
    let galleryVersion = 0;
    let $main = document.querySelector('.main');
    let $sort = document.querySelector('#gallery-sort');
    if ($sort.value === 'random') {
        let url = new URL(location.href);
        url.searchParams.set('seed', $sort.dataset.seed);
        history.replaceState(history.state, '', url);
    }
    let jobs = initializeJobs($main);
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
        if (event.target.id !== 'gallery-columns' || !['3', '4', '5', '6', '7', '8', '9'].includes(event.target.value))
            return;
        document.documentElement.style.setProperty('--gallery-columns', event.target.value);
        schedulePreloads();
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
    let $favorite = document.querySelector('#viewer-favorite');
    let $tags = document.querySelector('#viewer-tags');
    let $message = document.querySelector('#viewer-message');
    let $persons = document.querySelector('#viewer-persons');
    let $download = document.querySelector('#viewer-download');
    let $previous = document.querySelector('.viewer-previous');
    let $next = document.querySelector('.viewer-next');
    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let slideshow = false;
    let slideshowRun = 0;
    let slideTimer = null;
    let $slideshowStop = document.querySelector('#slideshow-stop');
    let ratingsPending = 0;
    let ratingVersion = 0;
    let ratingsSettled = null;
    let resolveRatings = null;
    let ratingFailure = '';
    let pendingRatings = new Set();
    let currentPhoto = null;
    let currentIndex = 0;
    let requestNumber = 0;
    let preloader = new DetailPreloader();
    let preloadFrame = null;
    let $hoveredCard = null;
    function stopSlideshow() {
        slideshow = false;
        slideshowRun++;
        clearTimeout(slideTimer);
        slideTimer = null;
        $slideshowStop.hidden = true;
        $viewer.classList.remove('slideshow');
        if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    }

    function scheduleSlideshow() {
        if (!slideshow || !currentPhoto || !$image.complete || !$image.naturalWidth) return;
        clearTimeout(slideTimer);
        let run = slideshowRun;
        slideTimer = setTimeout(async () => {
            if (!slideshow || run !== slideshowRun) return;
            while (currentIndex === $cards.length - 1 && $photoLoader.dataset.next) {
                if (photosLoading) {
                    slideTimer = setTimeout(scheduleSlideshow, 250);
                    return;
                }
                await loadMorePhotos();
                if (!slideshow || run !== slideshowRun) return;
                if (photoLoadFailed) {
                    closePhoto();
                    $photoLoadMessage.textContent = 'Slideshow gestoppt: Weitere Fotos konnten nicht geladen werden.';
                    return;
                }
            }
            if (currentIndex >= $cards.length - 1) {
                closePhoto();
                $photoLoadMessage.textContent = 'Slideshow beendet.';
                return;
            }
            await openPhoto($cards[currentIndex + 1].dataset.photo);
        }, 6000);
    }

    $slideshowStop.addEventListener('click', closePhoto);
    document.addEventListener(
        'fullscreenchange',
        () => {
            if (slideshow && !document.fullscreenElement) closePhoto();
        },
        { signal: lifecycle.signal }
    );
    function schedulePreloads() {
        preloader.paused = true;
        if (preloadFrame !== null) return;
        preloadFrame = requestAnimationFrame(() => {
            preloadFrame = null;
            if (!disposed)
                preloader.update(
                    $cards,
                    $viewer.open ? currentIndex : null,
                    $viewer.open && !$image.complete,
                    $hoveredCard
                );
        });
    }
    window.addEventListener('scroll', schedulePreloads, { passive: true, signal: lifecycle.signal });
    window.addEventListener('resize', schedulePreloads, { signal: lifecycle.signal });
    let preloadObserver = new ResizeObserver(schedulePreloads);
    preloadObserver.observe($main);
    $image.addEventListener('load', () => {
        schedulePreloads();
        scheduleSlideshow();
    });
    $image.addEventListener('error', schedulePreloads);
    schedulePreloads();

    async function openPhoto(id, updateHistory = true) {
        id = Number(id);
        if (!Number.isSafeInteger(id) || id < 1) return;
        slideshowRun++;
        clearTimeout(slideTimer);
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
        $tags.value = '';
        $persons.replaceChildren();
        $favorite.disabled = true;
        $download.removeAttribute('href');
        $previous.disabled = currentIndex <= 0;
        $next.disabled = currentIndex < 0 || currentIndex === $cards.length - 1;
        $hoveredCard = null;
        preloader.update($cards, currentIndex, true);
        $image.fetchPriority = 'high';
        $image.onerror = () => {
            $image.onerror = slideshow
                ? () => {
                      $image.onerror = null;
                      if (slideshow) {
                          closePhoto();
                          $photoLoadMessage.textContent = 'Slideshow gestoppt: Bild konnte nicht geladen werden.';
                      }
                  }
                : null;
            $image.src = `?photo=${id}&size=display`;
        };
        $image.src = `?photo=${id}&size=original`;
        updatePreview($image);
        if (!$viewer.open) $viewer.showModal();
        schedulePreloads();
        try {
            let response = await fetch(`?detail=${id}`, { signal: lifecycle.signal });
            if (!response.ok)
                throw new Error('Das Foto konnte nicht geladen werden. Bitte neu anmelden oder die Seite neu laden.');
            let photo = await response.json();
            if (request !== requestNumber) return;
            currentPhoto = photo;
            for (let person of photo.persons || []) {
                let $link = document.createElement('a');
                $link.className = 'chip';
                let personUrl = new URL(location.href);
                personUrl.searchParams.set('person', person.id);
                for (let key of ['image', 'page']) personUrl.searchParams.delete(key);
                $link.href = personUrl.href;
                let $crop = document.createElement('img');
                $crop.src = `?face=${person.cover}`;
                $crop.alt = '';
                $link.append($crop, document.createTextNode(person.name || `Person ${person.id}`));
                $persons.append($link);
            }
            $title.textContent = photo.name;
            $date.textContent = `${photo.taken.slice(0, 10)} · ${photo.album} · ${photo.width} × ${photo.height}`;
            $description.textContent = photo.description;
            $image.alt = photo.description || photo.name;
            $tags.value = photo.tags.join(', ');
            $favorite.textContent = photo.favorite ? '♥ Favorit entfernen' : '♡ Als Favorit';
            $favorite.disabled = false;
            $download.href = `?photo=${photo.id}&size=original&download=1`;
            scheduleSlideshow();
        } catch (error) {
            if (request === requestNumber) {
                if (slideshow) {
                    closePhoto();
                    $photoLoadMessage.textContent = error.message;
                    return;
                }
                stopSlideshow();
                $title.textContent = 'Foto nicht verfügbar';
                $message.textContent = error.message;
            }
        }
    }

    $viewer.addEventListener('click', async event => {
        if (!['face-retry', 'face-erase'].includes(event.target.id) || !currentPhoto) return;
        let erase = event.target.id === 'face-erase';
        if (
            erase &&
            !window.confirm(
                'Gesichtsdaten dieses Fotos einschließlich manueller Korrekturen löschen? Erst „Gesichter erneut prüfen“ aktiviert die Analyse wieder.'
            )
        )
            return;
        let id = currentPhoto.id;
        let body = new FormData();
        body.set('action', erase ? 'face-erase' : 'face-retry');
        body.set('id', id);
        body.set('csrf', csrf);
        try {
            let response = await fetch('./', { method: 'POST', body, signal: lifecycle.signal });
            if (!response.ok) throw new Error('Gesichtsdaten konnten nicht aktualisiert werden.');
            if (currentPhoto?.id !== id) return;
            await openPhoto(id, false);
            $message.textContent = erase
                ? 'Gesichtsdaten gelöscht; automatische Analyse ausgesetzt.'
                : 'Gesichtsanalyse vorgemerkt. KI-Tags bleiben erhalten.';
        } catch (error) {
            $message.textContent = error.message;
        }
    });

    function renderPriority(id, value) {
        if (disposed) return;
        let $card = [...document.querySelectorAll('[data-photo]')].find($item => Number($item.dataset.photo) === id);
        let $buttons = [...document.querySelectorAll(`[data-priority-photo="${id}"]`)];
        let $marker = document.querySelector(`[data-favorite="${id}"]`);
        if ($marker) $marker.textContent = value === 1 ? '♥' : '♡';
        $buttons.forEach($button =>
            $button.setAttribute('aria-pressed', String(Number($button.dataset.priority) === value))
        );
        let mode = document.querySelector('#gallery-favorites').value;
        let relevance = document.querySelector('#gallery-relevance').value;
        let hidden =
            (relevance === 'relevant' && value !== 1) ||
            (relevance === 'excluded' && value !== -1) ||
            (relevance === 'unrated' && value !== 0) ||
            (mode === '1' && value !== 1) ||
            (mode === 'none' && value === 1);
        if ($card) {
            $card.dataset.priority = String(value);
            $card.parentElement.hidden = hidden;
            $cards = [...document.querySelectorAll('[data-photo]')].filter($item => !$item.parentElement.hidden);
            currentIndex = $cards.findIndex($item => Number($item.dataset.photo) === currentPhoto?.id);
            if (hidden && $hoveredCard === $card) $hoveredCard = null;
            schedulePreloads();
        }
        if (currentPhoto?.id === id) {
            currentPhoto.priority = value;
            currentPhoto.favorite = value === 1;
            $favorite.textContent = value === 1 ? '♥ Favorit entfernen' : '♡ Als Favorit';
        }
    }

    async function savePhoto(action, photo = currentPhoto, priority = null) {
        if (!photo) return;
        let $buttons = [...document.querySelectorAll(`[data-priority-photo="${photo.id}"]`)];
        if (pendingRatings.has(photo.id) || $buttons.some($button => $button.disabled)) return;
        $buttons.forEach($button => {
            $button.disabled = true;
        });
        let rating = action === 'priority' || action === 'favorite';
        let $card = $cards.find($card => Number($card.dataset.photo) === photo.id);
        let previousPriority = Number($card?.dataset.priority ?? photo.priority ?? (photo.favorite ? 1 : 0));
        let desiredPriority = action === 'priority' ? priority : photo.favorite ? 0 : 1;
        let body = new FormData();
        body.set('csrf', csrf);
        body.set('action', action);
        body.set('id', photo.id);
        body.set('tags', $tags.value);
        body.set('favorite', photo.favorite ? '0' : '1');
        if (action === 'priority') body.set('priority', String(priority));
        $favorite.disabled = true;
        if (rating) {
            if (ratingsPending === 0) {
                ratingFailure = '';
                ratingsSettled = new Promise(resolve => {
                    resolveRatings = resolve;
                });
            }
            ratingsPending++;
            ratingVersion++;
            pendingRatings.add(photo.id);
            renderPriority(photo.id, desiredPriority);
        }
        try {
            let response = await fetch('./', { method: 'POST', body, signal: lifecycle.signal });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Speichern fehlgeschlagen. Sitzung und Tags prüfen (maximal 20 Tags, je 60 Zeichen).');
            let updated = await response.json();
            if (rating) {
                renderPriority(photo.id, updated.priority);
            }
            if (currentPhoto?.id !== updated.id) return;
            currentPhoto = updated;
            $favorite.textContent = updated.favorite ? '♥ Favorit entfernen' : '♡ Als Favorit';
            $tags.value = updated.tags.join(', ');
            $message.textContent = 'Gespeichert.';
        } catch (error) {
            if (rating) {
                renderPriority(photo.id, previousPriority);
                ratingFailure = error.message;
            }
            if (currentPhoto?.id === photo.id) $message.textContent = error.message;
            if (!$viewer.open) $photoLoadMessage.textContent = error.message;
        } finally {
            $buttons.forEach($button => {
                $button.disabled = false;
            });
            $favorite.disabled = currentPhoto === null;
            if (rating) {
                ratingsPending--;
                pendingRatings.delete(photo.id);
                if (ratingsPending === 0) {
                    resolveRatings();
                    if (photoLoadDeferred) loadMorePhotos();
                }
            }
        }
    }

    for (let eventName of ['mouseover', 'mouseout']) {
        $main.addEventListener(eventName, event => {
            let $card = event.target.closest('[data-photo]');
            if (!$card || $card === event.relatedTarget?.closest?.('[data-photo]')) return;
            $hoveredCard = eventName === 'mouseover' && !$viewer.open ? $card : null;
            schedulePreloads();
        });
    }

    let $grid = document.querySelector('.photo-grid');
    $main.addEventListener('click', async event => {
        let $rating = event.target.closest('[data-priority-photo]');
        if ($rating) {
            event.preventDefault();
            let $card = $cards.find($card => $card.dataset.photo === $rating.dataset.priorityPhoto);
            if (!$card || $rating.disabled) return;
            let priority = $card.dataset.priority === $rating.dataset.priority ? 0 : Number($rating.dataset.priority);
            await savePhoto('priority', { id: Number($card.dataset.photo) }, priority);
            return;
        }
        if (event.target.closest('#gallery-slideshow')) {
            if (Number(new URL(location.href).searchParams.get('page')) > 1) {
                let url = new URL(location.href);
                url.searchParams.delete('page');
                url.searchParams.delete('offset');
                url.searchParams.delete('image');
                try {
                    await navigatePage(url.href);
                } catch {
                    return;
                }
            }
            if (!$cards.length) return;
            stopSlideshow();
            slideshow = true;
            $viewer.classList.add('slideshow');
            await document.documentElement.requestFullscreen?.().catch(() => {});
            $slideshowStop.hidden = false;
            await openPhoto($cards[0].dataset.photo);
            return;
        }
        let $card = event.target.closest('[data-photo]');
        if ($card) openPhoto($card.dataset.photo);
        if (event.target.closest('#photo-retry')) loadMorePhotos();
    });

    let $photoLoader = document.querySelector('#photo-loader');
    let $photoLoadMessage = document.querySelector('#photo-load-message');
    let $photoRetry = document.querySelector('#photo-retry');
    let photosLoading = false;
    let photoLoadDeferred = false;
    let photoLoadFailed = false;
    let photoObserver = new IntersectionObserver(
        entries => {
            if (entries.some(entry => entry.isIntersecting) && !photoLoadFailed) loadMorePhotos();
        },
        { rootMargin: '400px' }
    );

    async function loadMorePhotos() {
        if (photosLoading || !$photoLoader.dataset.next) return;
        if (ratingsPending > 0) {
            photoLoadDeferred = true;
            return;
        }
        let version = galleryVersion;
        let ratings = ratingVersion;
        photoLoadDeferred = false;
        photosLoading = true;
        photoLoadFailed = false;
        $photoRetry.hidden = true;
        $photoLoadMessage.textContent = ratingFailure || 'Weitere Fotos werden geladen …';
        try {
            let url = new URL($photoLoader.dataset.next, location.href);
            // Filtered removals shift SQL offsets; count only the retained matching tiles.
            url.searchParams.set('offset', Number($photoLoader.dataset.offset || 0) + $cards.length);
            let response = await fetch(url.href, { signal: lifecycle.signal });
            if (!response.ok) throw new Error();
            let $page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (version !== galleryVersion) return;
            if (ratings !== ratingVersion) {
                photoLoadDeferred = true;
                return;
            }
            if (!$page.querySelector('.photo-grid')) throw new Error();
            let knownPhotos = new Set([...document.querySelectorAll('[data-photo]')].map($card => $card.dataset.photo));
            let $added = [...$page.querySelectorAll('[data-photo]')].filter(
                $card => !knownPhotos.has($card.dataset.photo)
            );
            $grid.append(...$added.map($card => $card.parentElement));
            updatePreviews();
            $cards.push(...$added);
            schedulePreloads();
            $next.disabled = currentIndex < 0 || currentIndex === $cards.length - 1;
            $photoLoader.dataset.next = $page.querySelector('#photo-loader')?.dataset.next || '';
            $photoLoadMessage.textContent = ratingFailure || ($photoLoader.dataset.next ? '' : 'Alle Fotos geladen.');
            photoObserver.unobserve($photoLoader);
            if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
        } catch {
            if (version !== galleryVersion || disposed) return;
            photoLoadFailed = true;
            $photoLoadMessage.textContent = 'Fotos konnten nicht geladen werden. Verbindung und Anmeldung prüfen.';
            $photoRetry.hidden = false;
        } finally {
            if (version === galleryVersion) {
                photosLoading = false;
                if (photoLoadDeferred && ratingsPending === 0 && !disposed) loadMorePhotos();
            }
        }
    }

    if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
    function closePhoto() {
        stopSlideshow();
        requestNumber++;
        currentPhoto = null;
        $viewer.close();
        schedulePreloads();
        if (history.state?.photoViewer) {
            history.back();
            return;
        }
        let url = new URL(location.href);
        url.searchParams.delete('image');
        history.replaceState(null, '', url);
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

    return {
        syncPhoto() {
            stopSlideshow();
            let id = new URL(location.href).searchParams.get('image');
            if (id && ($cards.some($card => $card.dataset.photo === id) || $viewer.dataset.selectedPhoto === id)) {
                openPhoto(id, false);
                return;
            }
            if (id) {
                let url = new URL(location.href);
                url.searchParams.delete('image');
                history.replaceState(null, '', url);
            }
            requestNumber++;
            currentPhoto = null;
            $viewer.close();
            schedulePreloads();
        },
        get pendingRatingSave() {
            return ratingsPending > 0 ? ratingsSettled : null;
        },
        get ratingVersion() {
            return ratingVersion;
        },
        update($page) {
            stopSlideshow();
            $viewer.dataset.selectedPhoto = $page.querySelector('#viewer').dataset.selectedPhoto;
            galleryVersion++;
            $hoveredCard = null;
            photoObserver.disconnect();
            preloader.update([], null, true);
            $main.replaceChildren(...$page.querySelector('.main').childNodes);
            document.querySelector('.brand').href = $page.querySelector('.brand').href;
            document
                .querySelector('nav[aria-label="Bibliothek"]')
                .replaceChildren(...$page.querySelector('nav[aria-label="Bibliothek"]').childNodes);
            updatePreviews();
            updateColumnSelector();
            $grid = document.querySelector('.photo-grid');
            $cards = [...document.querySelectorAll('[data-photo]')];
            $photoLoader = document.querySelector('#photo-loader');
            $photoLoadMessage = document.querySelector('#photo-load-message');
            $photoRetry = document.querySelector('#photo-retry');
            photosLoading = false;
            photoLoadDeferred = false;
            photoLoadFailed = false;
            ratingFailure = '';
            requestNumber++;
            currentPhoto = null;
            $viewer.close();
            schedulePreloads();
            jobs.update();
            if ($photoLoader.dataset.next) photoObserver.observe($photoLoader);
        },
        dispose() {
            stopSlideshow();
            disposed = true;
            jobs.dispose();
            galleryVersion++;
            photoObserver.disconnect();
            preloadObserver.disconnect();
            cancelAnimationFrame(preloadFrame);
            preloader.dispose();
            lifecycle.abort();
        }
    };
}
