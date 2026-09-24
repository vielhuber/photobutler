<?php
declare(strict_types=1) ?>
<section aria-label="Jobsteuerung">
    <p class="muted">Alle Jobs starten ausschließlich manuell und unabhängig. Navigation innerhalb der Bibliothek unterbricht gestartete Jobs nicht. Nach Reload oder Schließen erneut starten. Pausieren lässt den laufenden Schritt fertig werden (bei Vorschauen höchstens zwei Bilder).</p>
    <div class="jobs-grid">
        <?php foreach (\vielhuber\photobutler\JobRunner::LABELS as $job => $label):
            $state = $jobs[$job]; ?>
        <article class="job-card" data-job="<?= $escape($job) ?>" data-state="<?= $escape(
    json_encode($state, JSON_THROW_ON_ERROR)
) ?>">
            <h2><?= $escape($label) ?></h2>
            <p data-job-status role="status"><?= $state['percent'] ?> % · <?= $escape(
     match ($state['status']) {
         'running' => 'Gestartet',
         'paused' => 'Pausiert',
         'done' => 'Abgeschlossen',
         'error' => 'Mit Fehlern beendet',
         default => 'Bereit'
     }
 ) ?></p>
            <progress max="100" value="<?= $state['percent'] ?>" aria-label="<?= $escape(
    $label
) ?> Fortschritt"></progress>
            <p class="muted" data-job-count><?= $state['completed'] ?> / <?= $state['total'] .
     ($state['estimated'] ? ' (geschätzt)' : '') ?> · <?= $state['errors'] ?> Fehler</p>
            <dl class="job-estimate">
                <dt>Geschätzte Restlaufzeit</dt>
                <dd data-job-eta><?= $escape($state['eta']) ?></dd>
                <dd class="muted">Verarbeitungszeit ab Fortsetzung · ohne Pausen</dd>
            </dl>
            <div class="job-actions">
                <button type="button" class="chip" data-job-action="start">Starten / Fortsetzen</button>
                <button type="button" class="chip" data-job-action="pause"<?= $state['status'] !== 'running'
                    ? ' disabled'
                    : '' ?>>Pausieren</button>
            </div>
            <button type="button" class="chip" data-job-action="reset">Daten zurücksetzen</button>
            <p class="muted job-message" data-job-message role="status"><?= $escape($state['warning']) ?></p>
            <pre class="job-log" data-job-log role="log" aria-label="<?= $escape(
                $label
            ) ?> Aktivitäten" aria-live="polite" tabindex="0"><?= $escape(
     implode(
         "\n",
         array_map(fn(array $entry): string => '[' . $entry['time'] . '] ' . $entry['message'], $state['log'])
     ) ?:
     'Noch keine Aktivitäten.'
 ) ?></pre>
        </article>
        <?php
        endforeach; ?>
    </div>
    <p id="jobs-message" class="muted" role="status"></p>
</section>
