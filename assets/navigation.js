import { initializeGallery } from './?asset=app.js';
import { initializeLogin } from './?asset=login.js';

let gallery = null;
let navigationController = null;
let loadedUrl = new URL(location.href);
loadedUrl.searchParams.delete('image');

async function navigatePage(url, { replace = false, body = null } = {}) {
    navigationController?.abort();
    let controller = (navigationController = new AbortController());
    let $message = document.querySelector('#navigation-message');
    $message.hidden = true;
    document.documentElement.classList.add('page-loading');
    try {
        let $page;
        let ratingVersion;
        do {
            while (gallery?.pendingRatingSave) await gallery.pendingRatingSave;
            if (controller.signal.aborted) return;
            ratingVersion = gallery?.ratingVersion;
            let response = await fetch(url, {
                signal: controller.signal,
                ...(body ? { method: 'POST', body } : {})
            });
            if (!response.ok) throw new Error('Seite konnte nicht aktualisiert werden. Bitte erneut versuchen.');
            $page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (controller.signal.aborted) return;
        } while (!body && ratingVersion !== gallery?.ratingVersion);
        let isGallery = Boolean($page.querySelector('.photo-grid'));
        if (!isGallery && !$page.querySelector('#login-form')) throw new Error('Keine gültige Seitenantwort.');
        let destination = new URL(url, location.href);
        if (isGallery && destination.searchParams.get('sort') === 'random') {
            destination.searchParams.set('seed', $page.querySelector('#gallery-sort').dataset.seed);
        }
        document.title = $page.title;
        document.querySelector('meta[name="csrf-token"]').content =
            $page.querySelector('meta[name="csrf-token"]').content;
        if (gallery && isGallery) {
            gallery.update($page);
        } else {
            gallery?.dispose();
            gallery = null;
            document.body.className = $page.body.className;
            document.body.replaceChildren(...$page.body.childNodes);
            initializePage();
        }
        if (replace || destination.href === location.href) history.replaceState(null, '', destination);
        if (!replace && destination.href !== location.href) history.pushState(null, '', destination);
        loadedUrl = new URL(destination);
        loadedUrl.searchParams.delete('image');
        gallery?.syncPhoto();
        window.scrollTo(0, 0);
    } catch (error) {
        if (controller.signal.aborted) return;
        $message.textContent = error.message;
        $message.hidden = false;
        throw error;
    } finally {
        if (navigationController === controller) document.documentElement.classList.remove('page-loading');
    }
}

function initializePage() {
    if (document.querySelector('.photo-grid')) gallery = initializeGallery(navigatePage);
    if (document.querySelector('#login-form')) initializeLogin(navigatePage);
}

document.addEventListener('click', event => {
    let $link = event.target.closest('a[href]');
    if (
        !$link ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    )
        return;
    let url = new URL($link.href, location.href);
    if (
        url.origin !== location.origin ||
        url.searchParams.has('photo') ||
        url.searchParams.has('face') ||
        $link.target ||
        $link.hasAttribute('download')
    )
        return;
    event.preventDefault();
    navigatePage(url.href).catch(() => {});
});

document.addEventListener('change', event => {
    let filters = {
        'gallery-sort': 'sort',
        'gallery-person': 'person',
        'gallery-relevance': 'relevance',
        'gallery-favorites': 'favorites'
    };
    if (!(event.target.id in filters)) return;
    let url = new URL(location.href);
    url.searchParams.set(filters[event.target.id], event.target.value);
    if (event.target.id === 'gallery-sort') url.searchParams.delete('seed');
    url.searchParams.delete('page');
    url.searchParams.delete('offset');
    navigatePage(url.href).catch(() => {});
});

document.addEventListener('submit', async event => {
    let $form = event.target;
    if (!$form.matches('.person-form')) return;
    event.preventDefault();
    let target = $form.querySelector('select')?.selectedOptions[0]?.textContent || '';
    if ($form.dataset.confirm && !window.confirm(`${$form.dataset.confirm}\n${target}`)) return;
    let body = new FormData($form);
    body.set('csrf', document.querySelector('meta[name="csrf-token"]').content);
    let $button = $form.querySelector('button');
    $button.disabled = true;
    try {
        let response = await fetch('./', { method: 'POST', body });
        if (!response.headers.get('content-type')?.includes('application/json'))
            throw new Error('Speichern fehlgeschlagen. Bitte neu anmelden.');
        let result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Speichern fehlgeschlagen.');
        let url = new URL(location.href);
        url.searchParams.set('person', result.person);
        await navigatePage(url.href, { replace: true });
    } catch (error) {
        let $message = document.querySelector('#navigation-message');
        $message.textContent = error.message;
        $message.hidden = false;
    } finally {
        $button.disabled = false;
    }
});

document.addEventListener('submit', event => {
    let $form = event.target;
    if (!$form.matches('.logout-form')) return;
    event.preventDefault();
    let body = new FormData($form);
    let url = new URL('./', location.href);
    navigatePage(url.href, { body, replace: true }).catch(() => {});
});

window.addEventListener('popstate', () => {
    let url = new URL(location.href);
    url.searchParams.delete('image');
    if (gallery && url.href === loadedUrl.href) {
        gallery.syncPhoto();
        return;
    }
    navigatePage(location.href, { replace: true }).catch(() => {});
});
initializePage();
loadedUrl = new URL(location.href);
loadedUrl.searchParams.delete('image');
gallery?.syncPhoto();
