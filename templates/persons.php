<?php
declare(strict_types=1) ?>
<section class="people-section" aria-label="Personenverwaltung">
    <p class="muted">Automatische Vorschläge bitte prüfen. Nach Trennungen bleiben die beteiligten Gruppen vor automatischen Zuordnungen geschützt.</p>
    <?php if ($selectedPerson === null): ?>
        <div class="person-grid">
            <?php foreach ($persons as $item): ?>
                <a class="person-card" href="?view=persons&amp;person=<?= (int) $item['id'] ?>&amp;<?= $escape(
    $galleryPreferences
) ?>">
                    <?php if ($item['cover']): ?><img src="?face=<?= (int) $item[
    'cover'
] ?>" alt="" loading="lazy"><?php endif; ?>
                    <strong><?= $escape($item['name'] ?: 'Person ' . $item['id']) ?></strong>
                    <span><?= (int) $item['total'] ?> Fotos</span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($persons === []): ?><p>Noch keine Personen erkannt.</p><?php endif; ?>
    <?php else: ?>
        <a class="chip" href="?view=persons&amp;<?= $escape($galleryPreferences) ?>">Alle Personen</a>
        <a class="chip" href="?person=<?= $person ?>&amp;<?= $escape($galleryPreferences) ?>">Fotos dieser Person</a>
        <form class="person-form" method="post">
            <input type="hidden" name="action" value="face-rename"><input type="hidden" name="id" value="<?= $person ?>">
            <label>Name <input name="name" maxlength="100" value="<?= $escape($selectedPerson['name']) ?>"></label>
            <button class="chip" type="submit">Namen speichern</button>
        </form>
        <form class="person-form" method="post" data-confirm="Diese Gruppe mit der ausgewählten Zielperson zusammenführen?">
            <input type="hidden" name="action" value="face-merge"><input type="hidden" name="id" value="<?= $person ?>">
            <label>Zusammenführen mit <select name="target" required><option value="">Zielperson auswählen</option>
                <?php foreach ($persons as $item):
                    if ((int) $item['id'] === $person) {
                        continue;
                    } ?>
                    <option value="<?= (int) $item['id'] ?>"><?= $escape(
    ($item['name'] ?: 'Unbenannt') . ' · Person ' . $item['id']
) .
    ($selectedPerson['name'] !== '' && mb_strtolower($selectedPerson['name']) === mb_strtolower($item['name'])
        ? ' · Gleicher Name'
        : '') ?></option>
                <?php
                endforeach; ?>
            </select></label><button class="chip" type="submit">Zusammenführen …</button>
        </form>
        <p class="muted">Gleiche Namen dürfen verschiedene Menschen bezeichnen. Nur nach ausdrücklicher Bestätigung werden Gruppen zusammengeführt.</p>
        <div class="person-grid">
            <?php foreach ($personFaces as $face): ?>
                <article class="person-card">
                    <img src="?face=<?= (int) $face['id'] ?>" alt="Gesichtsausschnitt" loading="lazy">
                    <?php if (
                        !$face['current']
                    ): ?><p>Ältere Zuordnung oder Foto nicht verfügbar. Zur Kontrolle aufbewahrt.</p><?php endif; ?>
                    <?php if ($face['ignored']): ?><p>Ignorierter Treffer</p><?php endif; ?>
                    <?php if ($face['current']): ?><button class="chip" type="button" data-photo="<?= (int) $face[
    'photo_id'
] ?>">Foto öffnen</button><?php endif; ?>
                    <form class="person-form" method="post"><input type="hidden" name="id" value="<?= (int) $face[
                        'id'
                    ] ?>"><input type="hidden" name="action" value="face-split"><button class="chip" type="submit">Als neue Person trennen</button></form>
                    <form class="person-form" method="post"><input type="hidden" name="id" value="<?= (int) $face[
                        'id'
                    ] ?>"><input type="hidden" name="action" value="face-move">
                        <label>Zuordnen zu <select name="target" required><option value="">Person auswählen</option><?php foreach (
                            $persons
                            as $item
                        ):
                            if ((int) $item['id'] === $person) {
                                continue;
                            } ?><option value="<?= (int) $item['id'] ?>"><?= $escape(
    ($item['name'] ?: 'Unbenannt') . ' · Person ' . $item['id']
) ?></option><?php
                        endforeach; ?></select></label><button class="chip" type="submit">Umordnen</button>
                    </form>
                    <?php if (
                        !$face['ignored']
                    ): ?><form class="person-form" method="post"><input type="hidden" name="id" value="<?= (int) $face[
    'id'
] ?>"><input type="hidden" name="action" value="face-ignore"><button class="chip" type="submit">Falschen Treffer ignorieren</button></form><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
