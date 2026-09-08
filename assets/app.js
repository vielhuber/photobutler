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
window.addEventListener('resize', () => resizeSidebar(sidebarWidth));
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

async function openPhoto(index) {
    if (index < 0 || index >= $cards.length) return;
    currentIndex = index;
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
    $previous.disabled = index === 0;
    $next.disabled = index === $cards.length - 1;
    $image.src = `?photo=${$cards[index].dataset.photo}&size=thumb`;
    if (!$viewer.open) $viewer.showModal();
    try {
        let response = await fetch(`?detail=${$cards[index].dataset.photo}`);
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
        if (request === requestNumber) $message.textContent = error.message;
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
        let response = await fetch('./', { method: 'POST', body });
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

$cards.forEach(($card, index) => $card.addEventListener('click', () => openPhoto(index)));
document.querySelector('.viewer-close').addEventListener('click', () => $viewer.close());
$previous.addEventListener('click', () => openPhoto(currentIndex - 1));
$next.addEventListener('click', () => openPhoto(currentIndex + 1));
$viewer.addEventListener('click', event => {
    if (event.target === $viewer) $viewer.close();
});
$viewer.addEventListener('keydown', event => {
    if (['TEXTAREA', 'INPUT'].includes(event.target.tagName)) return;
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        openPhoto(currentIndex - 1);
    }
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        openPhoto(currentIndex + 1);
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
    if (workerRunning) return;
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
    try {
        do {
            let body = new FormData();
            body.set('csrf', csrf);
            body.set('action', action);
            let response = await fetch('./', { method: 'POST', body });
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
            if (workerStopRequested) {
                $workerMessage.textContent = `Gestoppt. ${summary}`;
                break;
            }
            if (!result.more) {
                $workerMessage.textContent = `Fertig. ${summary}`;
                if (action === 'tag' && result.stats.errors > 0)
                    $workerMessage.textContent = `${summary} KI-Fehler: frühestens nach einer Stunde erneut starten.`;
                break;
            }
        } while (!workerStopRequested);
    } catch (error) {
        $workerMessage.textContent = error.message;
    } finally {
        workerRunning = false;
        $scanStart.disabled = false;
        $tagStart.disabled = false;
        $workerStop.hidden = true;
        $workerProgress.hidden = true;
    }
}

$scanStart.addEventListener('click', () => runWorker('scan'));
$tagStart.addEventListener('click', () => runWorker('tag'));
$workerStop.addEventListener('click', () => {
    workerStopRequested = true;
    $workerStop.disabled = true;
    $workerMessage.textContent = 'Wird nach der laufenden Verarbeitung gestoppt …';
});
