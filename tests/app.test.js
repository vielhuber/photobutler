let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function startApp({
    queued = 0,
    responses = [],
    url = 'https://photobutler.rebuhleiv.xyz/',
    previewLoaded = false,
    confirm = true,
    selectedPhoto = ''
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
    let frames = [];
    let preloadUpdates = [];
    let resizeObservers = [];
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
    $card.parentElement = element('first-tile');
    element('rating-button').dataset = { priorityPhoto: '1', priority: '1' };
    element('reject-button').dataset = { priorityPhoto: '1', priority: '-1' };
    let $preview = element('preview-image');
    $preview.parentElement = $card;
    $preview.src = '?photo=1&size=display';
    $preview.complete = previewLoaded;
    $preview.naturalWidth = previewLoaded ? 64 : 0;
    element('#viewer').dataset.selectedPhoto = selectedPhoto;
    element('#viewer-image').parentElement = element('.viewer-stage');
    element('#tag-pending').dataset.queued = String(queued);
    element('#photo-loader').dataset.next = '?album=Urlaub&page=2';
    let context = {
        document: {
            querySelector: selector =>
                selector === '[data-photo]:hover' ? (element('hover').card ?? null) : element(selector),
            querySelectorAll: selector =>
                selector === '[data-preview-state] > img'
                    ? [$preview, element('#viewer-image')]
                    : selector.startsWith('[data-priority-photo=')
                      ? [element('rating-button'), element('reject-button')]
                      : [$card],
            addEventListener: (event, callback) => element('document').addEventListener(event, callback),
            documentElement: element('html')
        },
        window: { addEventListener() {}, confirm: () => confirm },
        navigatePage: async url => {
            element('navigation').url = url;
        },
        innerWidth: 1440,
        getComputedStyle: () => ({
            getPropertyValue: name => element('html').style[name] ?? (name === '--gallery-columns' ? '5' : '250')
        }),
        localStorage: {
            getItem() {
                return null;
            },
            setItem(key, value) {
                element('storage')[key] = value;
            }
        },
        initializeJobs: () => ({ update() {}, dispose() {} }),
        DetailPreloader: class {
            update(...args) {
                preloadUpdates.push(args);
            }
            dispose() {
                this.disposed = true;
            }
        },
        ResizeObserver: class {
            constructor(callback) {
                resizeObservers.push(callback);
            }
            observe() {}
            disconnect() {}
        },
        requestAnimationFrame: callback => frames.push(callback),
        cancelAnimationFrame() {},
        FormData,
        URL,
        location,
        history,
        AbortController,
        setTimeout: callback => timers.push(callback),
        clearTimeout: id => {
            if (id) timers[id - 1] = null;
        },
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
            requests.push({ url, action: options?.body?.get('action'), options });
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
        readFileSync('assets/app.js', 'utf8')
            .replace(/^import .*;\n/gm, '')
            .replace('export ', '') + '\ninitializeGallery(navigatePage);',
        context
    );
    return {
        element,
        requests,
        observers,
        timers,
        gallery,
        location,
        history,
        frames,
        preloadUpdates,
        resizeObservers
    };
}

async function settle() {
    await new Promise(resolve => setImmediate(resolve));
}

test('infinite loading appends unique cards and opens appended photos in the viewer', async () => {
    let second = { dataset: { photo: '2' } };
    second.parentElement = second;
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
    assert.equal(app.requests[0].url, 'https://photobutler.rebuhleiv.xyz/?album=Urlaub&page=2&offset=1');
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

test('gallery navigation invalidates an in-flight infinite loading response', async () => {
    let finish;
    let app = startApp({ responses: [() => new Promise(resolve => (finish = resolve))] });
    app.observers[0]([{ isIntersecting: true }]);
    app.gallery.update({
        querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' }, textContent: '', max: 1, value: 0 })
    });
    finish({ querySelector: () => ({}), querySelectorAll: () => [{ dataset: { photo: 'old-photo' } }] });
    await settle();
    assert.deepEqual(app.element('.photo-grid').children, []);
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
        selectedPhoto: '999',
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
    for (let columns of ['3', '4', '5', '6', '7', '8', '9']) {
        app.element('.main').listeners.change({ target: { id: 'gallery-columns', value: columns } });
        assert.equal(app.element('html').style['--gallery-columns'], columns);
        assert.equal(app.element('storage')['photobutler.galleryColumns'], columns);
    }
    app.element('.main').listeners.change({ target: { id: 'gallery-columns', value: '99' } });
    assert.equal(app.element('storage')['photobutler.galleryColumns'], '9');
    assert.equal(app.requests.length, 0);
});

test('preload lifecycle tracks foreground loads, viewport changes and gallery replacement', async () => {
    let app = startApp({ responses: [{ id: 1, name: 'Foto', taken: '2026-01-01', tags: [] }] });
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[1], null);
    app.element('.main').listeners.click({
        target: { closest: selector => (selector === '[data-photo]' ? app.element('first-card') : null) }
    });
    assert.equal(app.preloadUpdates.at(-1)[1], 0);
    assert.equal(app.preloadUpdates.at(-1)[2], true);
    assert.equal(app.element('#viewer-image').fetchPriority, 'high');
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[2], true);
    app.element('#viewer-image').complete = true;
    app.element('#viewer-image').listeners.load();
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[2], false);
    app.resizeObservers[0]();
    assert.equal(app.frames.length, 1);
    app.frames.shift()();
    app.gallery.update({
        querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' }, textContent: '', max: 1, value: 0 })
    });
    assert.equal(app.preloadUpdates.at(-1)[0].length, 0);
    assert.equal(app.preloadUpdates.at(-1)[2], true);
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[1], null);
    await settle();
});

test('delegated hover prioritizes the current card without opening the popup or reloading', () => {
    let app = startApp();
    app.frames.shift()();
    let $card = app.element('first-card');
    app.element('hover').card = $card;
    let event = { target: { closest: () => $card }, relatedTarget: null };
    app.element('.main').listeners.mouseover(event);
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], $card);
    assert.equal(app.requests.length, 0);
    assert.ok(!app.element('#viewer').open);
    app.element('.main').listeners.mouseover({ ...event, relatedTarget: { closest: () => $card } });
    assert.equal(app.frames.length, 0);
    app.element('hover').card = null;
    app.element('.main').listeners.mouseout(event);
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], null);
    app.gallery.update({
        querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' }, textContent: '', max: 1, value: 0 })
    });
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], null);
    let $newCard = app.element('new-card');
    app.element('hover').card = $newCard;
    app.element('.main').listeners.mouseover({ ...event, target: { closest: () => $newCard } });
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], $newCard);
});

test('viewport and gallery replacement do not synthesize hover preloads', () => {
    let app = startApp();
    app.element('hover').card = app.element('first-card');
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], null);
    app.element('.main').listeners.mouseover({ target: { closest: () => app.element('first-card') } });
    app.frames.shift()();
    app.gallery.update({
        querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' }, textContent: '', max: 1, value: 0 })
    });
    app.frames.shift()();
    assert.equal(app.preloadUpdates.at(-1)[3], null);
});

test('pending photos never start a job when mounting or refreshing the gallery', async () => {
    let app = startApp({ queued: 10 });
    app.gallery.update({
        querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' }, textContent: '', max: 1, value: 0 })
    });
    await settle();
    assert.equal(app.requests.length, 0);
});

test('column selection and the direct URL survive gallery navigation', () => {
    let url = 'https://photobutler.rebuhleiv.xyz/?sort=oldest&image=1';
    let app = startApp({ url, queued: 2 });
    for (let columns of ['3', '4', '5', '6', '7', '8', '9']) {
        app.element('.main').listeners.change({ target: { id: 'gallery-columns', value: columns } });
        app.gallery.update({
            querySelector: () => ({
                childNodes: [],
                dataset: { selectedPhoto: '0' },
                textContent: '',
                max: 1,
                value: 0
            })
        });
        assert.equal(app.element('#gallery-columns').value, columns);
        assert.equal(app.location.href, url);
        assert.equal(app.requests.length, 0);
    }
});

test('slideshow waits for the image, follows filtered pagination and stops at the end', async () => {
    let second = { dataset: { photo: '2' } };
    second.parentElement = second;
    let next = {
        querySelector: selector => (selector === '.photo-grid' ? {} : { dataset: { next: '' } }),
        querySelectorAll: () => [second]
    };
    let app = startApp({
        responses: [
            { id: 1, name: 'First', taken: '2024-01-01', tags: [] },
            next,
            { id: 2, name: 'Second', taken: '2024-01-01', tags: [] }
        ]
    });
    await app.element('.main').listeners.click({ target: { closest: selector => selector === '#gallery-slideshow' } });
    assert.equal(app.element('#slideshow-stop').hidden, false);
    assert.equal(app.timers.length, 0);
    let $image = app.element('#viewer-image');
    $image.complete = true;
    $image.naturalWidth = 80;
    $image.listeners.load();
    await app.timers.at(-1)();
    assert.deepEqual(
        app.requests.map(request => request.url),
        ['?detail=1', 'https://photobutler.rebuhleiv.xyz/?album=Urlaub&page=2&offset=1', '?detail=2']
    );
    assert.equal(app.element('#viewer-title').textContent, 'Second');
    await app.timers.at(-1)();
    assert.equal(app.element('#slideshow-stop').hidden, true);
    assert.equal(app.element('#photo-load-message').textContent, 'Slideshow beendet.');
    assert.equal(app.element('#viewer').open, false);
});

test('stopping slideshow invalidates an in-flight page load and does not open its photo', async () => {
    let finish;
    let app = startApp({
        responses: [
            { id: 1, name: 'First', taken: '2024-01-01', tags: [] },
            () =>
                new Promise(resolve => {
                    finish = resolve;
                })
        ]
    });
    await app.element('.main').listeners.click({ target: { closest: selector => selector === '#gallery-slideshow' } });
    let $image = app.element('#viewer-image');
    $image.complete = true;
    $image.naturalWidth = 80;
    $image.listeners.load();
    let advancing = app.timers.at(-1)();
    app.element('#slideshow-stop').listeners.click();
    finish({
        querySelector: selector => (selector === '.photo-grid' ? {} : { dataset: { next: '' } }),
        querySelectorAll: () => [{ dataset: { photo: '2' } }]
    });
    await advancing;
    assert.equal(app.requests.length, 2);
    assert.equal(app.element('#viewer-title').textContent, 'First');
    assert.equal(app.element('#slideshow-stop').hidden, true);
});

test('slideshow stops visibly on pagination errors and closing cancels timers', async () => {
    let app = startApp({ responses: [{ id: 1, name: 'First', taken: '2024-01-01', tags: [] }, new Error('offline')] });
    await app.element('.main').listeners.click({ target: { closest: selector => selector === '#gallery-slideshow' } });
    let $image = app.element('#viewer-image');
    $image.complete = true;
    $image.naturalWidth = 80;
    $image.listeners.load();
    await app.timers.at(-1)();
    assert.equal(app.element('#slideshow-stop').hidden, true);
    assert.match(app.element('#photo-load-message').textContent, /Weitere Fotos konnten nicht geladen/);
    app.element('.viewer-close').listeners.click();
    assert.ok(app.timers.every(timer => timer === null));
});

test('a direct link excluded by the server filters never loads an original or opens the viewer', () => {
    let app = startApp({ url: 'https://photobutler.rebuhleiv.xyz/?image=92942' });
    app.gallery.syncPhoto();
    assert.equal(app.requests.length, 0);
    assert.equal(app.element('#viewer').open, false);
    assert.equal(app.element('#viewer-image').getAttribute('src'), null);
    assert.equal(new URL(app.location.href).searchParams.has('image'), false);
});

test('overview priority buttons save once via Ajax, update opacity state and never open the popup', async () => {
    let finish;
    let app = startApp({
        responses: [
            () =>
                new Promise(resolve => {
                    finish = resolve;
                })
        ]
    });
    let $card = app.element('first-card');
    $card.dataset.priority = '0';
    let $button = app.element('rating-button');
    $button.dataset = { priorityPhoto: '1', priority: '1' };
    let event = {
        preventDefault() {},
        target: { closest: selector => (selector === '[data-priority-photo]' ? $button : null) }
    };
    let saving = app.element('.main').listeners.click(event);
    await app.element('.main').listeners.click(event);
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].options.body.get('action'), 'priority');
    assert.equal(app.requests[0].options.body.get('priority'), '1');
    assert.equal($card.dataset.priority, '1');
    assert.equal(app.element('[data-favorite="1"]').textContent, '♥');
    finish({ id: 1, priority: 1, favorite: true, tags: [] });
    await saving;
    assert.equal($card.dataset.priority, '1');
    assert.notEqual(app.element('#viewer').open, true);
    assert.equal(app.element('[data-favorite="1"]').textContent, '♥');
});

test('a failed overview rating leaves its priority intact and shows a retryable error', async () => {
    let app = startApp({ responses: [new Error('offline')] });
    let $card = app.element('first-card');
    $card.dataset.priority = '0';
    let $button = app.element('rating-button');
    $button.dataset = { priorityPhoto: '1', priority: '-1' };
    await app.element('.main').listeners.click({
        preventDefault() {},
        target: { closest: selector => (selector === '[data-priority-photo]' ? $button : null) }
    });
    assert.equal($card.dataset.priority, '0');
    assert.equal($button.disabled, false);
    assert.equal(app.element('#photo-load-message').textContent, 'offline');
});

test('rating acknowledgements preserve the gallery DOM and URL across affected filters', async () => {
    for (let [priority, mode, relevance] of [
        [-1, '0', 'relevant'],
        [0, '0', 'relevant'],
        [1, 'none', 'all'],
        [0, '1', 'all'],
        [1, '0', 'excluded'],
        [1, '0', 'unrated'],
        [-1, '0', 'unrated']
    ]) {
        let app = startApp({
            url: 'https://photobutler.rebuhleiv.xyz/?page=2&image=1',
            responses: [{ id: 1, priority, favorite: priority === 1, tags: [] }]
        });
        app.element('#gallery-favorites').value = mode;
        app.element('#gallery-relevance').value = relevance;
        app.element('first-card').dataset.priority = priority === 0 ? '1' : '0';
        let $button = app.element('rating-button');
        $button.dataset = { priorityPhoto: '1', priority: priority === -1 ? '-1' : '1' };
        await app.element('.main').listeners.click({
            preventDefault() {},
            target: { closest: selector => (selector === '[data-priority-photo]' ? $button : null) }
        });
        assert.equal(app.element('navigation').url, undefined);
        assert.equal(app.element('first-tile').hidden, true);
    }
});

test('an excluded tile disappears synchronously and is restored when its pending save fails', async () => {
    let reject;
    let app = startApp({
        responses: [
            () =>
                new Promise((resolve, fail) => {
                    reject = fail;
                })
        ]
    });
    app.element('#gallery-relevance').value = 'relevant';
    app.element('first-card').dataset.priority = '1';
    let $button = app.element('reject-button');
    let saving = app.element('.main').listeners.click({
        preventDefault() {},
        target: { closest: selector => (selector === '[data-priority-photo]' ? $button : null) }
    });
    assert.equal(app.element('first-tile').hidden, true);
    assert.equal(app.element('first-card').dataset.priority, '-1');
    assert.equal(app.element('navigation').url, undefined);
    reject(new Error('offline'));
    await saving;
    assert.equal(app.element('first-tile').hidden, false);
    assert.equal(app.element('first-card').dataset.priority, '1');
    assert.equal($button.disabled, false);
    assert.equal(app.element('#photo-load-message').textContent, 'offline');
});

test('a late rating acknowledgement updates the current tile without navigating again', async () => {
    let finish;
    let app = startApp({
        responses: [
            () =>
                new Promise(resolve => {
                    finish = resolve;
                })
        ]
    });
    app.element('first-card').dataset.priority = '0';
    let $button = app.element('rating-button');
    let saving = app.element('.main').listeners.click({
        preventDefault() {},
        target: { closest: selector => (selector === '[data-priority-photo]' ? $button : null) }
    });
    app.gallery.update({ querySelector: () => ({ childNodes: [], dataset: { selectedPhoto: '0' } }) });
    app.location.href = 'https://photobutler.rebuhleiv.xyz/?relevance=excluded';
    finish({ id: 1, priority: 1, favorite: true, tags: [] });
    await saving;
    assert.equal(app.element('navigation').url, undefined);
    assert.equal(app.element('first-card').dataset.priority, '1');
});

test('loading waits for pending ratings and uses the remaining matching count after success or rollback', async () => {
    for (let success of [true, false]) {
        let finish;
        let fail;
        let next = { querySelector: () => ({ dataset: { next: '' } }), querySelectorAll: () => [] };
        let app = startApp({
            responses: [
                () =>
                    new Promise((resolve, reject) => {
                        finish = resolve;
                        fail = reject;
                    }),
                next
            ]
        });
        app.element('#gallery-relevance').value = 'unrated';
        app.element('#photo-loader').dataset.offset = '60';
        app.element('first-card').dataset.priority = '0';
        let saving = app.element('.main').listeners.click({
            preventDefault() {},
            target: {
                closest: selector => (selector === '[data-priority-photo]' ? app.element('reject-button') : null)
            }
        });
        app.observers[0]([{ isIntersecting: true }]);
        assert.equal(app.requests.length, 1);
        if (success) finish({ id: 1, priority: -1, tags: [] });
        if (!success) fail(new Error('offline'));
        await saving;
        await settle();
        assert.equal(app.requests.length, 2);
        assert.equal(new URL(app.requests[1].url).searchParams.get('offset'), success ? '60' : '61');
        if (!success) assert.equal(app.element('#photo-load-message').textContent, 'offline');
        assert.equal(app.element('navigation').url, undefined);
    }
});

test('a page fetched across a rating is discarded and retried without replacing existing cards', async () => {
    let finish;
    let stale = { dataset: { photo: 'stale' } };
    stale.parentElement = stale;
    let next = { querySelector: () => ({ dataset: { next: '' } }), querySelectorAll: () => [] };
    let app = startApp({
        responses: [
            () =>
                new Promise(resolve => {
                    finish = resolve;
                }),
            { id: 1, priority: -1, tags: [] },
            next
        ]
    });
    app.element('#gallery-relevance').value = 'relevant';
    app.element('first-card').dataset.priority = '0';
    app.observers[0]([{ isIntersecting: true }]);
    await app.element('.main').listeners.click({
        preventDefault() {},
        target: { closest: selector => (selector === '[data-priority-photo]' ? app.element('reject-button') : null) }
    });
    finish({ querySelector: () => ({ dataset: { next: '' } }), querySelectorAll: () => [stale] });
    await settle();
    assert.equal(app.requests.length, 3);
    assert.equal(new URL(app.requests[2].url).searchParams.get('offset'), '0');
    assert.deepEqual(app.element('.photo-grid').children, []);
    assert.equal(app.element('navigation').url, undefined);
});
