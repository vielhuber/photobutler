<?php
declare(strict_types=1);
$jobsView ??= false;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $escape($csrf) ?>">
    <title><?= $escape($title) ?> · photobutler</title>
    <link rel="icon" type="image/svg+xml" href="?asset=favicon.svg">
    <script src="?asset=preferences.js"></script>
    <link rel="stylesheet" href="?asset=app.css">
    <script type="module" src="?asset=navigation.js"></script>
</head>
<body>
    <p id="navigation-message" class="navigation-message" role="alert" hidden></p>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-resize" id="sidebar-resize" role="separator" tabindex="0" aria-label="Breite der Seitenleiste" aria-orientation="vertical" aria-controls="sidebar" aria-valuemin="210" aria-valuemax="640" aria-valuenow="250"></div>
        <a class="brand" href="?<?= $escape(
            $galleryPreferences
        ) ?>"><span class="brand-icon" aria-hidden="true">▧</span> photobutler<span class="brand-dot">.</span></a>
        <p class="nav-label">BIBLIOTHEK</p>
        <nav aria-label="Bibliothek">
            <a class="nav-item <?= !$jobsView && !$peopleView && $favorites === '0' && $album === ''
                ? 'active'
                : '' ?>" href="?<?= $escape(
    $galleryPreferences
) ?>"><span aria-hidden="true">▦</span> Alle Fotos <small><?= $stats['total'] ?></small></a>
            <a class="nav-item <?= $peopleView ? 'active' : '' ?>" href="?view=persons&amp;<?= $escape(
    $galleryPreferences
) ?>"><span aria-hidden="true">♙</span> Personen <small><?= count($persons) ?></small></a>
            <a class="nav-item <?= $jobsView
                ? 'active'
                : '' ?>" href="?view=jobs"><span aria-hidden="true">⚙</span> Jobs</a>
        </nav>
        <form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?= $escape(
            $csrf
        ) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="quiet">Abmelden ↗</button></form>
    </aside>
    <main class="main">
        <header class="topbar"><span>Bibliothek <span class="muted">/ <?= $escape(
            $jobsView
                ? 'Jobs'
                : ($peopleView
                    ? 'Personen'
                    : ($album !== ''
                        ? basename($album)
                        : match ($favorites) {
                            '1' => 'Favoriten',
                            'none' => 'Keine Favoriten',
                            default => 'Alle Fotos'
                        }))
        ) ?></span></span></header>
        <section class="intro">
            <div><h1><?= $escape($title) ?></h1><p class="muted"><?= number_format(
    (int) $stats['total'],
    0,
    ',',
    '.'
) ?> Fotos</p></div>
        </section>
        <?php if ($jobsView) {
            require __DIR__ . '/jobs.php';
        } ?>
        <?php if ($peopleView) {
            require __DIR__ . '/persons.php';
        } ?>
        <div<?= $peopleView || $jobsView ? ' hidden' : '' ?>>
        <?php if ($tags !== []): ?><nav class="tags" aria-label="Schlagwörter"><?php foreach (
    $tags
    as $item
): ?><a class="chip <?= $tag === $item['name'] ? 'selected' : '' ?>" href="?<?= $escape(
    http_build_query([
        'person' => $person,
        'tag' => $item['name'],
        'album' => $album,
        'favorites' => $favorites,
        'sort' => $sort,
        'relevance' => $relevance,
        'seed' => $sort === 'random' ? $seed : ''
    ])
) ?>"><?= $escape($item['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>
        <div class="gallery-filters"><label class="person-filter"><select id="gallery-relevance" aria-label="Relevanz"><option value="all"<?= $relevance ===
        'all'
            ? ' selected'
            : '' ?>>Alle anzeigen</option><option value="relevant"<?= $relevance === 'relevant'
    ? ' selected'
    : '' ?>>Eingeblendete Fotos</option><option value="unrated"<?= $relevance === 'unrated'
    ? ' selected'
    : '' ?>>Nicht bewertete Fotos</option><option value="excluded"<?= $relevance === 'excluded'
    ? ' selected'
    : '' ?>>Ausgeblendete Fotos</option></select></label>
        <label class="person-filter"><select id="gallery-favorites" aria-label="Favoriten"><option value="0"<?= $favorites ===
        '0'
            ? ' selected'
            : '' ?>>Alle anzeigen</option><option value="1"<?= $favorites === '1'
    ? ' selected'
    : '' ?>>Favoriten</option><option value="none"<?= $favorites === 'none'
    ? ' selected'
    : '' ?>>Keine Favoriten</option></select></label>
        <label class="person-filter"><select id="gallery-person" aria-label="Person"><option value="0">Alle Personen</option><?php foreach (
            $persons
            as $item
        ): ?><option value="<?= (int) $item['id'] ?>"<?= $person === (int) $item['id']
    ? ' selected'
    : '' ?>><?= $escape($item['name'] ?: 'Person ' . $item['id']) ?></option><?php endforeach; ?></select></label></div>
        <section class="photos-section"><div class="section-heading"><h2>Fotos</h2><label class="gallery-view" for="gallery-columns"><select id="gallery-columns" aria-label="Spalten"><option value="3">3</option><option value="4">4</option><option value="5" selected>5</option><option value="6">6</option><option value="7">7</option><option value="8">8</option><option value="9">9</option></select></label><label class="gallery-view gallery-sorting" for="gallery-sort"><select id="gallery-sort" aria-label="Sortierung" data-seed="<?= $sort ===
        'random'
            ? $escape($seed)
            : '' ?>"><?php foreach (
    \vielhuber\photobutler\PhotoButler::SORT_OPTIONS
    as $value => $label
): ?><option value="<?= $escape($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= $escape(
    $label
) ?></option><?php endforeach; ?></select></label><button id="gallery-slideshow" class="chip" type="button"<?= $photos ===
[]
    ? ' disabled'
    : '' ?>>Slideshow</button></div>
            <?php if (
                $person > 0 ||
                $album !== '' ||
                $favorites !== '0' ||
                $tag !== '' ||
                $relevance !== 'all'
            ): ?><a class="reset" href="?sort=<?= $escape($sort) ?>">Filter zurücksetzen ×</a><?php endif; ?>
            <div class="photo-grid">
                <?php foreach (
                    $photos
                    as $photo
                ): ?><div class="photo-tile"><button class="photo-card" data-priority="<?= $photo->priority ?>" data-preview-state="loading" aria-busy="true" type="button" data-photo="<?= $photo->id ?>" aria-label="<?= $escape(
    $photo->name
) ?> öffnen"><img src="?photo=<?= $photo->id ?>&amp;size=display" alt="<?= $escape(
    $photo->description !== '' ? $photo->description : $photo->name
) ?>" loading="lazy"<?= $photo->width > 0 && $photo->height > 0
    ? ' width="' . $photo->width . '" height="' . $photo->height . '"'
    : '' ?>><span class="photo-caption"><strong><?= $escape($photo->name) ?></strong><small><?= $escape(
    substr($photo->taken, 0, 10)
) ?></small></span></button><div class="photo-actions"><button class="favorite-marker" type="button" data-priority-photo="<?= $photo->id ?>" data-priority="1" data-favorite="<?= $photo->id ?>" aria-label="Favorit" aria-pressed="<?= $photo->priority ===
1
    ? 'true'
    : 'false' ?>"><?= $photo->favorite
    ? '♥'
    : '♡' ?></button><button type="button" data-priority-photo="<?= $photo->id ?>" data-priority="-1" aria-label="Ausschließen" aria-pressed="<?= $photo->priority ===
-1
    ? 'true'
    : 'false' ?>">×</button></div></div><?php endforeach; ?>
            </div>
            <?php if ($photos === []): ?><div class="empty"><span aria-hidden="true">▧</span><h2><?= (int) $stats[
    'total'
] === 0
    ? 'Noch keine Fotos'
    : 'Keine Fotos gefunden' ?></h2><p><?= (int) $stats['total'] === 0
    ? 'Noch keine Bilder in der Bibliothek.'
    : 'Filter ändern oder entfernen.' ?></p></div><?php endif; ?>
            <div id="photo-loader" class="photo-loader" data-offset="<?= $offset ?>" data-next="<?= count($photos) ===
60
    ? '?' . $escape(http_build_query($pagination + ['page' => $page + 1, 'offset' => $offset + count($photos)]))
    : '' ?>">
                <p id="photo-load-message" class="muted" role="status"></p>
                <button id="photo-retry" class="chip" type="button" hidden>Erneut versuchen</button>
            </div>
        </section>
        </div>
    </main>
    <dialog id="viewer" data-selected-photo="<?= $selectedPhoto ?>" aria-labelledby="viewer-title"><div class="viewer-layout"><div class="viewer-stage" data-preview-state="loading" aria-busy="true"><button class="viewer-close" type="button" aria-label="Bildansicht schließen">×</button><button id="slideshow-stop" type="button" hidden>Slideshow stoppen</button><button class="viewer-previous" type="button" aria-label="Vorheriges Foto">‹</button><img id="viewer-image" alt=""><button class="viewer-next" type="button" aria-label="Nächstes Foto">›</button></div><section class="viewer-info"><h2 id="viewer-title"></h2><p id="viewer-date" class="muted"></p><p id="viewer-description"></p><button id="viewer-favorite" class="chip" type="button">♡ Als Favorit</button><div id="viewer-persons" class="viewer-persons" aria-label="Erkannte Personen"></div><form id="tag-form"><label for="viewer-tags">Schlagwörter</label><textarea id="viewer-tags" rows="4" placeholder="Tags mit Komma trennen"></textarea><small class="muted">Eigene Tags ersetzen KI-Tags.</small><button class="primary" type="submit">Tags speichern</button></form><a id="viewer-download" class="download" href="./">Original herunterladen ↗</a><button id="face-retry" class="chip" type="button">Gesichter erneut prüfen</button><button id="face-erase" class="chip" type="button">Gesichtsdaten löschen …</button><p id="viewer-message" role="status"></p></section></div></dialog>
</body>
</html>
