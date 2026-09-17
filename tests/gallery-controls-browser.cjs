let fs = require('node:fs');
let os = require('node:os');
let path = require('node:path');
let net = require('node:net');
let { once } = require('node:events');
let { spawn, execFileSync } = require('node:child_process');
let assert = require('node:assert/strict');
let { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    let project = path.resolve(__dirname, '..');
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-gallery-controls-'));
    let server, browser;
    let live = process.env.PHOTOBUTLER_GALLERY_LIVE === '1';
    let url = 'https://photobutler.rebuhleiv.xyz/';
    let credentials = { username: 'gallery-test', password: 'isolated-gallery-test' };
    let php = code =>
        execFileSync(
            'php',
            [
                '-r',
                `require ${JSON.stringify(project + '/vendor/autoload.php')}; $root=${JSON.stringify(root)}; ${code}`
            ],
            { encoding: 'utf8' }
        );
    try {
        if (live) {
            credentials = JSON.parse(
                php(
                    `$settings=\\Dotenv\\Dotenv::parse(file_get_contents(${JSON.stringify(project + '/.data/.env')})); echo json_encode(['username'=>$settings['AUTH_USERNAME'],'password'=>$settings['AUTH_PASSWORD']]);`
                )
            );
        } else {
            for (let directory of ['.data', 'public', 'photos']) fs.mkdirSync(root + '/' + directory);
            fs.writeFileSync(
                root + '/.data/.env',
                `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=${credentials.username}\nAUTH_PASSWORD=${credentials.password}\nJWT_SECRET=isolated-gallery-controls-signing-secret\n`
            );
            fs.writeFileSync(
                root + '/public/index.php',
                `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; if (($_POST['action'] ?? '') === 'priority') { usleep(1200000); if (is_file(dirname(__DIR__).'/.data/rating-failure')) { http_response_code(503); exit; } } (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
            );
            php(`
                $image=imagecreatetruecolor(80,60);
                imagefilledrectangle($image,0,0,79,59,imagecolorallocate($image,50,140,190));
                imagefilledellipse($image,40,30,30,30,imagecolorallocate($image,240,160,45));
                $paths=['old/old.jpg','_WHATSAPP/.Statuses/story.jpg','_WHATSAPP/WhatsApp Animated Gifs/Sent/animation.jpg','_WHATSAPP/WhatsApp Images/animation.gif'];
                for($i=1;$i<=62;$i++) $paths[]='gallery/photo-'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'.jpg';
                foreach($paths as $path){$target=$root.'/photos/'.$path; if(!is_dir(dirname($target))) mkdir(dirname($target),0700,true); imagejpeg($image,$target);}
                $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index();
                foreach($library->database->query('SELECT id FROM photos')->fetchAll(PDO::FETCH_COLUMN) as $id) $library->imagePath((int)$id);
                $library->database->exec("UPDATE photos SET priority=CASE WHEN name NOT LIKE 'photo-%' THEN -1 ELSE (name >= 'photo-001' AND name < 'photo-011') END, taken=CASE WHEN album='old' THEN '2022-12-31 23:59:59' ELSE '2024-01-01 00:00:00' END");
            `);
            let socket = net.createServer();
            socket.listen(0, '127.0.0.1');
            await once(socket, 'listening');
            let port = socket.address().port;
            await new Promise(resolve => socket.close(resolve));
            url = `http://127.0.0.1:${port}/`;
            server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
                stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
            });
        }
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        let errors = [],
            writes = [],
            documents = 0,
            ratingResponses = 0;
        page.on('response', response => {
            if (response.request().method() === 'POST' && response.request().postData()?.includes('name="priority"'))
                ratingResponses++;
        });
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents++;
            if (request.method() === 'POST' && request.postData()?.includes('job-')) writes.push(request.postData());
        });
        await page.goto(url + (live ? '?album=__photobutler_controls_no_match__' : ''));
        await page.getByLabel('Benutzername').fill(credentials.username);
        await page.getByLabel('Passwort', { exact: true }).fill(credentials.password);
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('#gallery-relevance');
        if (live) {
            for (let asset of ['app.js', 'navigation.js']) {
                let response = await page.request.get(url + '?asset=' + asset);
                assert.equal(response.ok(), true);
                assert.equal(await response.text(), fs.readFileSync(project + '/assets/' + asset, 'utf8'));
            }
        }
        assert.equal(await page.locator('.album-nav, .albums-section, .album-card').count(), 0);
        assert.equal(await page.getByText(/Alben/).count(), 0);
        assert.equal(await page.locator('.storage-note, .private-badge').count(), 0);
        assert.equal(await page.locator('#gallery-relevance').inputValue(), 'relevant');
        assert.equal(await page.locator('#gallery-relevance option').first().getAttribute('value'), 'all');
        assert.deepEqual(await page.locator('#gallery-relevance option').allTextContents(), [
            'Alle anzeigen',
            'Eingeblendete Fotos',
            'Nicht bewertete Fotos',
            'Ausgeblendete Fotos'
        ]);
        assert.equal(await page.locator('#gallery-favorites').inputValue(), '0');
        assert.deepEqual(await page.locator('#gallery-favorites option').allTextContents(), [
            'Alle anzeigen',
            'Favoriten',
            'Keine Favoriten'
        ]);
        assert.equal(await page.locator('.search, input[name="q"], .sidebar a[href*="favorites="]').count(), 0);
        for (let id of ['relevance', 'favorites', 'person', 'columns', 'sort']) {
            assert.ok(await page.locator('#gallery-' + id).getAttribute('aria-label'));
            assert.equal(
                await page.locator('#gallery-' + id).evaluate($select =>
                    [...$select.parentElement.childNodes]
                        .filter($node => $node.nodeType === Node.TEXT_NODE)
                        .map($node => $node.textContent)
                        .join('')
                        .trim()
                ),
                ''
            );
        }
        if (!live) {
            let $card = page.locator('.photo-card[data-priority="0"]').first();
            await $card.scrollIntoViewIfNeeded();
            await page.waitForFunction(() => {
                let $card = document.querySelector('.photo-card[data-priority="0"]');
                return $card.dataset.previewState === 'ready';
            });
            let id = await $card.getAttribute('data-photo');
            $card = page.locator(`[data-photo="${id}"]`);
            assert.equal(await $card.locator('img').evaluate($image => getComputedStyle($image).opacity), '0.5');
            await $card.click();
            await page.waitForFunction(() => !document.querySelector('#viewer-favorite').disabled);
            assert.equal(await page.locator('#viewer-image').evaluate($image => getComputedStyle($image).opacity), '1');
            await page.locator('#viewer-favorite').click();
            await page.waitForFunction(id => document.querySelector(`[data-favorite="${id}"]`).textContent === '♥', id);
            assert.equal(await $card.locator('img').evaluate($image => getComputedStyle($image).opacity), '1');
            await page.locator('#viewer-favorite').click();
            await page.waitForFunction(id => document.querySelector(`[data-favorite="${id}"]`).textContent === '♡', id);
            assert.equal(await $card.locator('img').evaluate($image => getComputedStyle($image).opacity), '0.5');
            await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
        }
        let loadedDocuments = documents;
        await page.locator('#gallery-columns').selectOption('7');
        await page.locator('#gallery-relevance').selectOption('relevant');
        await page.waitForURL(/relevance=relevant/);
        await page.locator('#gallery-sort').selectOption('random');
        await page.waitForURL(/seed=[a-f0-9]{16}/);
        assert.equal(documents, loadedDocuments);
        assert.equal(await page.locator('#gallery-columns').inputValue(), '7');
        let seed = new URL(page.url()).searchParams.get('seed');
        let firstIds = await page
            .locator('[data-photo]')
            .evaluateAll($cards => $cards.slice(0, 60).map($card => $card.dataset.photo));
        if (!live) {
            assert.equal(firstIds.length, 60);
            assert.ok(
                (await page.locator('.photo-caption strong').allTextContents()).every(name => name.startsWith('photo-'))
            );
            await page.getByRole('button', { name: 'Slideshow', exact: true }).click();
            await page.waitForFunction(
                () =>
                    document.querySelector('#viewer-image').complete &&
                    document.querySelector('#viewer-image').naturalWidth > 0 &&
                    document.querySelector('#viewer-title').textContent.startsWith('photo-')
            );
            await page.waitForFunction(() => document.fullscreenElement !== null);
            assert.equal(await page.locator('.viewer-info').isVisible(), false);
            assert.equal(
                await page.evaluate(() => Boolean(document.elementFromPoint(20, 20)?.closest('#viewer'))),
                true
            );
            assert.equal(await page.locator('#viewer-image').evaluate($image => getComputedStyle($image).opacity), '1');
            assert.equal(
                await page
                    .locator('.viewer-stage')
                    .evaluate($stage => Math.round($stage.getBoundingClientRect().height) === innerHeight),
                true
            );
            let first = await page.locator('#viewer-title').textContent();
            let started = Date.now();
            await page.waitForFunction(
                name =>
                    document.querySelector('#viewer-title').textContent !== name &&
                    document.querySelector('#viewer-title').textContent.startsWith('photo-'),
                first,
                { timeout: 12000 }
            );
            assert.ok(Date.now() - started >= 5500, 'image shown for about six real seconds');
            await page.getByRole('button', { name: 'Slideshow stoppen' }).click();
            let stopped = await page.locator('#viewer-title').textContent();
            await page.clock.install();
            await page.clock.pauseAt(new Date());
            await page.clock.fastForward(12000);
            assert.equal(await page.locator('#viewer-title').textContent(), stopped);
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
            await page.getByRole('button', { name: 'Slideshow', exact: true }).click();
            let seen = [];
            for (let index = 0; index < 62; index++) {
                await page.waitForFunction(
                    () =>
                        document.querySelector('#viewer-image').complete &&
                        document.querySelector('#viewer-image').naturalWidth > 0 &&
                        document.querySelector('#viewer-title').textContent.startsWith('photo-'),
                    null,
                    { polling: 100 }
                );
                let id = new URL(page.url()).searchParams.get('image');
                assert.ok(!seen.includes(id), 'no duplicate slideshow image');
                seen.push(id);
                assert.match(await page.locator('#viewer-title').textContent(), /^photo-/);
                await page.clock.fastForward(6001);
                if (index < 61)
                    await page.waitForFunction(
                        previous => new URL(location.href).searchParams.get('image') !== previous,
                        id,
                        { polling: 100 }
                    );
            }
            await page.waitForFunction(() => document.querySelector('#slideshow-stop').hidden, null, { polling: 100 });
            assert.equal(await page.locator('#photo-load-message').textContent(), 'Slideshow beendet.');
            assert.equal(seen.length, 62);
            assert.deepEqual(seen.slice(0, 60), firstIds);
            await page.waitForFunction(() => !document.querySelector('#viewer').open, null, { polling: 100 });
            await page.clock.resume();
        }
        if (!live) {
            let paged = new URL(page.url());
            paged.searchParams.set('page', '2');
            await page.goto(paged.href);
            await page.waitForSelector('#gallery-slideshow');
            assert.equal(await page.locator('[data-photo]').count(), 2);
            let beforeSlideshow = documents;
            await page.getByRole('button', { name: 'Slideshow', exact: true }).click();
            await page.waitForFunction(
                first => new URL(location.href).searchParams.get('image') === first,
                firstIds[0]
            );
            assert.equal(documents, beforeSlideshow);
            assert.equal(new URL(page.url()).searchParams.has('page'), false);
            await page.getByRole('button', { name: 'Slideshow stoppen' }).click();
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
        }
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            await page.setViewportSize({ width, height });
            await page.reload();
            await page.waitForSelector('#gallery-sort');
            assert.equal(await page.locator('#gallery-sort').inputValue(), 'random');
            assert.equal(await page.locator('#gallery-relevance').inputValue(), 'relevant');
            assert.equal(new URL(page.url()).searchParams.get('seed'), seed);
            assert.deepEqual(
                await page
                    .locator('[data-photo]')
                    .evaluateAll($cards => $cards.slice(0, 60).map($card => $card.dataset.photo)),
                firstIds
            );
            if (!live) {
                await page.waitForFunction(() => document.querySelector('.photo-card[data-preview-state="ready"]'));
                let states = await page.locator('.photo-card[data-preview-state="ready"]').evaluateAll($cards =>
                    $cards.map($card => ({
                        favorite: $card.dataset.priority === '1',
                        opacity: getComputedStyle($card.querySelector('img')).opacity
                    }))
                );
                for (let state of states) assert.equal(state.opacity, state.favorite ? '1' : '0.5');
            }
            assert.equal(await page.locator('#viewer').evaluate($viewer => $viewer.open), false);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (!live) {
                let $card = page.locator('.photo-card[data-priority="0"]').nth(30);
                await $card.scrollIntoViewIfNeeded();
                await page.waitForFunction(
                    id => document.querySelector(`[data-photo="${id}"]`).dataset.previewState === 'ready',
                    await $card.getAttribute('data-photo')
                );
                let id = await $card.getAttribute('data-photo');
                let before = documents;
                let acknowledged = ratingResponses;
                let startedRating = Date.now();
                await page.locator(`[data-priority-photo="${id}"][data-priority="1"]`).click();
                assert.equal(await page.locator(`[data-photo="${id}"]`).getAttribute('data-priority'), '1');
                assert.equal(ratingResponses, acknowledged, 'favorite feedback precedes the delayed server response');
                console.log(`${name}: favorite feedback ${Date.now() - startedRating} ms; server delay 1200 ms`);

                await page.waitForFunction(
                    id => document.querySelector(`[data-photo="${id}"]`).dataset.priority === '1',
                    id
                );
                assert.equal(
                    await page.locator(`[data-photo="${id}"] img`).evaluate($image => getComputedStyle($image).opacity),
                    '1'
                );
                assert.equal(await page.locator('#viewer').evaluate($viewer => $viewer.open), false);
                await page.waitForFunction(id => !document.querySelector(`[data-priority-photo="${id}"]`).disabled, id);
                await page.evaluate(() => {
                    window.ratingDom = {
                        $grid: document.querySelector('.photo-grid'),
                        $cards: [...document.querySelectorAll('[data-photo]')]
                    };
                });
                acknowledged = ratingResponses;
                startedRating = Date.now();
                await page.locator(`[data-priority-photo="${id}"][data-priority="-1"]`).click();
                assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
                assert.equal(
                    ratingResponses,
                    acknowledged,
                    'rejection hides the tile before the delayed server response'
                );
                console.log(`${name}: exclusion feedback ${Date.now() - startedRating} ms; server delay 1200 ms`);
                let scrollAfterHiding = await page.evaluate(
                    () => new Promise(resolve => requestAnimationFrame(() => resolve(scrollY)))
                );

                await page.waitForFunction(
                    id =>
                        document.querySelector(`[data-photo="${id}"]`).parentElement.hidden &&
                        !document.querySelector(`[data-priority-photo="${id}"]`).disabled,
                    id
                );
                assert.equal(
                    await page.evaluate(
                        () =>
                            window.ratingDom.$grid === document.querySelector('.photo-grid') &&
                            window.ratingDom.$cards.every($card => $card.isConnected)
                    ),
                    true,
                    'acknowledgement preserves the exact grid and all existing card nodes'
                );
                assert.equal(
                    await page.evaluate(() => scrollY),
                    scrollAfterHiding,
                    'saving causes no second scroll jump'
                );
                assert.equal(documents, before);
                await page.locator('#gallery-relevance').selectOption('excluded');
                await page.waitForURL(/relevance=excluded/);
                assert.equal(await page.locator('.photo-card:not([data-priority="-1"])').count(), 0);
                let $rejected = page.locator(`[data-priority-photo="${id}"][data-priority="-1"]`);
                assert.equal(await $rejected.getAttribute('aria-pressed'), 'true');
                await page.reload();
                await page.waitForSelector(`[data-priority-photo="${id}"]`);
                assert.equal(await $rejected.getAttribute('aria-pressed'), 'true');
                assert.equal(await page.locator('#gallery-relevance').inputValue(), 'excluded');
                await $rejected.click();
                assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
                await page.waitForFunction(
                    id =>
                        document.querySelector(`[data-photo="${id}"]`).parentElement.hidden &&
                        !document.querySelector(`[data-priority-photo="${id}"]`).disabled,
                    id
                );
                await page.locator('#gallery-relevance').selectOption('relevant');
                await page.waitForURL(/relevance=relevant/);
            }
            await page.locator('#gallery-relevance').selectOption('unrated');
            await page.waitForURL(/relevance=unrated/);
            await page.reload();
            await page.waitForSelector('#gallery-relevance');
            assert.equal(await page.locator('#gallery-relevance').inputValue(), 'unrated');
            assert.equal(await page.locator('.photo-card:not([data-priority="0"])').count(), 0);
            if (!live) {
                assert.equal(await page.locator('[data-photo]').count(), 52);
                let id = await page.locator('[data-photo]').first().getAttribute('data-photo');
                for (let priority of ['1', '-1']) {
                    await page.locator(`[data-priority-photo="${id}"][data-priority="${priority}"]`).click();
                    assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
                    await page.waitForFunction(
                        id =>
                            document.querySelector(`[data-photo="${id}"]`).parentElement.hidden &&
                            !document.querySelector(`[data-priority-photo="${id}"]`).disabled,
                        id
                    );
                    assert.equal(await page.locator('.photo-tile:not([hidden]) [data-photo]').count(), 51);
                    await page.locator('#gallery-relevance').selectOption('all');
                    await page.waitForURL(/relevance=all/);
                    await page.locator(`[data-priority-photo="${id}"][data-priority="${priority}"]`).click();
                    await page.waitForFunction(
                        id => !document.querySelector(`[data-priority-photo="${id}"]`).disabled,
                        id
                    );
                    await page.locator('#gallery-relevance').selectOption('unrated');
                    await page.waitForURL(/relevance=unrated/);
                    assert.equal(await page.locator('[data-photo]').count(), 52);
                }
            }
            await page.locator('#gallery-relevance').selectOption('relevant');
            await page.waitForURL(/relevance=relevant/);
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path:
                        process.env.PHOTOBUTLER_BROWSER_ARTIFACTS +
                        '/gallery-controls-' +
                        (live ? 'live-' : '') +
                        name +
                        '.png',
                    fullPage: true
                });
        }
        if (!live) {
            let $card = page.locator('.photo-card[data-priority="0"]').first();
            let id = await $card.getAttribute('data-photo');
            $card = page.locator(`[data-photo="${id}"]`);
            fs.writeFileSync(root + '/.data/rating-failure', '1');
            await page.locator(`[data-priority-photo="${id}"][data-priority="-1"]`).click();
            assert.equal(await $card.isVisible(), false);
            await page.waitForFunction(
                id => document.querySelector(`[data-photo="${id}"]`).dataset.priority === '0',
                id
            );
            assert.equal(await $card.isVisible(), true);
            assert.match(await page.locator('#photo-load-message').textContent(), /Speichern fehlgeschlagen/);
            fs.unlinkSync(root + '/.data/rating-failure');
            let ids = await page
                .locator('.photo-card[data-priority="0"]')
                .evaluateAll($cards => $cards.slice(0, 2).map($card => $card.dataset.photo));
            let acknowledged = ratingResponses;
            for (let id of ids) {
                await page.locator(`[data-priority-photo="${id}"][data-priority="-1"]`).click();
                assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
            }
            assert.equal(ratingResponses, acknowledged, 'two rapid exclusions react before either response');
            await page.waitForFunction(
                ids =>
                    ids.every(
                        id =>
                            document.querySelector(`[data-photo="${id}"]`).parentElement.hidden &&
                            !document.querySelector(`[data-priority-photo="${id}"]`).disabled
                    ),
                ids
            );
            await page.locator('#photo-loader').scrollIntoViewIfNeeded();
            await page.waitForFunction(() => document.querySelector('#photo-loader').dataset.next === '');
            let remaining = await page
                .locator('.photo-tile:not([hidden]) [data-photo]')
                .evaluateAll($cards => $cards.map($card => $card.dataset.photo));
            assert.equal(remaining.length, 60);
            assert.equal(new Set(remaining).size, 60);
            await page.locator('#gallery-relevance').selectOption('excluded');
            await page.waitForURL(/relevance=excluded/);
            for (let id of ids) {
                await page.locator(`[data-priority-photo="${id}"][data-priority="-1"]`).click();
                assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
            }
            await page.waitForFunction(
                ids =>
                    ids.every(
                        id =>
                            document.querySelector(`[data-photo="${id}"]`).parentElement.hidden &&
                            !document.querySelector(`[data-priority-photo="${id}"]`).disabled
                    ),
                ids
            );
            await page.locator('#gallery-relevance').selectOption('relevant');
            await page.waitForURL(/relevance=relevant/);
        }
        if (!live) {
            let id = await page.locator('.photo-card[data-priority="0"]').first().getAttribute('data-photo');
            let acknowledged = ratingResponses;
            await page.locator(`[data-priority-photo="${id}"][data-priority="1"]`).click();
            assert.equal(ratingResponses, acknowledged);
            await page.locator('#gallery-favorites').selectOption('1');
            await page.waitForURL(/favorites=1/);
            assert.equal(await page.locator(`[data-photo="${id}"]`).count(), 1);
            assert.equal(await page.locator('[data-photo]').count(), 11);
            await page.locator(`[data-priority-photo="${id}"][data-priority="1"]`).click();
            await page.locator('#gallery-favorites').selectOption('0');
            await page.waitForURL(/favorites=0/);
            assert.equal(await page.locator(`[data-photo="${id}"]`).getAttribute('data-priority'), '0');
        }
        if (!live) {
            await page.getByRole('button', { name: 'Slideshow', exact: true }).click();
            await page.waitForFunction(
                () =>
                    document.querySelector('#viewer-image').complete &&
                    document.querySelector('#viewer-image').naturalWidth > 0
            );
            assert.equal(await page.getByRole('button', { name: 'Slideshow stoppen' }).isVisible(), true);
            await page.waitForFunction(() => document.fullscreenElement !== null);
            assert.equal(await page.locator('.viewer-info').isVisible(), false);
            assert.equal(
                await page.evaluate(() => Boolean(document.elementFromPoint(20, 20)?.closest('#viewer'))),
                true
            );
            assert.equal(
                await page
                    .locator('#viewer')
                    .evaluate($viewer => Math.round($viewer.getBoundingClientRect().height) === innerHeight),
                true
            );
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/gallery-controls-mobile-slideshow.png',
                    fullPage: false
                });
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
        }
        await page.locator('#gallery-relevance').selectOption('all');
        await page.waitForURL(/relevance=all/);
        assert.equal(await page.locator('#gallery-sort').inputValue(), 'random');
        assert.equal(new URL(page.url()).searchParams.get('seed'), seed);
        if (!live) {
            await page.locator('#gallery-favorites').selectOption('1');
            await page.waitForURL(/favorites=1/);
            assert.equal(await page.locator('[data-photo]').count(), 10);
            await page.locator('#gallery-favorites').selectOption('none');
            await page.waitForURL(/favorites=none/);
            assert.equal(await page.locator('[data-photo]').count(), 56);
            await page.locator('#gallery-favorites').selectOption('0');
            await page.waitForURL(/favorites=0/);
            await page.goto(url + '?album=old&relevance=all&q=ignored');
            assert.equal(await page.locator('[data-photo]').count(), 1);
            await page.locator('#gallery-relevance').selectOption('relevant');
            await page.waitForURL(/relevance=relevant/);
            assert.equal(await page.locator('[data-photo]').count(), 0);
            assert.equal(await page.locator('#gallery-slideshow').isDisabled(), true);
        }
        let storyAlbum = '_WHATSAPP/.Statuses';
        let storyPage = await page.request.get(url + '?album=' + encodeURIComponent(storyAlbum) + '&relevance=all');
        let storyHtml = await storyPage.text();
        let storyId = live ? '92942' : /data-photo="(\d+)"/.exec(storyHtml)[1];
        assert.ok(storyHtml.includes('data-photo="' + storyId + '"'));
        let hiddenPage = await page.request.get(url + '?album=' + encodeURIComponent(storyAlbum));
        assert.ok(!(await hiddenPage.text()).includes('data-photo="' + storyId + '"'));
        let directPage = await page.request.get(url + '?sort=newest&image=' + storyId);
        assert.ok((await directPage.text()).includes('data-selected-photo="0"'));
        if (!live) {
            await page.goto(url + '?sort=newest&image=' + storyId);
            await page.waitForFunction(() => !new URL(location.href).searchParams.has('image'));
            assert.equal(await page.locator('#viewer').evaluate($viewer => $viewer.open), false);
            await page.goto(url + '?sort=newest&relevance=all&image=' + storyId);
            await page.waitForFunction(
                () =>
                    document.querySelector('#viewer').open &&
                    document.querySelector('#viewer-image').complete &&
                    document.querySelector('#viewer-image').naturalWidth > 0
            );
            await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
        }
        assert.deepEqual(errors, []);
        assert.deepEqual(writes, []);
        console.log(
            'PASS: ' +
                (live
                    ? 'live empty-result UI (no private image requests)'
                    : 'real filtered slideshow across 62 photos, six-second interval, stop/end, original loading') +
                ', relevance/random/columns, desktop/mobile/reload, no job actions. URL: ' +
                url
        );
    } finally {
        if (browser) await browser.close();
        if (server) {
            server.kill();
            await once(server, 'exit');
        }
        fs.rmSync(root, { recursive: true, force: true });
    }
})().catch(error => {
    console.error(error.stack);
    process.exitCode = 1;
});
