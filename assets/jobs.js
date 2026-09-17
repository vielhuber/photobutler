export function initializeJobs($main) {
    let runs = new Map();
    let states = new Map();
    let messages = new Map();
    let logs = new Map();
    let logElements = new WeakSet();
    let resets = new Set();
    let revisions = new Map();
    let disposed = false;
    let poll;

    function updateLog(job, entries = []) {
        let previous = logs.get(job) || [];
        if ((previous.at(-1)?.id || 0) > (entries.at(-1)?.id || 0)) return;
        logs.set(job, entries);
    }

    function render() {
        for (let $card of $main.querySelectorAll('[data-job]')) {
            let job = $card.dataset.job;
            let state = states.get(job) || JSON.parse($card.dataset.state);
            let running = runs.has(job);
            updateLog(job, state.log);
            let $log = $card.querySelector('[data-job-log]');
            let content =
                logs
                    .get(job)
                    .map(entry => `[${entry.time}] ${entry.message}`)
                    .join('\n') || 'Noch keine Aktivitäten.';
            if ($log.textContent !== content || !logElements.has($log)) {
                let follow = !logElements.has($log) || $log.scrollTop + $log.clientHeight >= $log.scrollHeight - 8;
                $log.textContent = content;
                if (follow) $log.scrollTop = $log.scrollHeight;
                logElements.add($log);
            }
            let labels = {
                idle: 'Bereit',
                running: running ? 'Läuft' : 'Gestartet (anderer Browser oder unterbrochen)',
                paused: 'Pausiert',
                done: 'Abgeschlossen',
                error: 'Mit Fehlern beendet'
            };
            $card.querySelector('[data-job-status]').textContent = `${state.percent} % · ${labels[state.status]}`;
            $card.querySelector('progress').value = state.percent;
            $card.querySelector('[data-job-eta]').textContent = state.eta || 'Noch nicht abschätzbar';
            $card.querySelector('[data-job-count]').textContent =
                `${state.completed} / ${state.total}${state.estimated ? ' (geschätzt)' : ''} · ${state.errors} Fehler`;
            $card.querySelector('[data-job-action="start"]').disabled = running || resets.has(job);
            $card.querySelector('[data-job-action="pause"]').disabled =
                resets.has(job) || (!running && state.status !== 'running');
            $card.querySelector('[data-job-action="reset"]').disabled = resets.has(job);
            $card.querySelector('[data-job-message]').textContent =
                messages.get(job) ||
                (state.errors
                    ? 'Fehler prüfen und manuell erneut starten.' +
                      (['tag', 'faces'].includes(job) ? ' Wiederholung frühestens nach einer Stunde.' : '')
                    : '');
        }
    }

    async function request(action, job = '', token = '') {
        let body = new FormData();
        body.set('csrf', document.querySelector('meta[name="csrf-token"]').content);
        body.set('action', action);
        body.set('job', job);
        body.set('token', token);
        let response = await fetch('./', { method: 'POST', body });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Bitte neu anmelden.');
        let result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Job konnte nicht ausgeführt werden.');
        return result;
    }

    async function run(job, run) {
        try {
            let state = await request('job-start', job);
            states.set(job, state);
            render();
            while (!disposed && !run.stop && state.status === 'running') {
                state = await request('job-step', job, state.token);
                states.set(job, state);
                render();
            }
            if ((run.stop || disposed) && state.token) states.set(job, await request('job-pause', job, state.token));
        } catch (error) {
            messages.set(job, error.message);
        } finally {
            runs.delete(job);
            render();
        }
    }

    $main.addEventListener('click', async event => {
        let $button = event.target.closest('[data-job-action]');
        if ($button) {
            let job = $button.closest('[data-job]').dataset.job;
            if (resets.has(job)) return;
            if ($button.dataset.jobAction === 'reset') {
                let confirmations = {
                    scan: 'Eingelesenen Katalog und Importfortschritt zurücksetzen? Originale, Favoriten, manuelle Tags und Personeninformationen bleiben erhalten. Favoriten und Tags werden beim erneuten manuellen Import wieder zugeordnet.',
                    previews:
                        'Erzeugte Thumbnails und Bildgenerierungsfortschritt löschen? Originale bleiben unverändert. Es wird kein Job automatisch gestartet.',
                    tag: 'KI-generierte Tags, Beschreibungen und KI-Jobfortschritt löschen? Manuelle Tags und Favoriten bleiben erhalten.',
                    faces: 'Automatische Gesichtserkennungen und Gesichter-Jobfortschritt löschen? Manuell gepflegte Personen, Zuordnungen und Erkennungsausschlüsse bleiben erhalten.'
                };
                if (!window.confirm(confirmations[job])) return;
                resets.add(job);
                revisions.set(job, (revisions.get(job) || 0) + 1);
                messages.set(job, 'Zurücksetzen nach dem laufenden Schritt …');
                let active = runs.get(job);
                if (active) active.stop = true;
                render();
                try {
                    if (active) await active.promise;
                    if (disposed) return;
                    let state = await request('job-reset', job);
                    logs.delete(job);
                    states.set(job, state);
                    messages.set(job, 'Daten zurückgesetzt. Erneut manuell starten.');
                } catch (error) {
                    messages.set(job, error.message);
                } finally {
                    resets.delete(job);
                    render();
                }
                return;
            }
            if ($button.dataset.jobAction === 'start' && !runs.has(job)) {
                let active = { stop: false };
                runs.set(job, active);
                messages.delete(job);
                active.promise = run(job, active);
                render();
                return;
            }
            if ($button.dataset.jobAction === 'pause') {
                let revision = revisions.get(job);
                if (runs.has(job)) runs.get(job).stop = true;
                messages.set(job, 'Pausiert nach dem laufenden Schritt.');
                try {
                    let state = await request('job-pause', job);
                    if (revision === revisions.get(job)) states.set(job, state);
                } catch (error) {
                    messages.set(job, error.message);
                }
                render();
            }
            return;
        }
    });

    async function refresh() {
        if (disposed) return;
        if ($main.querySelector('[data-job]')) {
            try {
                let versions = new Map(revisions);
                let response = await fetch('?jobs=1');
                if (!response.ok) throw new Error('Status nicht verfügbar. Bitte neu anmelden.');
                let result = await response.json();
                for (let [job, state] of Object.entries(result)) {
                    if (!resets.has(job) && versions.get(job) === revisions.get(job)) updateLog(job, state.log);
                    if (!runs.has(job) && !resets.has(job) && versions.get(job) === revisions.get(job))
                        states.set(job, state);
                }
                render();
            } catch (error) {
                let $message = document.querySelector('#jobs-message');
                if ($message) $message.textContent = error.message;
            }
        }
        if (!disposed) poll = setTimeout(refresh, 3000);
    }

    render();
    refresh();
    return {
        update() {
            for (let $card of $main.querySelectorAll('[data-job]')) {
                if (!runs.has($card.dataset.job) && !resets.has($card.dataset.job))
                    states.set($card.dataset.job, JSON.parse($card.dataset.state));
            }
            render();
        },
        dispose() {
            disposed = true;
            clearTimeout(poll);
            for (let active of runs.values()) active.stop = true;
        }
    };
}
