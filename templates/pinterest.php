<?php
declare(strict_types=1) ?>
<section id="pinterest-gallery" aria-label="Pinterest-Galerie">
    <div class="gallery-toolbar">
        <div class="gallery-filters">
            <label class="person-filter"><select id="pinterest-board" aria-label="Pinnwand" disabled><option>Pinnwände werden geladen …</option></select></label>
            <a id="pinterest-board-link" class="reset" href="https://www.pinterest.com/" target="_blank" rel="noopener noreferrer">Auf Pinterest öffnen ↗</a>
        </div>
        <div class="gallery-controls">
        <label class="gallery-view" for="pinterest-columns"><select id="pinterest-columns" aria-label="Spalten"><?php foreach (
            range(3, 9)
            as $columns
        ): ?><option value="<?= $columns ?>"<?= $columns === 5
    ? ' selected'
    : '' ?>><?= $columns ?></option><?php endforeach; ?></select></label>
        <button id="pinterest-slideshow" class="chip" type="button" disabled>Slideshow</button>
        </div>
    </div>
    <div id="pinterest-grid" class="photos-section photo-grid pinterest-grid" aria-busy="true"></div>
    <div id="pinterest-loader" class="photo-loader">
        <p id="pinterest-message" class="muted" role="status">Pinnwände werden geladen …</p>
        <button id="pinterest-retry" class="chip" type="button" hidden>Erneut versuchen</button>
    </div>
    <template id="pinterest-tile">
        <article class="photo-tile">
            <button class="photo-card" type="button" data-preview-state="loading" aria-busy="true"><img alt="" loading="lazy" referrerpolicy="no-referrer"></button>
            <p class="pinterest-caption"></p>
        </article>
    </template>
    <dialog id="pinterest-viewer" aria-labelledby="pinterest-title">
        <div class="pinterest-viewer-controls">
            <button id="pinterest-previous" type="button" aria-label="Vorheriger Pin">‹</button>
            <button id="pinterest-next" type="button" aria-label="Nächster Pin">›</button>
            <button id="pinterest-close" type="button" aria-label="Schließen">×</button>
        </div>
        <div class="viewer-layout">
            <div class="viewer-stage" data-preview-state="loading" aria-busy="true"><img id="pinterest-image" alt="" referrerpolicy="no-referrer"></div>
            <section class="viewer-info"><h2 id="pinterest-title"></h2><p id="pinterest-description"></p><a id="pinterest-pin-link" class="download" target="_blank" rel="noopener noreferrer">Auf Pinterest öffnen ↗</a><a id="pinterest-source-link" class="download" target="_blank" rel="noopener noreferrer" hidden>Originalseite öffnen ↗</a></section>
        </div>
    </dialog>
</section>
