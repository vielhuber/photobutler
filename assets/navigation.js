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
        let response = await fetch(url, {
            signal: controller.signal,
            ...(body ? { method: 'POST', body } : {})
        });
        if (!response.ok) throw new Error('Seite konnte nicht aktualisiert werden. Bitte erneut versuchen.');
        let $page = new DOMParser().parseFromString(await response.text(), 'text/html');
        if (controller.signal.aborted) return;
        let isGallery = Boolean($page.querySelector('.photo-grid'));
        if (!isGallery && !$page.querySelector('#login-form')) throw new Error('Keine gültige Seitenantwort.');
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
        let destination = new URL(url, location.href);
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
    if (document.querySelector('.photo-grid')) gallery = initializeGallery();
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
        $link.target ||
        $link.hasAttribute('download')
    )
        return;
    event.preventDefault();
    navigatePage(url.href).catch(() => {});
});

document.addEventListener('submit', event => {
    let $form = event.target;
    if (!$form.matches('.search, .logout-form')) return;
    event.preventDefault();
    let body = new FormData($form);
    let url = new URL('./', location.href);
    if ($form.matches('.search')) {
        url.search = new URLSearchParams(body).toString();
        navigatePage(url.href).catch(() => {});
        return;
    }
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
gallery?.syncPhoto();
