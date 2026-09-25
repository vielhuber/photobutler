let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function mount({ confirm = false, deferRefresh = false, deferReset = false, resetError = null } = {}) {
    let requests = [];
    let finishReset;
    let polls = [];
    let timers = new Set();
    let listener;
    let finishRefresh;
    let refresh;
    let cards = new Map();
    let visible = true;
    let states = Object.fromEntries(
        ['tag', 'faces', 'scan', 'previews'].map(job => [
            job,
            {
                job,
                status: 'paused',
                completed: 1,
                total: 4,
                errors: 0,
                estimated: job === 'scan',
                percent: 25
            }
        ])
    );
    for (let [job, state] of Object.entries(states)) {
        let elements = new Map();
        cards.set(job, {
            dataset: { job, state: JSON.stringify(state) },
            querySelector(selector) {
                assert.ok(
                    [
                        '[data-job-status]',
                        'progress',
                        '[data-job-eta]',
                        '[data-job-count]',
                        '[data-job-action="reset"]',
                        '[data-job-message]'
                    ].includes(selector),
                    selector
                );
                if (!elements.has(selector)) elements.set(selector, {});
                return elements.get(selector);
            }
        });
    }
    let $message = {};
    let controller = runInNewContext(
        readFileSync('assets/jobs.js', 'utf8').replace('export ', '') + '\ninitializeJobs($main);',
        {
            $main: {
                querySelectorAll: () => (visible ? [...cards.values()] : []),
                querySelector: () => (visible ? cards.get('tag') : null),
                addEventListener: (name, callback) => {
                    listener = callback;
                }
            },
            document: { querySelector: selector => (selector === '#jobs-message' ? $message : { content: 'csrf' }) },
            window: { confirm: () => confirm },
            FormData,
            Map,
            JSON,
            Promise,
            setTimeout: (callback, delay) => {
                assert.equal(delay, 3000);
                refresh = callback;
                timers.add(callback);
                return callback;
            },
            clearTimeout: timer => timers.delete(timer),
            fetch: async (url, options) => {
                if (!options) {
                    polls.push(url);
                    assert.equal(url, '?jobs=1');
                    let snapshot = JSON.parse(JSON.stringify(states));
                    if (deferRefresh)
                        await new Promise(resolve => {
                            finishRefresh = resolve;
                        });
                    return { ok: true, json: async () => snapshot };
                }
                let action = options.body.get('action');
                let job = options.body.get('job');
                requests.push({ action, job });
                assert.equal(options.method, 'POST');
                assert.equal(options.body.get('csrf'), 'csrf');
                assert.equal(action, 'job-reset');
                if (deferReset)
                    await new Promise(resolve => {
                        finishReset = resolve;
                    });
                let result = resetError
                    ? { error: resetError }
                    : (states[job] = { ...states[job], status: 'idle', token: '', completed: 0, percent: 0 });
                return { ok: !resetError, headers: { get: () => 'application/json' }, json: async () => result };
            }
        }
    );
    return {
        requests,
        polls,
        timers,
        finishReset() {
            finishReset();
        },
        cards,
        states,
        controller,
        refresh() {
            timers.delete(refresh);
            return refresh();
        },
        finishRefresh() {
            finishRefresh();
        },
        navigate(show) {
            visible = show;
            controller.update();
        },
        reset() {
            return listener({ target: { closest: selector => selector === '#analysis-reset' } });
        },
        click(job, action) {
            let $button = { dataset: { jobAction: action }, closest: () => cards.get(job) };
            return listener({ target: { closest: selector => (selector === '[data-job-action]' ? $button : null) } });
        }
    };
}

async function settle() {
    await new Promise(resolve => setImmediate(resolve));
}

test('only AI and face errors display the hourly retry notice', async () => {
    let app = mount();
    await settle();
    for (let status of ['running', 'paused', 'error']) {
        for (let job of Object.keys(app.states)) {
            app.states[job] = { ...app.states[job], status, errors: 1 };
            app.cards.get(job).dataset.state = JSON.stringify(app.states[job]);
        }
        app.controller.update();
        for (let job of ['scan', 'previews', 'tag', 'faces']) {
            let message = app.cards.get(job).querySelector('[data-job-message]').textContent;
            assert.equal(
                message,
                'Fehler prüfen und manuell erneut starten.' +
                    (['tag', 'faces'].includes(job) ? ' Wiederholung frühestens nach einer Stunde.' : '')
            );
            assert.equal(app.cards.get(job).querySelector('[data-job-action="reset"]').disabled, false);
        }
    }
    assert.deepEqual(app.requests, []);
    for (let job of Object.keys(app.states)) {
        app.states[job].errors = 0;
        app.cards.get(job).dataset.state = JSON.stringify(app.states[job]);
    }
    app.controller.update();
    for (let job of Object.keys(app.states))
        assert.equal(app.cards.get(job).querySelector('[data-job-message]').textContent, '');
    app.controller.dispose();
});

test('source warnings survive polling and clear after recovery without starting jobs', async () => {
    let app = mount();
    await settle();
    app.states.scan.warning = 'Fotoquelle nicht verfügbar. Gespeicherter Bestand bleibt erhalten.';
    await app.refresh();
    assert.equal(app.cards.get('scan').querySelector('[data-job-message]').textContent, app.states.scan.warning);
    assert.equal(app.cards.get('previews').querySelector('[data-job-message]').textContent, '');
    app.states.scan.warning = '';
    await app.refresh();
    assert.equal(app.cards.get('scan').querySelector('[data-job-message]').textContent, '');
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('mount, persisted pauses and navigation never start jobs', async () => {
    let app = mount();
    await settle();
    app.navigate(false);
    app.navigate(true);
    assert.equal(app.requests.length, 0);
    assert.match(app.cards.get('scan').querySelector('[data-job-count]').textContent, /geschätzt/);
    assert.match(app.cards.get('tag').querySelector('[data-job-status]').textContent, /25 % · Pausiert/);
    app.controller.dispose();
});

test('polling shows independent CLI progress, pause and completion without processing', async () => {
    let app = mount();
    await settle();
    for (let [status, label, percent] of [
        ['running', 'Läuft', 50],
        ['paused', 'Pausiert', 50],
        ['done', 'Abgeschlossen', 100]
    ]) {
        for (let job of Object.keys(app.states)) {
            app.states[job] = { ...app.states[job], status, percent, completed: percent / 25, eta: 'ca. 1 Min.' };
            await app.refresh();
            let $card = app.cards.get(job);
            assert.equal($card.querySelector('[data-job-status]').textContent, `${percent} % · ${label}`);
            assert.equal($card.querySelector('progress').value, percent);
            assert.equal($card.querySelector('[data-job-eta]').textContent, 'ca. 1 Min.');
            assert.match($card.querySelector('[data-job-count]').textContent, new RegExp(`^${percent / 25} / 4`));
        }
    }
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('completing a CLI import never starts tagging, face analysis or preview generation', async () => {
    let app = mount();
    await settle();
    app.states.scan = { ...app.states.scan, status: 'done', percent: 100, completed: 4 };
    await app.refresh();
    assert.equal(app.cards.get('scan').querySelector('[data-job-status]').textContent, '100 % · Abgeschlossen');
    for (let job of ['tag', 'faces', 'previews'])
        assert.equal(app.cards.get(job).querySelector('[data-job-status]').textContent, '25 % · Pausiert');
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('removed browser start and pause actions cannot submit processing requests', async () => {
    let app = mount();
    await settle();
    for (let job of Object.keys(app.states)) {
        await app.click(job, 'start');
        await app.click(job, 'pause');
    }
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
    assert.equal(app.timers.size, 0);
    let count = app.polls.length;
    await app.refresh();
    assert.equal(app.polls.length, count);
});

test('the removed combined reset is neither offered nor handled', async () => {
    assert.doesNotMatch(readFileSync('templates/jobs.php', 'utf8'), /analysis-reset|KI-Tags und Gesichter/);
    let app = mount({ confirm: true });
    await settle();
    await app.reset();
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('disposing during a pending status request never pauses a CLI run or resumes polling', async () => {
    let app = mount({ deferRefresh: true });
    app.controller.dispose();
    app.finishRefresh();
    await settle();
    assert.equal(app.timers.size, 0);
    assert.equal(app.polls.length, 1);
    assert.deepEqual(app.requests, []);
});

test('all four cards display persisted estimates without a countdown or automatic processing', async () => {
    let app = mount();
    await settle();
    for (let job of Object.keys(app.states)) {
        assert.equal(app.cards.get(job).querySelector('[data-job-eta]').textContent, 'Noch nicht abschätzbar');
        app.states[job].eta = 'ca. 1 Std. 20 Min.';
        app.cards.get(job).dataset.state = JSON.stringify(app.states[job]);
    }
    app.controller.update();
    for (let job of Object.keys(app.states)) {
        assert.equal(app.cards.get(job).querySelector('[data-job-eta]').textContent, 'ca. 1 Std. 20 Min.');
    }
    app.navigate(false);
    app.navigate(true);
    assert.equal(app.requests.length, 0);
    assert.equal(app.cards.get('scan').querySelector('[data-job-eta]').textContent, 'ca. 1 Std. 20 Min.');
    app.controller.dispose();
});

test('each reset requires confirmation and changes only its own job without a restart', async () => {
    let cancelled = mount();
    await settle();
    for (let job of Object.keys(cancelled.states)) await cancelled.click(job, 'reset');
    assert.deepEqual(cancelled.requests, []);
    cancelled.controller.dispose();
    let app = mount({ confirm: true });
    await settle();
    for (let job of Object.keys(app.states)) {
        await app.click(job, 'reset');
        assert.match(app.cards.get(job).querySelector('[data-job-status]').textContent, /0 % · Bereit/);
    }
    assert.deepEqual(
        app.requests.map(item => item.action),
        Array(4).fill('job-reset')
    );
    app.controller.dispose();
});

test('pending resets block double clicks and stale polling without changing other CLI runs', async () => {
    let app = mount({ confirm: true, deferReset: true });
    await settle();
    app.states.faces.status = 'running';
    let reset = app.click('tag', 'reset');
    await app.click('tag', 'reset');
    assert.equal(app.cards.get('tag').querySelector('[data-job-action="reset"]').disabled, true);
    app.states.tag.completed = 3;
    app.states.tag.percent = 75;
    await app.refresh();
    assert.equal(app.cards.get('tag').querySelector('progress').value, 25);
    assert.equal(app.cards.get('faces').querySelector('[data-job-status]').textContent, '25 % · Läuft');
    app.finishReset();
    await reset;
    assert.deepEqual(app.requests, [{ action: 'job-reset', job: 'tag' }]);
    assert.equal(app.cards.get('tag').querySelector('[data-job-status]').textContent, '0 % · Bereit');
    assert.equal(app.cards.get('tag').querySelector('[data-job-action="reset"]').disabled, false);
    assert.equal(app.states.faces.status, 'running');
    app.controller.dispose();
});

test('a status response captured before a reset cannot restore its old progress', async () => {
    let app = mount({ confirm: true, deferRefresh: true });
    await app.click('scan', 'reset');
    app.finishRefresh();
    await settle();
    assert.match(app.cards.get('scan').querySelector('[data-job-status]').textContent, /0 % · Bereit/);
    assert.equal(app.requests.length, 1);
    app.controller.dispose();
});

test('CLI status remains readable without rendering server-side log entries', async () => {
    assert.doesNotMatch(readFileSync('templates/jobs.php', 'utf8'), /data-job-log|data-job-action="(?:start|pause)"/);
    let app = mount();
    await settle();
    app.states.previews = {
        ...app.states.previews,
        status: 'running',
        completed: 2,
        percent: 50,
        log: [{ id: 2, time: '12:00:00', message: '<img src=x onerror=alert(1)>' }]
    };
    await app.refresh();
    assert.equal(app.cards.get('previews').querySelector('[data-job-status]').textContent, '50 % · Läuft');
    assert.match(app.cards.get('previews').querySelector('[data-job-count]').textContent, /^2 \/ 4/);
    assert.equal(app.cards.get('previews').querySelector('[data-job-message]').textContent, '');
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('a rejected reset preserves progress and displays its error as plain text', async () => {
    let message = 'CLI läuft <img src=x onerror=alert(1)>';
    let app = mount({ confirm: true, resetError: message });
    await settle();
    app.states.scan.status = 'running';
    await app.refresh();
    await app.click('scan', 'reset');
    assert.equal(app.cards.get('scan').querySelector('[data-job-status]').textContent, '25 % · Läuft');
    assert.equal(app.cards.get('scan').querySelector('[data-job-message]').textContent, message);
    assert.equal(app.cards.get('scan').querySelector('[data-job-action="reset"]').disabled, false);
    assert.equal(app.cards.get('tag').querySelector('[data-job-message]').textContent, '');
    assert.deepEqual(app.requests, [{ action: 'job-reset', job: 'scan' }]);
    app.controller.dispose();
});
