let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function startNavigation({ login = false } = {}) {
    let updates = [];
    let requests = [];
    let listeners = {};
    let mounted = 0;
    let disposed = 0;
    let loginCsrf = [];
    let csrf = { content: 'initial' };
    let location = { href: 'https://photobutler.rebuhleiv.xyz/', origin: 'https://photobutler.rebuhleiv.xyz' };
    let history = [];
    let current;
    let ratingState = { version: 0, pending: null };
    function page(isLogin = false, title = 'photobutler') {
        let result = {
            title,
            body: { className: isLogin ? 'login-page' : '' },
            querySelector: selector => {
                if (selector === '#gallery-sort') return { dataset: { seed: '0123456789abcdef' } };
                if (selector === '.photo-grid') return isLogin ? null : {};
                if (selector === '#login-form') return isLogin ? {} : null;
                if (selector === 'meta[name="csrf-token"]') return { content: title };
                if (selector === '#navigation-message') return message;
            }
        };
        result.body.childNodes = [result];
        return result;
    }
    let message = { hidden: true, textContent: '' };
    current = page(login);
    let context = {
        AbortController,
        URL,
        URLSearchParams,
        FormData,
        location,
        history: Object.fromEntries(
            ['pushState', 'replaceState'].map(method => [
                method,
                (state, title, url) => {
                    history.push({ method, url: url.href });
                    location.href = url.href;
                }
            ])
        ),
        document: {
            title: 'photobutler',
            querySelector: selector =>
                selector === 'meta[name="csrf-token"]' ? csrf : current.querySelector(selector),
            documentElement: { classList: { add() {}, remove() {} } },
            body: {
                replaceChildren(next) {
                    current = next;
                }
            },
            addEventListener: (event, callback) => (listeners[event] = callback)
        },
        window: { scrollTo() {}, addEventListener: (event, callback) => (listeners[event] = callback) },
        initializeGallery: () => {
            mounted++;
            loginCsrf.push(csrf.content);
            return {
                update(next) {
                    updates.push(next);
                    let query = next.querySelector;
                    next.querySelector = selector => (selector === '#gallery-sort' ? null : query(selector));
                },
                dispose: () => disposed++,
                syncPhoto() {},
                get ratingVersion() {
                    return ratingState.version;
                },
                get pendingRatingSave() {
                    return ratingState.pending;
                }
            };
        },
        initializeLogin() {},
        DOMParser: class {
            parseFromString(value) {
                return value;
            }
        },
        fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve }))
    };
    let navigation = runInNewContext(
        readFileSync('assets/navigation.js', 'utf8').replace(/^import .*;\n/gm, '') + '\n({navigatePage});',
        context
    );
    return {
        ...navigation,
        page,
        ratingState,
        requests,
        updates,
        history,
        listeners,
        message,
        loginCsrf,
        get mounted() {
            return mounted;
        },
        get disposed() {
            return disposed;
        }
    };
}

test('gallery refresh updates content without restarting the worker or navigating the document', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?album=Urlaub');
    app.requests[0].resolve({ ok: true, text: async () => app.page(false, 'Urlaub · photobutler') });
    await navigation;
    assert.equal(app.mounted, 1);
    assert.equal(app.disposed, 0);
    assert.equal(app.updates.length, 1);
    assert.equal(app.history[0].method, 'pushState');
    assert.match(app.history[0].url, /album=Urlaub/);
});

test('a newer navigation discards an older response', async () => {
    let app = startNavigation();
    let first = app.navigatePage('?q=first');
    let second = app.navigatePage('?q=second');
    assert.equal(app.requests[0].options.signal.aborted, true);
    app.requests[1].resolve({ ok: true, text: async () => app.page(false, 'second') });
    await second;
    app.requests[0].resolve({ ok: true, text: async () => app.page(false, 'first') });
    await first;
    assert.equal(app.updates.length, 1);
    assert.equal(app.updates[0].title, 'second');
});

test('failed refresh keeps the current page and exposes an error instead of reloading', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?q=failed');
    app.requests[0].resolve({ ok: false });
    await assert.rejects(navigation, /aktualisiert/);
    assert.equal(app.updates.length, 0);
    assert.equal(app.history.length, 0);
    assert.equal(app.message.hidden, false);
});

test('login installs the rotated CSRF token before starting the gallery worker', async () => {
    let app = startNavigation({ login: true });
    let navigation = app.navigatePage('./', { replace: true });
    app.requests[0].resolve({ ok: true, text: async () => app.page(false, 'rotated-csrf') });
    await navigation;
    assert.deepEqual(app.loginCsrf, ['rotated-csrf']);
    assert.equal(app.history[0].method, 'replaceState');
});

test('logout disposes the gallery without a document reload', async () => {
    let app = startNavigation();
    let body = new FormData();
    body.set('action', 'logout');
    let navigation = app.navigatePage('./', { body, replace: true });
    assert.equal(app.requests[0].options.method, 'POST');
    app.requests[0].resolve({ ok: true, text: async () => app.page(true) });
    await navigation;
    assert.equal(app.disposed, 1);
});

test('refreshing the current URL replaces history and keeps the worker instance', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('https://photobutler.rebuhleiv.xyz/');
    app.requests[0].resolve({ ok: true, text: async () => app.page() });
    await navigation;
    assert.equal(app.history.length, 1);
    assert.equal(app.history[0].method, 'replaceState');
    assert.equal(app.mounted, 1);
    assert.equal(app.disposed, 0);
});

test('sorting keeps filters and image URLs, resets pagination and preserves the running gallery', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?q=Meer&album=Urlaub&tag=Meer&favorites=1&page=3&image=42');
    app.requests[0].resolve({ ok: true, text: async () => app.page() });
    await navigation;
    app.listeners.change({ target: { id: 'gallery-sort', value: 'oldest' } });
    let url = new URL(app.requests[1].url);
    assert.equal(url.searchParams.get('sort'), 'oldest');
    assert.equal(url.searchParams.has('page'), false);
    for (let [key, value] of Object.entries({ q: 'Meer', album: 'Urlaub', tag: 'Meer', favorites: '1', image: '42' }))
        assert.equal(url.searchParams.get(key), value);
    app.requests[1].resolve({ ok: true, text: async () => app.page() });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.mounted, 1);
    assert.equal(app.disposed, 0);
    assert.equal(app.updates.length, 2);
    assert.equal(app.history[1].method, 'pushState');
    app.listeners.change({ target: { id: 'gallery-columns', value: '7' } });
    assert.equal(app.requests.length, 2);
});

test('person filters preserve combined filters and the gallery worker without reloading', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?q=Meer&album=Urlaub&tag=Meer&favorites=1&sort=oldest&page=3');
    app.requests[0].resolve({ ok: true, text: async () => app.page() });
    await navigation;
    app.listeners.change({ target: { id: 'gallery-person', value: '42' } });
    let url = new URL(app.requests[1].url);
    assert.equal(url.searchParams.get('person'), '42');
    assert.equal(url.searchParams.has('page'), false);
    for (let [key, value] of Object.entries({
        q: 'Meer',
        album: 'Urlaub',
        tag: 'Meer',
        favorites: '1',
        sort: 'oldest'
    }))
        assert.equal(url.searchParams.get(key), value);
    app.requests[1].resolve({ ok: true, text: async () => app.page() });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.mounted, 1);
    assert.equal(app.disposed, 0);
});

test('relevance and random sorting preserve filters and a stable seed without remounting', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?q=Meer&tag=Meer&favorites=1&person=7&page=3');
    app.requests[0].resolve({ ok: true, text: async () => app.page() });
    await navigation;
    app.listeners.change({ target: { id: 'gallery-sort', value: 'random' } });
    app.requests[1].resolve({ ok: true, text: async () => app.page() });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(new URL(app.history.at(-1).url).searchParams.get('seed'), '0123456789abcdef');
    app.listeners.change({ target: { id: 'gallery-relevance', value: 'relevant' } });
    let url = new URL(app.requests[2].url);
    for (let [key, value] of Object.entries({
        q: 'Meer',
        tag: 'Meer',
        favorites: '1',
        person: '7',
        sort: 'random',
        seed: '0123456789abcdef',
        relevance: 'relevant'
    }))
        assert.equal(url.searchParams.get(key), value);
    assert.equal(url.searchParams.has('page'), false);
    app.requests[2].resolve({ ok: true, text: async () => app.page() });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.mounted, 1);
    assert.equal(app.disposed, 0);
});

test('expired authentication during random navigation still displays the login page', async () => {
    let app = startNavigation();
    let expired = app.page(true);
    let query = expired.querySelector;
    expired.querySelector = selector => (selector === '#gallery-sort' ? null : query(selector));
    let navigation = app.navigatePage('?sort=random&seed=0123456789abcdef');
    app.requests[0].resolve({ ok: true, text: async () => expired });
    await navigation;
    assert.equal(app.disposed, 1);
    assert.equal(app.message.hidden, true);
});

test('favorites filter keeps relevance and random seed while restarting pagination', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?sort=random&seed=0123456789abcdef&relevance=relevant&page=2&offset=58');
    app.requests[0].resolve({ ok: true, text: async () => app.page() });
    await navigation;
    app.listeners.change({ target: { id: 'gallery-favorites', value: 'none' } });
    let url = new URL(app.requests[1].url);
    assert.equal(url.searchParams.get('favorites'), 'none');
    assert.equal(url.searchParams.get('relevance'), 'relevant');
    assert.equal(url.searchParams.has('offset'), false);
    assert.equal(url.searchParams.get('seed'), '0123456789abcdef');
    assert.equal(url.searchParams.has('page'), false);
    app.requests[1].resolve({ ok: true, text: async () => app.page() });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.mounted, 1);
});

test('explicit filter navigation waits for rating saves before fetching matching photos', async () => {
    let app = startNavigation();
    let finish;
    app.ratingState.pending = new Promise(resolve => {
        finish = resolve;
    });
    let navigation = app.navigatePage('?relevance=excluded');
    assert.equal(app.requests.length, 0);
    app.ratingState.pending = null;
    app.ratingState.version++;
    finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.requests.length, 1);
    app.requests[0].resolve({ ok: true, text: async () => app.page(false, 'excluded') });
    await navigation;
    assert.equal(app.updates.length, 1);
});

test('filter responses overtaken by a rating are discarded before touching the gallery', async () => {
    let app = startNavigation();
    let navigation = app.navigatePage('?favorites=1');
    app.ratingState.version++;
    app.requests[0].resolve({ ok: true, text: async () => app.page(false, 'stale') });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.updates.length, 0);
    assert.equal(app.requests.length, 2);
    app.requests[1].resolve({ ok: true, text: async () => app.page(false, 'current') });
    await navigation;
    assert.equal(app.updates.length, 1);
    assert.equal(app.updates[0].title, 'current');
});
