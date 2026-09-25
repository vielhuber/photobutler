export function initializeJobs($main) {
    let states = new Map();
    let messages = new Map();
    let resets = new Set();
    let revisions = new Map();
    let disposed = false;
    let poll;

    function render() {
        for (let $card of $main.querySelectorAll('[data-job]')) {
            let job = $card.dataset.job;
            let state = states.get(job) || JSON.parse($card.dataset.state);
            let labels = {
                idle: 'Bereit',
                running: 'Läuft',
                paused: 'Pausiert',
                done: 'Abgeschlossen',
                error: 'Mit Fehlern beendet'
            };
            $card.querySelector('[data-job-status]').textContent = `${state.percent} % · ${labels[state.status]}`;
            $card.querySelector('progress').value = state.percent;
            $card.querySelector('[data-job-eta]').textContent = state.eta || 'Noch nicht abschätzbar';
            $card.querySelector('[data-job-count]').textContent =
                `${state.completed} / ${state.total}${state.estimated ? ' (geschätzt)' : ''} · ${state.errors} Fehler`;
            $card.querySelector('[data-job-action="reset"]').disabled = resets.has(job);
            $card.querySelector('[data-job-message]').textContent =
                messages.get(job) ||
                state.warning ||
                (state.errors
                    ? 'Fehler prüfen und manuell erneut starten.' +
                      (['tag', 'faces'].includes(job) ? ' Wiederholung frühestens nach einer Stunde.' : '')
                    : '');
        }
    }

    async function request(job) {
        let body = new FormData();
        body.set('csrf', document.querySelector('meta[name="csrf-token"]').content);
        body.set('action', 'job-reset');
        body.set('job', job);
        let response = await fetch('./', { method: 'POST', body });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Bitte neu anmelden.');
        let result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Job konnte nicht ausgeführt werden.');
        return result;
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
                messages.set(job, 'Daten werden zurückgesetzt …');
                render();
                try {
                    if (disposed) return;
                    let state = await request(job);
                    states.set(job, state);
                    messages.set(job, 'Daten zurückgesetzt. Erneut über die Konsole starten.');
                } catch (error) {
                    messages.set(job, error.message);
                } finally {
                    resets.delete(job);
                    render();
                }
                return;
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
                    if (!resets.has(job) && versions.get(job) === revisions.get(job)) states.set(job, state);
                }
                render();
                let $message = document.querySelector('#jobs-message');
                if ($message) $message.textContent = '';
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
                if (!resets.has($card.dataset.job)) states.set($card.dataset.job, JSON.parse($card.dataset.state));
            }
            render();
        },
        dispose() {
            disposed = true;
            clearTimeout(poll);
        }
    };
}
