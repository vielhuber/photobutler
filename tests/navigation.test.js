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
    function page(isLogin = false, title = 'photobutler') {
        let result = {
            title,
            body: { className: isLogin ? 'login-page' : '' },
            querySelector: selector => {
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
            return { update: next => updates.push(next), dispose: () => disposed++, syncPhoto() {} };
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
