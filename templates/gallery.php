<?php
declare(strict_types=1) ?>
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
        <a class="brand" href="./"><span class="brand-icon" aria-hidden="true">▧</span> photobutler<span class="brand-dot">.</span></a>
        <p class="nav-label">BIBLIOTHEK</p>
        <nav aria-label="Bibliothek">
            <a class="nav-item <?= !$favorites && $album === ''
                ? 'active'
                : '' ?>" href="./"><span aria-hidden="true">▦</span> Alle Fotos <small><?= $stats[
    'total'
] ?></small></a>
            <a class="nav-item <?= $favorites
                ? 'active'
                : '' ?>" href="?favorites=1"><span aria-hidden="true">♡</span> Favoriten <small><?= $stats[
    'favorites'
] ?></small></a>
        </nav>
        <div class="nav-heading"><p class="nav-label">ALBEN</p><span><?= count($albums) ?></span></div>
        <nav class="album-nav" aria-label="Alben">
            <?php foreach ($albums as $item): ?>
                <a class="nav-item <?= $album === $item['album'] ? 'active' : '' ?>" href="?album=<?= rawurlencode(
    $item['album']
) ?>" title="<?= $escape($item['album']) ?>"><span aria-hidden="true">▱</span><span class="truncate"><?= $escape(
    $item['album']
) ?></span><small><?= $item['total'] ?></small></a>
            <?php endforeach; ?>
            <?php if ($albums === []): ?><p class="muted sidebar-hint">Noch keine Alben.</p><?php endif; ?>
        </nav>
        <section class="worker sidebar-worker" aria-label="KI-Verschlagwortung">
            <div class="ai-note"><span aria-hidden="true">✧</span><div><strong id="tag-count"><?= $stats[
                'tagged'
            ] ?> Fotos mit KI-Tags</strong><small id="tag-pending" data-queued="<?= (int) $stats[
     'queued'
 ] ?>"><?= (int) $stats['total'] - (int) $stats['tagged'] ?> noch offen<?= (int) $stats['errors'] > 0
     ? ' · ' . $stats['errors'] . ' mit Fehler'
     : '' ?></small></div></div>
            <div class="worker-actions">
                <button id="tag-start" class="chip" type="button">KI-Tags erstellen</button>
                <button id="worker-stop" class="chip" type="button" hidden>Stoppen</button>
                <a id="worker-refresh" class="chip" href="" hidden>Galerie aktualisieren</a>
            </div>
            <progress id="worker-progress" aria-label="Fortschritt" max="<?= max(
                1,
                (int) $stats['total']
            ) ?>" value="<?= (int) $stats['tagged'] ?>"></progress>
            <p id="worker-message" class="muted" role="status">Automatisch, solange diese Seite geöffnet ist.</p>
        </section>
        <div class="storage-note"><span class="status-dot"></span> Selbst gehostet</div>
        <form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?= $escape(
            $csrf
        ) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="quiet">Abmelden ↗</button></form>
    </aside>
    <main class="main">
        <header class="topbar"><span>Bibliothek <span class="muted">/ <?= $escape(
            $album !== '' ? basename($album) : ($favorites ? 'Favoriten' : 'Alle Fotos')
        ) ?></span></span><span class="private-badge">● Privat</span></header>
        <section class="intro">
            <div><h1><?= $escape($title) ?></h1><p class="muted"><?= number_format(
    (int) $stats['total'],
    0,
    ',',
    '.'
) ?> Fotos · <?= count($albums) ?> Alben</p></div>
        </section>
        <section class="worker" aria-label="Fotoverwaltung">
            <button id="scan-start" class="chip" type="button">Fotos einlesen</button>
        </section>
        <form class="search" method="get" role="search"><span aria-hidden="true">⌕</span><input aria-label="Fotos durchsuchen" name="q" value="<?= $escape(
            $query
        ) ?>" placeholder="Fotos suchen …"><input type="hidden" name="album" value="<?= $escape(
    $album
) ?>"><input type="hidden" name="favorites" value="<?= $favorites
    ? '1'
    : '0' ?>"><button type="submit">Suchen <span aria-hidden="true">↵</span></button></form>
        <?php if (
            $tags !== []
        ): ?><nav class="tags" aria-label="Schlagwörter"><span class="muted">Tags</span><?php foreach (
    $tags
    as $item
): ?><a class="chip <?= $tag === $item['name'] ? 'selected' : '' ?>" href="?<?= $escape(
    http_build_query(['tag' => $item['name'], 'album' => $album, 'favorites' => $favorites ? '1' : '0'])
) ?>"><?= $escape($item['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>
        <?php if ($album === '' && !$favorites && $query === '' && $tag === '' && $page === 1 && $albums !== []): ?>
            <details class="albums-section"><summary class="section-heading"><h2>Alle Alben (<?= count(
                $albums
            ) ?>)</h2><span aria-hidden="true">▾</span></summary><div class="album-cards"><?php foreach (
    $albums
    as $item
): ?><a class="album-card" data-preview-state="loading" aria-busy="true" href="?album=<?= rawurlencode(
    $item['album']
) ?>"><img src="?photo=<?= $item['cover'] ?>&amp;size=display" alt="" loading="lazy"><div><strong><?= $escape(
    basename($item['album'])
) ?></strong><span><?= $item[
    'total'
] ?> Fotos <span aria-hidden="true">↗</span></span></div></a><?php endforeach; ?></div></details>
        <?php endif; ?>
        <section class="photos-section"><div class="section-heading"><h2><?= $query !== '' || $tag !== ''
            ? 'Suchergebnisse'
            : 'Fotos' ?></h2><label class="gallery-view" for="gallery-columns">Spalten <select id="gallery-columns"><option value="5">5</option><option value="6">6</option><option value="7">7</option></select></label><span class="muted">Neueste zuerst</span></div>
            <?php if (
                $album !== '' ||
                $query !== '' ||
                $tag !== ''
            ): ?><a class="reset" href="./">Filter zurücksetzen ×</a><?php endif; ?>
            <div class="photo-grid">
                <?php foreach (
                    $photos
                    as $photo
                ): ?><button class="photo-card" data-preview-state="loading" aria-busy="true" type="button" data-photo="<?= $photo->id ?>" aria-label="<?= $escape(
    $photo->name
) ?> öffnen"><img src="?photo=<?= $photo->id ?>&amp;size=display" alt="<?= $escape(
    $photo->description !== '' ? $photo->description : $photo->name
) ?>" loading="lazy"<?= $photo->width > 0 && $photo->height > 0
    ? ' width="' . $photo->width . '" height="' . $photo->height . '"'
    : '' ?>><span class="photo-caption"><strong><?= $escape($photo->name) ?></strong><small><?= $escape(
    substr($photo->taken, 0, 10)
) ?></small></span><span class="favorite-marker" data-favorite="<?= $photo->id ?>"><?= $photo->favorite
    ? '♥'
    : '' ?></span></button><?php endforeach; ?>
            </div>
            <?php if ($photos === []): ?><div class="empty"><span aria-hidden="true">▧</span><h2><?= (int) $stats[
    'total'
] === 0
    ? 'Noch keine Fotos'
    : 'Keine Fotos gefunden' ?></h2><p><?= (int) $stats['total'] === 0
    ? 'Fotoordner einlesen, um Alben anzuzeigen.'
    : 'Suchbegriff ändern oder Filter entfernen.' ?></p></div><?php endif; ?>
            <div id="photo-loader" class="photo-loader" data-next="<?= count($photos) === 60
                ? '?' . $escape(http_build_query($pagination + ['page' => $page + 1]))
                : '' ?>">
                <p id="photo-load-message" class="muted" role="status"></p>
                <button id="photo-retry" class="chip" type="button" hidden>Erneut versuchen</button>
            </div>
        </section>
    </main>
    <dialog id="viewer" aria-labelledby="viewer-title"><div class="viewer-layout"><div class="viewer-stage" data-preview-state="loading" aria-busy="true"><button class="viewer-close" type="button" aria-label="Bildansicht schließen">×</button><button class="viewer-previous" type="button" aria-label="Vorheriges Foto">‹</button><img id="viewer-image" alt=""><button class="viewer-next" type="button" aria-label="Nächstes Foto">›</button></div><section class="viewer-info"><h2 id="viewer-title"></h2><p id="viewer-date" class="muted"></p><p id="viewer-description"></p><p id="viewer-status" class="muted"></p><button id="viewer-favorite" class="chip" type="button">♡ Als Favorit</button><form id="tag-form"><label for="viewer-tags">Schlagwörter</label><textarea id="viewer-tags" rows="4" placeholder="Tags mit Komma trennen"></textarea><small class="muted">Eigene Tags ersetzen KI-Tags.</small><button class="primary" type="submit">Tags speichern</button></form><a id="viewer-download" class="download" href="./">Original herunterladen ↗</a><p id="viewer-message" role="status"></p></section></div></dialog>
</body>
</html>
