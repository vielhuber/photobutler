let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

test('stored layout is applied synchronously before the gallery exists', () => {
    let values = new Map([
        ['photobutler.sidebarWidth', '420'],
        ['photobutler.galleryColumns', '7']
    ]);
    let styles = new Map();
    runInNewContext(readFileSync('assets/preferences.js', 'utf8'), {
        document: { documentElement: { style: { setProperty: (name, value) => styles.set(name, value) } } },
        localStorage: { getItem: key => values.get(key) },
        innerWidth: 1440
    });
    assert.equal(styles.get('--sidebar-width'), '420px');
    assert.equal(styles.get('--gallery-columns'), '7');
});

test('unavailable local storage does not prevent the page from rendering', () => {
    assert.doesNotThrow(() =>
        runInNewContext(readFileSync('assets/preferences.js', 'utf8'), {
            localStorage: {
                getItem() {
                    throw new Error('denied');
                }
            },
            innerWidth: 1440
        })
    );
});

test('each supported column preference is restored and invalid values keep the default', () => {
    for (let columns of ['3', '4', '5', '6', '7', '8', '9', null, '', '2', '10', '3.5', '03', '99']) {
        let styles = new Map();
        runInNewContext(readFileSync('assets/preferences.js', 'utf8'), {
            document: { documentElement: { style: { setProperty: (name, value) => styles.set(name, value) } } },
            localStorage: { getItem: key => (key === 'photobutler.galleryColumns' ? columns : null) },
            innerWidth: 1920
        });
        assert.equal(
            styles.get('--gallery-columns'),
            ['3', '4', '5', '6', '7', '8', '9'].includes(columns) ? columns : undefined
        );
    }
});

test('stored dropdown selection is restored as soon as its option is parsed', () => {
    for (let columns of ['3', '4', '5', '6', '7', '8', '9']) {
        let $option = null;
        let callback;
        let disconnected = false;
        let observed = false;
        let $root = { style: { setProperty() {} } };
        runInNewContext(readFileSync('assets/preferences.js', 'utf8'), {
            document: {
                documentElement: $root,
                querySelector: selector => (selector === `#gallery-columns option[value="${columns}"]` ? $option : null)
            },
            localStorage: { getItem: key => (key === 'photobutler.galleryColumns' ? columns : null) },
            innerWidth: 1920,
            MutationObserver: class {
                constructor(listener) {
                    callback = listener;
                }
                observe(target, options) {
                    observed = target === $root && options.childList && options.subtree;
                }
                disconnect() {
                    disconnected = true;
                }
            }
        });
        assert.equal(observed, true);
        callback();
        assert.equal(disconnected, false);
        $option = { selected: false };
        callback();
        assert.equal($option.selected, true);
        assert.equal(disconnected, true);
    }
});
