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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-gallery-relevance-'));
    let live = process.env.PHOTOBUTLER_GALLERY_LIVE === '1';
    let url = 'https://photobutler.rebuhleiv.xyz/';
    let credentials = { username: 'relevance-test', password: 'isolated-relevance-test' };
    let server, browser;
    let php = code =>
        execFileSync('php', ['-r', `require ${JSON.stringify(project + '/vendor/autoload.php')}; ${code}`], {
            encoding: 'utf8'
        });
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
                `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=${credentials.username}\nAUTH_PASSWORD=${credentials.password}\nJWT_SECRET=isolated-relevance-signing-secret\n`
            );
            fs.writeFileSync(
                root + '/public/index.php',
                `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; if (($_POST['action'] ?? '') === 'priority') { usleep(500000); if (is_file(dirname(__DIR__).'/.data/rating-failure')) { http_response_code(503); exit; } } (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
            );
            php(`$root=${JSON.stringify(root)};
                for($priority=-1;$priority<=1;$priority++) {
                    $image=imagecreatetruecolor(80,60);
                    imagefill($image,0,0,imagecolorallocate($image,80+60*$priority,140,190));
                    imagejpeg($image,$root.'/photos/priority-'.$priority.'.jpg');
                }
                $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index();
                $library->database->exec("UPDATE photos SET priority=CASE name WHEN 'priority--1.jpg' THEN -1 WHEN 'priority-0.jpg' THEN 0 ELSE 1 END");
            `);
            let socket = net.createServer();
            socket.listen(0, '127.0.0.1');
            await once(socket, 'listening');
            let port = socket.address().port;
            await new Promise(resolve => socket.close(resolve));
            url = `http://127.0.0.1:${port}/`;
            server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], { stdio: 'ignore' });
            await once(server, 'spawn');
        }
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            let page = await browser.newPage({ viewport: { width, height } });
            let errors = [],
                writes = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => {
                if (request.method() === 'POST') writes.push(request.postData());
            });
            await page.addInitScript(() => {
                function capture() {
                    let $grid = document.querySelector('.photo-grid');
                    if (!$grid || !$grid.getBoundingClientRect().width) return requestAnimationFrame(capture);
                    window.firstGallery = {
                        relevance: document.querySelector('#gallery-relevance').value,
                        columns: document.querySelector('#gallery-columns').value,
                        priorities: [...$grid.querySelectorAll('[data-photo]')].map($card =>
                            Number($card.dataset.priority)
                        )
                    };
                }
                requestAnimationFrame(capture);
            });
            let base = url + (live ? '?album=__photobutler_relevance_no_match__' : '?');
            await page.goto(base);
            await page.getByLabel('Benutzername').fill(credentials.username);
            await page.getByLabel('Passwort', { exact: true }).fill(credentials.password);
            await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
            await page.waitForSelector('#gallery-relevance');
            for (let asset of ['app.js', 'navigation.js', 'preferences.js']) {
                let response = await page.request.get(url + '?asset=' + asset);
                assert.equal(response.ok(), true);
                assert.equal(await response.text(), fs.readFileSync(project + '/assets/' + asset, 'utf8'));
            }
            await page.evaluate(() => localStorage.setItem('photobutler.galleryColumns', '7'));
            await page.goto(base);
            await page.waitForFunction(() => window.firstGallery);
            let first = await page.evaluate(() => window.firstGallery);
            assert.equal(first.relevance, 'relevant');
            assert.equal(first.columns, '7');
            assert.ok(first.priorities.every(priority => priority === 1));
            if (!live) assert.deepEqual(first.priorities, [1]);
            assert.deepEqual(await page.locator('#gallery-relevance option').allTextContents(), [
                'Alle anzeigen',
                'Eingeblendete Fotos',
                'Nicht bewertete Fotos',
                'Ausgeblendete Fotos'
            ]);
            for (let [relevance, priority] of [
                ['all', null],
                ['relevant', 1],
                ['unrated', 0],
                ['excluded', -1]
            ]) {
                await page.locator('#gallery-relevance').selectOption(relevance);
                await page.waitForURL(new RegExp('relevance=' + relevance));
                await page.reload();
                await page.waitForFunction(() => window.firstGallery);
                let state = await page.evaluate(() => window.firstGallery);
                assert.equal(state.relevance, relevance);
                assert.equal(state.columns, '7');
                assert.ok(state.priorities.every(value => priority === null || value === priority));
                if (!live) assert.equal(state.priorities.length, priority === null ? 3 : 1);
                let response = await page.request.get(url + '?relevance=' + relevance);
                assert.equal(response.ok(), true);
                let html = await response.text();
                let priorities = [...html.matchAll(/class="photo-card" data-priority="(-?\d+)"/g)].map(match =>
                    Number(match[1])
                );
                assert.ok(priorities.every(value => priority === null || value === priority));
                if (!live) assert.equal(priorities.length, priority === null ? 3 : 1);
            }
            await page.locator('#gallery-relevance').selectOption('relevant');
            await page.waitForURL(/relevance=relevant/);
            await page.goBack();
            await page.waitForFunction(() => document.querySelector('#gallery-relevance').value === 'excluded');
            await page.goForward();
            await page.waitForFunction(() => document.querySelector('#gallery-relevance').value === 'relevant');
            assert.equal(await page.locator('.photo-card:not([data-priority="1"])').count(), 0);
            if (!live) {
                let ids = JSON.parse(
                    php(
                        `$library=new \\vielhuber\\photobutler\\PhotoButler(${JSON.stringify(root)}); echo json_encode($library->database->query('SELECT id, priority FROM photos')->fetchAll());`
                    )
                );
                for (let photo of ids) {
                    await page.goto(url + '?image=' + photo.id);
                    await page.waitForSelector('#gallery-relevance');
                    assert.equal(
                        await page.locator('#viewer').getAttribute('data-selected-photo'),
                        photo.priority === 1 ? String(photo.id) : '0'
                    );
                    if (photo.priority === 1) {
                        await page.waitForFunction(
                            () =>
                                document.querySelector('#viewer').open &&
                                document.querySelector('#viewer-image').naturalWidth > 0
                        );
                        await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
                    } else {
                        assert.equal(await page.locator('#viewer').evaluate($viewer => $viewer.open), false);
                    }
                }
                await page.goto(url + '?relevance=relevant');
                let id = await page.locator('.photo-card').getAttribute('data-photo');
                for (let failure of [true, false]) {
                    if (failure) fs.writeFileSync(root + '/.data/rating-failure', '1');
                    await page.locator(`[data-priority-photo="${id}"][data-priority="1"]`).click();
                    assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
                    await page.waitForFunction(
                        id => !document.querySelector(`[data-priority-photo="${id}"]`).disabled,
                        id
                    );
                    assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), failure);
                    if (failure) fs.unlinkSync(root + '/.data/rating-failure');
                }
                await page.reload();
                assert.equal(await page.locator('.photo-card').count(), 0);
                await page.locator('#gallery-relevance').selectOption('unrated');
                await page.waitForURL(/relevance=unrated/);
                assert.equal(await page.locator('.photo-card').count(), 2);
                await page.locator(`[data-priority-photo="${id}"][data-priority="1"]`).click();
                await page.waitForFunction(id => !document.querySelector(`[data-priority-photo="${id}"]`).disabled, id);
                assert.equal(await page.locator(`[data-photo="${id}"]`).isVisible(), false);
                await page.locator('#gallery-relevance').selectOption('relevant');
                await page.waitForURL(/relevance=relevant/);
                await page.waitForFunction(
                    () => document.querySelector('.photo-card').dataset.previewState === 'ready'
                );
                if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                    await page.screenshot({
                        path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/gallery-relevance-' + name + '.png',
                        fullPage: true
                    });
            }
            assert.deepEqual(errors, []);
            assert.equal(
                writes.some(body => /job-|reset/.test(body)),
                false
            );
            if (live) assert.equal(writes.length, 2);
            console.log(
                `${name}: initial state, saved columns, four filters, reload, history, server response${live ? ', read-only live' : ', direct links, real images, rating rollback/persistence'} passed: ${url}`
            );
            await page.context().close();
        }
    } finally {
        await browser?.close();
        if (server) {
            server.kill();
            await once(server, 'exit');
        }
        fs.rmSync(root, { recursive: true, force: true });
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
