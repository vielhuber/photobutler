<?php
declare(strict_types=1) ?>
<section aria-label="Jobsteuerung">
    <p class="muted">Jeden Job unabhängig mit dem angezeigten PHP-Befehl auf dem Server ausführen. Die Befehle eignen sich auch für Cron. Fortschritt und Status werden automatisch aktualisiert; der Browser steuert keine Verarbeitung. Abbrechen in der Konsole mit Strg+C, fortsetzen mit demselben Befehl.</p>
    <div class="jobs-grid">
        <?php foreach (\vielhuber\photobutler\JobRunner::LABELS as $job => $label):
            $state = $jobs[$job]; ?>
        <article class="job-card" data-job="<?= $escape($job) ?>" data-state="<?= $escape(
    json_encode($state, JSON_THROW_ON_ERROR)
) ?>">
            <h2><?= $escape($label) ?></h2>
            <p data-job-status role="status"><?= $state['percent'] ?> % · <?= $escape(
     match ($state['status']) {
         'running' => 'Läuft',
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
            <pre class="job-command"><code><?= $escape($jobCommands[$job]) ?></code></pre>
            <button type="button" class="chip" data-job-action="reset">Daten zurücksetzen</button>
            <p class="muted job-message" data-job-message role="status"><?= $escape($state['warning']) ?></p>
        </article>
        <?php
        endforeach; ?>
    </div>
    <p id="jobs-message" class="muted" role="status"></p>
</section>
