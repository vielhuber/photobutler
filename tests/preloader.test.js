let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function startPreloader() {
    let images = [];
    let Preloader = runInNewContext(
        readFileSync('assets/preloader.js', 'utf8').replace('export ', '') + '\nDetailPreloader;',
        {
            innerWidth: 1000,
            innerHeight: 800,
            Image: class {
                constructor() {
                    images.push(this);
                }
                removeAttribute() {}
            }
        }
    );
    let preloader = new Preloader();
    return { preloader, images };
}

function card(id, rectangle = { top: 10, bottom: 110, left: 10, right: 110, width: 100, height: 100 }) {
    return { dataset: { photo: String(id) }, getBoundingClientRect: () => rectangle };
}

function finish(images) {
    for (let index = 0; index < images.length; index++) images[index].onload?.();
}

function ids(images) {
    return images.map(image => image.src);
}

test('visible cards alone never start original preloading', () => {
    let { preloader, images } = startPreloader();
    preloader.update([
        card(1),
        card(2, { top: 790, bottom: 890, left: 0, right: 100, width: 100, height: 100 }),
        card(3, { top: 800, bottom: 900, left: 0, right: 100, width: 100, height: 100 }),
        card(4, { top: -100, bottom: 0, left: 0, right: 100, width: 100, height: 100 }),
        card(5, { top: 0, bottom: 100, left: 1000, right: 1100, width: 100, height: 100 }),
        card(6, { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 })
    ]);
    finish(images);
    assert.deepEqual(ids(images), []);
});

test('two neighbours per direction follow the actual filtered sorted slide order, nearest first', () => {
    let { preloader, images } = startPreloader();
    preloader.update(
        [90, 30, 70, 20, 60, 10, 80, 40, 50].map(id => card(id)),
        4
    );
    finish(images);
    assert.deepEqual(
        ids(images),
        [10, 20, 80, 70].map(id => `?photo=${id}&size=original`)
    );
});

test('boundaries and an unknown direct-link photo never wrap or preload unrelated photos', () => {
    for (let [index, expected] of [
        [0, [2, 3]],
        [2, [2, 1]],
        [-1, []],
        [3, []]
    ]) {
        let { preloader, images } = startPreloader();
        preloader.update(
            [1, 2, 3].map(id => card(id)),
            index
        );
        finish(images);
        assert.deepEqual(
            ids(images),
            expected.map(id => `?photo=${id}&size=original`)
        );
    }
});

test('queue caps concurrency at two, removes stale waiting entries and deduplicates across views', () => {
    let { preloader, images } = startPreloader();
    preloader.update(
        [1, 9, 2, 3, 4].map(id => card(id)),
        1
    );
    assert.equal(images.length, 2);
    assert.ok(images.every(image => image.fetchPriority === 'low' && image.crossOrigin === undefined));
    preloader.update(
        [2, 5, 6].map(id => card(id)),
        0
    );
    images[0].onload();
    assert.equal(images.length, 3);
    assert.equal(images[0].onload, null);
    assert.equal(images[0].onerror, null);
    images[1].onload();
    finish(images);
    preloader.update(
        [2, 5, 6].map(id => card(id)),
        0
    );
    assert.deepEqual(
        ids(images),
        [2, 1, 5, 6].map(id => `?photo=${id}&size=original`)
    );
    assert.equal(preloader.active.size, 0);
});

test('foreground loading pauses neighbours, rapid slides replace pending work, close stops preloading', () => {
    let { preloader, images } = startPreloader();
    let cards = [1, 2, 3, 4, 5, 6, 7, 8].map(id => card(id));
    preloader.update(cards, 0, true);
    assert.equal(images.length, 0);
    preloader.update(cards, 7, true);
    preloader.update(cards, 7);
    assert.deepEqual(
        ids(images),
        [7, 6].map(id => `?photo=${id}&size=original`)
    );
    preloader.update([card(9)], null, true);
    finish(images);
    assert.equal(images.length, 2);
    preloader.update([card(9)]);
    assert.equal(images.length, 2);
    preloader.dispose();
    finish(images);
    preloader.update(cards);
    assert.equal(images.length, 2);
    assert.equal(preloader.active.size, 0);
});

test('unsupported originals never preload another size or retry errors endlessly', () => {
    let { preloader, images } = startPreloader();
    let cards = [card(1)];
    preloader.update(cards, null, false, cards[0]);
    images[0].onerror({ type: 'error' });
    preloader.update(cards, null, false, cards[0]);
    assert.deepEqual(ids(images), ['?photo=1&size=original']);
});

test('obsolete active originals finish but do not start fallback downloads', () => {
    let { preloader, images } = startPreloader();
    let cards = [card(1)];
    preloader.update(cards, null, false, cards[0]);
    preloader.update([]);
    images[0].onerror({ type: 'error' });
    assert.equal(images.length, 1);
});

test('hover starts only the selected original without consulting thumbnail completion', () => {
    let { preloader, images } = startPreloader();
    let cards = [1, 2, 3, 4].map(id => card(id));
    preloader.update(cards, null, false, cards[3]);
    assert.deepEqual(ids(images), ['?photo=4&size=original']);
    assert.equal(images[0].fetchPriority, 'high');
});

test('hover promotes queued or active loads without adding parallel or duplicate downloads', () => {
    let { preloader, images } = startPreloader();
    let cards = [1, 2, 3, 4].map(id => card(id));
    preloader.update(cards, 0);
    preloader.update(cards, null, false, cards[3]);
    assert.equal(images.length, 2);
    images[0].onload();
    assert.equal(images[2].src, '?photo=4&size=original');
    assert.equal(images[2].fetchPriority, 'high');
    preloader.update(cards, null, false, cards[2]);
    assert.equal(images[1].fetchPriority, 'high');
    assert.equal(images[2].fetchPriority, 'low');
    assert.equal(images.length, 3);
    preloader.update(cards);
    assert.equal(images[1].fetchPriority, 'low');
    finish(images);
    preloader.update(cards, null, false, cards[3]);
    assert.equal(images.length, 3);
});

test('foreground and replaced galleries discard stale hover priority', () => {
    let { preloader, images } = startPreloader();
    let cards = [1, 2, 3, 4].map(id => card(id));
    preloader.update(cards, null, false, cards[3]);
    preloader.update(cards, 0, true, cards[3]);
    assert.equal(images[0].fetchPriority, 'low');
    finish(images);
    assert.equal(images.length, 1);
    preloader.update([card(5)], null, false, cards[3]);
    assert.equal(images.length, 1);
});
