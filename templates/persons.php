<?php
declare(strict_types=1) ?>
<section class="people-section" aria-label="Personenverwaltung">
    <p class="muted">Automatische Vorschläge bitte prüfen. Nach Trennungen bleiben die beteiligten Gruppen vor automatischen Zuordnungen geschützt.</p>
    <?php if ($selectedPerson === null): ?>
        <?php if (
            $hiddenPersons > 0 ||
            $hiddenView
        ): ?><p class="muted">Unbenannte Gruppen erscheinen erst ab <?= \vielhuber\photobutler\FaceStore::LISTED_MIN_PHOTOS ?> Fotos an mindestens <?= \vielhuber\photobutler\FaceStore::LISTED_MIN_DAYS ?> Tagen; selten gesehene und manuell ausgeblendete Gruppen stehen unter „Ausgeblendete Gruppen“. <a class="chip" href="?<?= $escape(
     'view=persons&' . ($hiddenView ? '' : 'hidden=1&') . $galleryPreferences
 ) ?>"><?= $escape(
    $hiddenView
        ? 'Eingeblendete Personen anzeigen (' . $shownPersons . ')'
        : 'Ausgeblendete Gruppen anzeigen (' . $hiddenPersons . ')'
) ?></a></p><?php endif; ?>
        <div class="person-grid">
            <?php foreach ($persons as $item): ?>
                <a class="person-card" href="?view=persons&amp;person=<?= (int) $item['id'] ?>&amp;<?= $escape(
    $galleryPreferences
) ?>">
                    <?php if ($item['cover']): ?><img src="?face=<?= (int) $item[
    'cover'
] ?>" alt="" loading="lazy"><?php endif; ?>
                    <strong><?= $escape($item['name'] ?: 'Person ' . $item['id']) ?></strong>
                    <span><?= (int) $item['total'] ?> Fotos<?= $item['hidden'] ? ' · ausgeblendet' : '' ?></span>
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
        <form class="person-form" method="post">
            <input type="hidden" name="action" value="face-<?= $selectedPerson['hidden']
                ? 'show'
                : 'hide' ?>"><input type="hidden" name="id" value="<?= $person ?>">
            <button class="chip" type="submit"><?= $selectedPerson['hidden']
                ? 'Person einblenden'
                : 'Person ausblenden' ?></button>
            <?php if (
                $selectedPerson['hidden']
            ): ?><span class="muted">Ausgeblendet: erscheint nicht unter Personen, im Personenfilter und in der Bildansicht.</span><?php endif; ?>
        </form>
        <template id="person-picker-options">
    <?php foreach ($persons as $item):
        if ((int) $item['id'] === $person) {
            continue;
        } ?>
        <label class="person-picker-option"><input type="radio" name="target" value="<?= (int) $item[
            'id'
        ] ?>"><?php if ($item['cover']): ?><img src="?face=<?= (int) $item[
    'cover'
] ?>" alt="" loading="lazy"><?php endif; ?><span><?= $escape(
    ($item['name'] ?: 'Unbenannt') .
        ' · Person ' .
        $item['id'] .
        ' · ' .
        $item['total'] .
        ' Fotos' .
        ($selectedPerson['name'] !== '' && mb_strtolower($selectedPerson['name']) === mb_strtolower($item['name'])
            ? ' · Gleicher Name'
            : '')
) ?></span></label>
    <?php
    endforeach; ?>
        </template>
        <form class="person-form" method="post">
            <input type="hidden" name="action" value="face-merge"><input type="hidden" name="id" value="<?= $person ?>">
            <span>Zusammenführen mit</span>
            <?php
            $pickerPrompt = 'Zielperson auswählen';
            require __DIR__ . '/person-picker.php';
            ?>
            <button class="chip" type="submit">Zusammenführen …</button>
        </form>
        <p class="muted">Gleiche Namen dürfen verschiedene Menschen bezeichnen. Gruppen werden nur zusammengeführt, wenn du eine Zielperson auswählst.</p>
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
                        <span>Zuordnen zu</span>
                        <?php
                        $pickerPrompt = 'Person auswählen';
                        require __DIR__ . '/person-picker.php';
                        ?><button class="chip" type="submit">Umordnen</button>
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
