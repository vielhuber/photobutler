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
            error: 'KI-Fehler · erneuter Versuch folgt',
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
