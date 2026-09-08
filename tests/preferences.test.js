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
