let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function startApp({
    queued = 0,
    responses = [],
    url = 'https://photobutler.rebuhleiv.xyz/',
    previewLoaded = false
} = {}) {
    let location = { href: url };
    let history = {
        state: null,
        pushState(state, title, url) {
            this.state = state;
            location.href = url.href;
        },
        replaceState(state, title, url) {
            this.state = state;
            location.href = url.href;
        },
        back() {
            this.backRequested = true;
        }
    };
    let elements = new Map();
    let requests = [];
    let observers = [];
    let timers = [];
    function element(selector) {
        if (!elements.has(selector)) {
            elements.set(selector, {
                dataset: {},
                listeners: {},
                children: [],
                textContent: '',
                style: {
                    setProperty(name, value) {
                        this[name] = value;
                    }
                },
                classList: { add() {}, remove() {} },
                addEventListener(event, callback) {
                    this.listeners[event] = callback;
                },
                setAttribute(name, value) {
                    this[name] = value;
                },
                getAttribute(name) {
                    return this[name] ?? null;
                },
                matches(selector) {
                    return selector === '[data-preview-state] > img' && Boolean(this.parentElement);
                },
                removeAttribute(name) {
                    delete this[name];
                },
                append(...children) {
                    this.children.push(...children);
                },
                close() {
                    this.open = false;
                },
                replaceChildren(...children) {
                    this.children = children;
                },
                querySelectorAll: () => [],
                getBoundingClientRect: () => ({ width: 250 }),
                showModal() {
                    this.open = true;
                }
            });
        }
        return elements.get(selector);
    }
    let $card = element('first-card');
    $card.dataset.photo = '1';
    let $preview = element('preview-image');
    $preview.parentElement = $card;
    $preview.src = '?photo=1&size=display';
    $preview.complete = previewLoaded;
    $preview.naturalWidth = previewLoaded ? 64 : 0;
    element('#viewer-image').parentElement = element('.viewer-stage');
    element('#tag-pending').dataset.queued = String(queued);
    element('#photo-loader').dataset.next = '?album=Urlaub&page=2';
    let context = {
        document: {
            querySelector: element,
            querySelectorAll: selector =>
                selector === '[data-preview-state] > img' ? [$preview, element('#viewer-image')] : [$card],
            addEventListener: (event, callback) => element('document').addEventListener(event, callback),
            documentElement: element('html')
        },
        window: { addEventListener() {} },
        innerWidth: 1440,
        getComputedStyle: () => ({ getPropertyValue: () => '250' }),
        localStorage: {
            getItem() {},
            setItem(key, value) {
                element('storage')[key] = value;
            }
        },
        FormData,
        URL,
        location,
        history,
        AbortController,
        setTimeout: callback => timers.push(callback),
        IntersectionObserver: class {
            constructor(callback) {
                observers.push(callback);
            }
            observe() {}
            unobserve() {}
            disconnect() {}
        },
        DOMParser: class {
            parseFromString(value) {
                return value;
            }
        },
        fetch: async (url, options) => {
            requests.push({ url, action: options?.body?.get('action') });
            let response = responses.shift();
            if (response instanceof Error) throw response;
            if (typeof response === 'function') response = await response();
            return {
                ok: true,
                status: 200,
                headers: { get: () => 'application/json; text/html' },
                json: async () => response,
                text: async () => response,
                ...response?.http
            };
        }
    };
    let gallery = runInNewContext(
        readFileSync('assets/app.js', 'utf8').replace('export ', '') + '\ninitializeGallery();',
        context
    );
    return { element, requests, observers, timers, gallery, location, history };
}

async function settle() {
    await new Promise(resolve => setImmediate(resolve));
}

let complete = { processed: 1, more: false, stats: { total: 1, tagged: 1, errors: 0, queued: 0 } };

test('pending photos start tagging automatically and finish with persistent progress', async () => {
    let app = startApp({ queued: 1, responses: [complete] });
    await settle();
    assert.deepEqual(
        app.requests.map(request => request.action),
        ['tag']
    );
    assert.equal(app.element('#tag-count').textContent, '1 Fotos mit KI-Tags');
    assert.equal(app.element('#worker-progress').hidden, false);
    assert.equal(app.element('#worker-progress').value, 1);
});

test('empty queue stays idle and a completed scan starts tagging', async () => {
    let scan = { processed: 1, more: false, stats: { total: 1, tagged: 0, errors: 0, queued: 1 } };
    let app = startApp({ responses: [scan, complete] });
    assert.equal(app.requests.length, 0);
    app.element('.main').listeners.click({ target: { closest: selector => selector === '#scan-start' } });
    await settle();
    assert.deepEqual(
        app.requests.map(request => request.action),
        ['scan', 'tag']
    );
});

test('stop finishes the current request without starting another', async () => {
    let finish;
    let app = startApp({ queued: 2, responses: [() => new Promise(resolve => (finish = resolve))] });
    app.element('#worker-stop').listeners.click();
    finish({ ...complete, more: true });
    await settle();
    assert.equal(app.requests.length, 1);
    assert.match(app.element('#worker-message').textContent, /Gestoppt/);
});

test('an active worker in another page is retried without a parallel tagging run', async () => {
    let app = startApp({ queued: 1, responses: [{ http: { status: 409, ok: false } }, complete] });
    await settle();
    assert.equal(app.timers.length, 1);
    assert.equal(app.requests.length, 1);
    app.timers.shift()();
    await settle();
    assert.equal(app.requests.length, 2);
});

test('infinite loading appends unique cards and opens appended photos in the viewer', async () => {
    let second = { dataset: { photo: '2' } };
    let next = {
        querySelector: selector => (selector === '.photo-grid' ? {} : { dataset: { next: '' } }),
        querySelectorAll: () => [{ dataset: { photo: '1' } }, second]
    };
    let app = startApp({ responses: [next, { id: 2, name: 'Foto 2', taken: '2026-01-01', tags: [], status: 'done' }] });
    assert.equal(app.observers.length, 1);
    app.observers[0]([{ isIntersecting: true }]);
    app.observers[0]([{ isIntersecting: true }]);
    await settle();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].url, '?album=Urlaub&page=2');
    assert.deepEqual(app.element('.photo-grid').children, [second]);
    assert.equal(app.element('#photo-loader').dataset.next, '');
    app.element('.main').listeners.click({
        target: { closest: selector => (selector === '[data-photo]' ? second : null) }
    });
    await settle();
    assert.equal(app.requests[1].url, '?detail=2');
    assert.equal(app.element('#viewer-title').textContent, 'Foto 2');
});

test('failed loading keeps the next batch for an explicit retry', async () => {
    let empty = { querySelector: () => ({ dataset: { next: '' } }), querySelectorAll: () => [] };
    let app = startApp({ responses: [new Error('offline'), empty] });
    assert.equal(app.observers.length, 1);
    app.observers[0]([{ isIntersecting: true }]);
    await settle();
    assert.equal(app.element('#photo-retry').hidden, false);
    assert.equal(app.element('#photo-loader').dataset.next, '?album=Urlaub&page=2');
    app.element('.main').listeners.click({ target: { closest: selector => selector === '#photo-retry' } });
    await settle();
    assert.equal(app.requests.length, 2);
    assert.equal(app.element('#photo-loader').dataset.next, '');
});

test('stopping while waiting for another worker prevents the retry', async () => {
    let app = startApp({ queued: 1, responses: [{ http: { status: 409, ok: false } }] });
    await settle();
    app.element('#worker-stop').listeners.click();
    app.timers.shift()();
    await settle();
    assert.equal(app.requests.length, 1);
    assert.match(app.element('#worker-message').textContent, /Gestoppt/);
    assert.equal(app.element('#worker-stop').hidden, true);
});

test('a failed or stopped scan never starts tagging', async () => {
    let failed = startApp({ responses: [new Error('offline')] });
    failed.element('.main').listeners.click({ target: { closest: selector => selector === '#scan-start' } });
    await settle();
    assert.deepEqual(
        failed.requests.map(request => request.action),
        ['scan']
    );
    let finish;
    let stopped = startApp({ responses: [() => new Promise(resolve => (finish = resolve))] });
    stopped.element('.main').listeners.click({ target: { closest: selector => selector === '#scan-start' } });
    stopped.element('#worker-stop').listeners.click();
    finish({ processed: 1, more: false, stats: { total: 1, tagged: 0, errors: 0, queued: 1 } });
    await settle();
    assert.deepEqual(
        stopped.requests.map(request => request.action),
        ['scan']
    );
});

test('gallery navigation invalidates an in-flight infinite loading response', async () => {
    let finish;
    let app = startApp({ responses: [() => new Promise(resolve => (finish = resolve))] });
    app.observers[0]([{ isIntersecting: true }]);
    app.gallery.update({ querySelector: () => ({ childNodes: [], textContent: '', max: 1, value: 0 }) });
    finish({ querySelector: () => ({}), querySelectorAll: () => [{ dataset: { photo: 'old-photo' } }] });
    await settle();
    assert.deepEqual(app.element('.photo-grid').children, []);
});

test('disposing the gallery prevents more background requests', async () => {
    let finish;
    let app = startApp({ queued: 2, responses: [() => new Promise(resolve => (finish = resolve))] });
    app.gallery.dispose();
    finish({ ...complete, more: true });
    await settle();
    assert.equal(app.requests.length, 1);
});

test('opening a photo creates a shareable URL and closing uses browser history', async () => {
    let app = startApp({
        responses: [{ id: 1, name: 'Foto', taken: '2026-01-01', tags: [] }],
        url: 'https://photobutler.rebuhleiv.xyz/?album=Urlaub'
    });
    app.element('.main').listeners.click({
        target: { closest: selector => (selector === '[data-photo]' ? app.element('first-card') : null) }
    });
    await settle();
    assert.equal(app.element('#viewer-image').src, '?photo=1&size=original');
    app.element('#viewer-image').onerror();
    assert.equal(app.element('#viewer-image').src, '?photo=1&size=display');
    assert.equal(app.element('#viewer-image').onerror, null);
    assert.equal(new URL(app.location.href).searchParams.get('image'), '1');
    assert.equal(new URL(app.location.href).searchParams.get('album'), 'Urlaub');
    app.element('.viewer-close').listeners.click();
    assert.equal(app.history.backRequested, true);
});

test('a direct photo URL opens even when the photo is not in the loaded batch', async () => {
    let app = startApp({
        url: 'https://photobutler.rebuhleiv.xyz/?image=999',
        responses: [{ id: 999, name: 'Direktfoto', taken: '2026-01-01', tags: [] }]
    });
    app.gallery.syncPhoto();
    await settle();
    assert.equal(app.requests[0].url, '?detail=999');
    assert.equal(app.element('#viewer-title').textContent, 'Direktfoto');
    assert.equal(app.element('.viewer-next').disabled, true);
    assert.equal(app.element('.viewer-previous').disabled, true);
    app.element('.viewer-close').listeners.click();
    assert.equal(new URL(app.location.href).searchParams.has('image'), false);
    assert.equal(app.element('#viewer').open, false);
});

test('returning to the gallery invalidates pending photo details', async () => {
    let finish;
    let app = startApp({
        url: 'https://photobutler.rebuhleiv.xyz/?image=1',
        responses: [() => new Promise(resolve => (finish = resolve))]
    });
    app.gallery.syncPhoto();
    app.location.href = 'https://photobutler.rebuhleiv.xyz/';
    app.gallery.syncPhoto();
    finish({ id: 1, name: 'Veraltetes Foto', taken: '2026-01-01', tags: [] });
    await settle();
    assert.equal(app.element('#viewer').open, false);
    assert.notEqual(app.element('#viewer-title').textContent, 'Veraltetes Foto');
});

test('previews show loading until loaded and stop the spinner after an error', () => {
    let app = startApp();
    let $image = app.element('preview-image');
    let $card = app.element('first-card');
    assert.equal($card.dataset.previewState, 'loading');
    $image.complete = true;
    $image.naturalWidth = 64;
    app.element('document').listeners.load({ target: $image });
    assert.equal($card.dataset.previewState, 'ready');
    assert.equal($card['aria-busy'], 'false');
    $image.naturalWidth = 0;
    app.element('document').listeners.error({ target: $image });
    assert.equal($card.dataset.previewState, 'error');
    assert.equal($card['aria-busy'], 'false');
});

test('already loaded previews are immediately visible without another image request', () => {
    let app = startApp({ previewLoaded: true });
    assert.equal(app.element('first-card').dataset.previewState, 'ready');
    assert.equal(app.requests.length, 0);
});

test('column selection changes the grid and persists without navigation', () => {
    let app = startApp();
    for (let columns of ['5', '6', '7']) {
        app.element('.main').listeners.change({ target: { id: 'gallery-columns', value: columns } });
        assert.equal(app.element('html').style['--gallery-columns'], columns);
        assert.equal(app.element('storage')['photobutler.galleryColumns'], columns);
    }
    app.element('.main').listeners.change({ target: { id: 'gallery-columns', value: '99' } });
    assert.equal(app.element('storage')['photobutler.galleryColumns'], '7');
    assert.equal(app.requests.length, 0);
});
