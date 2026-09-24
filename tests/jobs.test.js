let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

function mount({ confirm = false, deferRefresh = false } = {}) {
    let requests = [];
    let pending = [];
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
            setTimeout: callback => {
                refresh = callback;
                return 1;
            },
            clearTimeout() {},
            fetch: async (url, options) => {
                if (!options) {
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
                let result = states[job];
                if (action === 'job-start')
                    result = states[job] = { ...result, status: 'running', token: job + '-token' };
                if (action === 'job-pause') result = states[job] = { ...result, status: 'paused' };
                if (action === 'job-reset')
                    result = states[job] = { ...result, status: 'idle', token: '', completed: 0, percent: 0 };
                if (action === 'job-step') result = await new Promise(resolve => pending.push({ job, resolve }));
                return { ok: true, headers: { get: () => 'application/json' }, json: async () => result };
            }
        }
    );
    return {
        requests,
        pending,
        cards,
        states,
        controller,
        refresh() {
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
        },
        complete(job, more = false) {
            let index = pending.findIndex(item => item.job === job);
            assert.notEqual(index, -1);
            let [{ resolve }] = pending.splice(index, 1);
            states[job] = {
                ...states[job],
                status: states[job].status === 'paused' ? 'paused' : more ? 'running' : 'done',
                completed: 4,
                percent: 100
            };
            resolve(states[job]);
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
            assert.equal(app.cards.get(job).querySelector('[data-job-action="start"]').disabled, false);
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

test('all four jobs run independently, pause at the step boundary and resume only manually', async () => {
    let app = mount();
    for (let job of Object.keys(app.states)) app.click(job, 'start');
    await settle();
    assert.equal(app.pending.length, 4);
    await app.click('faces', 'pause');
    app.complete('faces', true);
    await settle();
    assert.equal(
        app.pending.some(item => item.job === 'faces'),
        false
    );
    assert.equal(app.pending.length, 3);
    app.navigate(false);
    app.complete('tag', true);
    await settle();
    assert.equal(
        app.pending.some(item => item.job === 'tag'),
        true
    );
    app.navigate(true);
    assert.match(app.cards.get('faces').querySelector('[data-job-status]').textContent, /Pausiert/);
    app.click('faces', 'start');
    await settle();
    assert.equal(app.pending.length, 4);
    for (let job of Object.keys(app.states)) app.complete(job);
    await settle();
    for (let job of Object.keys(app.states)) {
        assert.match(app.cards.get(job).querySelector('[data-job-status]').textContent, /100 % · Abgeschlossen/);
        assert.equal(app.cards.get(job).querySelector('[data-job-action="start"]').disabled, false);
    }
    app.controller.dispose();
});

test('completing an import never starts tagging, face analysis or preview generation', async () => {
    let app = mount();
    app.click('scan', 'start');
    await settle();
    app.complete('scan');
    await settle();
    assert.deepEqual(
        app.requests.map(item => item.job),
        ['scan', 'scan']
    );
    app.controller.dispose();
});

test('double starts are ignored and disposal never schedules a new processing step', async () => {
    let app = mount();
    app.click('tag', 'start');
    app.click('tag', 'start');
    await settle();
    app.controller.dispose();
    app.complete('tag', true);
    await settle();
    assert.deepEqual(
        app.requests.map(item => item.action),
        ['job-start', 'job-step', 'job-pause']
    );
});

test('the removed combined reset is neither offered nor handled', async () => {
    assert.doesNotMatch(readFileSync('templates/jobs.php', 'utf8'), /analysis-reset|KI-Tags und Gesichter/);
    let app = mount({ confirm: true });
    await settle();
    await app.reset();
    assert.deepEqual(app.requests, []);
    app.controller.dispose();
});

test('a stale run cannot send a global pause after another browser replaced its token', async () => {
    let app = mount();
    app.click('tag', 'start');
    await settle();
    app.controller.dispose();
    app.pending.shift().resolve({ ...app.states.tag, status: 'paused', token: undefined });
    await settle();
    assert.deepEqual(
        app.requests.map(item => item.action),
        ['job-start', 'job-step']
    );
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

test('reset waits for its in-flight step, blocks double clicks and leaves other runs alone', async () => {
    let app = mount({ confirm: true });
    app.click('tag', 'start');
    app.click('faces', 'start');
    await settle();
    let reset = app.click('tag', 'reset');
    await app.click('tag', 'reset');
    await app.click('tag', 'start');
    assert.equal(
        app.requests.some(item => item.action === 'job-reset'),
        false
    );
    app.complete('tag', true);
    await reset;
    assert.deepEqual(
        app.requests.filter(item => item.job === 'tag').map(item => item.action),
        ['job-start', 'job-step', 'job-pause', 'job-reset']
    );
    assert.equal(app.pending.length, 1);
    assert.equal(app.pending[0].job, 'faces');
    assert.match(app.cards.get('tag').querySelector('[data-job-status]').textContent, /0 % · Bereit/);
    app.complete('faces');
    await settle();
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

test('live logs update during pending steps without overwriting progress or accepting older entries', async () => {
    let app = mount();
    await settle();
    app.click('previews', 'start');
    await settle();
    app.states.previews = {
        ...app.states.previews,
        completed: 99,
        log: [{ id: 2, time: '12:00:00', message: 'Erzeuge Foto 1 …' }]
    };
    await app.refresh();
    let $log = app.cards.get('previews').querySelector('[data-job-log]');
    assert.match($log.textContent, /Erzeuge Foto 1/);
    assert.match(app.cards.get('previews').querySelector('[data-job-count]').textContent, /^1 \/ 4/);
    app.states.previews.log = [{ id: 1, time: '11:59:59', message: 'Veraltet' }];
    await app.refresh();
    assert.doesNotMatch($log.textContent, /Veraltet/);
    app.complete('previews');
    await settle();
    assert.match($log.textContent, /Erzeuge Foto 1/);
    app.controller.dispose();
});

test('log messages remain plain text and confirmed resets clear only their own history', async () => {
    let app = mount({ confirm: true });
    await settle();
    for (let job of Object.keys(app.states)) {
        app.states[job].log = [{ id: 3, time: '12:00:00', message: '<img src=x onerror=alert(1)>' }];
        app.cards.get(job).dataset.state = JSON.stringify(app.states[job]);
    }
    app.controller.update();
    let $log = app.cards.get('scan').querySelector('[data-job-log]');
    assert.match($log.textContent, /<img/);
    app.states.scan.log = [];
    await app.click('scan', 'reset');
    assert.equal($log.textContent, 'Noch keine Aktivitäten.');
    assert.match(app.cards.get('tag').querySelector('[data-job-log]').textContent, /<img/);
    app.controller.dispose();
});
