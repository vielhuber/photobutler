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
    let fixtures = process.env.PHOTOBUTLER_FACE_FIXTURES;
    assert.ok(fixtures, 'Provide a directory with approved sample-a.jpg and sample-b.jpg images.');
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-face-e2e-'));
    let server;
    let browser;
    let database = code =>
        execFileSync(
            'php',
            [
                '-r',
                `require ${JSON.stringify(project + '/vendor/autoload.php')}; $library = new \\vielhuber\\photobutler\\PhotoButler(${JSON.stringify(root)}); ${code}`
            ],
            { encoding: 'utf8' }
        );
    try {
        fs.mkdirSync(root + '/.data');
        fs.mkdirSync(root + '/public');
        fs.mkdirSync(root + '/photos');
        fs.symlinkSync(
            process.env.PHOTOBUTLER_TEST_FACE_RUNTIME || project + '/.data/face-runtime',
            root + '/.data/face-runtime'
        );
        fs.copyFileSync(fixtures + '/sample-a.jpg', root + '/photos/a.jpg');
        fs.copyFileSync(fixtures + '/sample-a.jpg', root + '/photos/a-copy.jpg');
        fs.copyFileSync(fixtures + '/sample-b.jpg', root + '/photos/b.jpg');
        fs.copyFileSync(project + '/tests/fixtures/animated-sticker.webp', root + '/photos/sticker.webp');
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=face-test\nAUTH_PASSWORD=isolated-face-test\nJWT_SECRET=isolated-local-face-test-signing-key-123456\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        database(
            `$library->index(); $library->database->exec("UPDATE photos SET status='done', ai_tags='[\\\"Test\\\"]', priority=1");`
        );
        let socket = net.createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        let port = socket.address().port;
        await new Promise(resolve => socket.close(resolve));
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
            stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
        });
        let url = `http://127.0.0.1:${port}/`;
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.addInitScript(() => {
            let capture = () => {
                let $grid = document.querySelector('.photo-grid');
                if ($grid && $grid.getBoundingClientRect().width > 0) {
                    window.firstGallery = {
                        columns: getComputedStyle(document.documentElement)
                            .getPropertyValue('--gallery-columns')
                            .trim(),
                        selected: document.querySelector('#gallery-columns').value,
                        sidebar: getComputedStyle(document.documentElement).getPropertyValue('--sidebar-width').trim()
                    };
                    return;
                }
                requestAnimationFrame(capture);
            };
            requestAnimationFrame(capture);
        });
        let page = await context.newPage();
        let errors = [];
        let external = [];
        let documents = 0;
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (!request.url().startsWith(url)) external.push(request.url());
            if (request.isNavigationRequest()) documents++;
        });
        await page.goto(url + '?relevance=all');
        assert.equal((await context.request.get(url + '?face=1')).status(), 401);
        await page.evaluate(() => {
            localStorage.setItem('photobutler.galleryColumns', '7');
            localStorage.setItem('photobutler.sidebarWidth', '310');
        });
        await page.reload();
        await page.getByLabel('Benutzername').fill('face-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-face-test');
        await page.getByRole('button', { name: 'Anmelden' }).click();
        await page.waitForSelector('.photo-grid');
        await page.waitForFunction(() => window.firstGallery);
        assert.deepEqual(await page.evaluate(() => window.firstGallery), {
            columns: '7',
            selected: '7',
            sidebar: '310px'
        });
        async function openJobs() {
            await page.getByRole('link', { name: 'Jobs', exact: true }).click();
            await page.waitForSelector('[data-job="faces"]');
        }
        async function runFaces() {
            await openJobs();
            await page.locator('[data-job="faces"] [data-job-action="start"]').click();
            await page.waitForFunction(() =>
                document.querySelector('[data-job="faces"] [data-job-status]').textContent.includes('Abgeschlossen')
            );
        }
        assert.equal(
            Number(database('echo $library->database->query("SELECT COUNT(*) FROM face_state")->fetchColumn();')),
            0
        );
        await openJobs();
        await page.evaluate(() => {
            document.querySelector('[data-job="faces"] [data-job-action="start"]').click();
            document.querySelector('[data-job="faces"] [data-job-action="pause"]').click();
        });
        await page.waitForFunction(
            () => !document.querySelector('[data-job="faces"] [data-job-action="start"]').disabled
        );
        assert.match(await page.locator('[data-job="faces"] [data-job-status]').textContent(), /Pausiert/);
        await runFaces();
        await page.getByRole('link', { name: /Personen/ }).click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid > a').length === 2);
        assert.equal(
            Number(
                database(
                    `echo $library->database->query("SELECT COUNT(*) FROM face_state WHERE status='done'")->fetchColumn();`
                )
            ),
            4
        );
        assert.equal(
            Number(
                database(
                    `echo $library->database->query("SELECT COUNT(*) FROM photos WHERE status='done' AND attempted=0")->fetchColumn();`
                )
            ),
            4
        );
        database(`$library->database->exec("INSERT INTO persons DEFAULT VALUES");
            $fragment = (int) $library->database->lastInsertId();
            $library->database->exec("UPDATE faces SET person_id = $fragment WHERE id =
                (SELECT MAX(id) FROM faces WHERE person_id = (SELECT person_id FROM faces GROUP BY person_id HAVING COUNT(*) = 2))");`);
        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid > a').length === 3);
        let regroup = execFileSync('php', [project + '/bin/photobutler-index', '--root=' + root, '--regroup-faces'], {
            encoding: 'utf8'
        });
        assert.match(regroup, /1 doppelte Personengruppen/);
        assert.match(
            execFileSync('php', [project + '/bin/photobutler-index', '--root=' + root, '--regroup-faces'], {
                encoding: 'utf8'
            }),
            /0 doppelte Personengruppen/
        );
        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid > a').length === 2);
        let navigationCount = documents;
        let people = JSON.parse(database('echo json_encode($library->faces->persons());'));
        let group = people.find(person => person.total === 2);
        assert.ok(group, 'identical input photos must share a person');
        await page.locator(`a[href*="view=persons&person=${group.id}&"]`).click();
        await page.waitForSelector('.person-form input[name="name"]');
        await page.locator('input[name="name"]').fill('Testperson');
        await page.getByRole('button', { name: 'Namen speichern' }).click();
        await page.waitForFunction(() => document.querySelector('h1').textContent === 'Testperson');
        await page.getByRole('button', { name: 'Als neue Person trennen' }).first().click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid article').length === 1);
        people = JSON.parse(database('echo json_encode($library->faces->persons());'));
        let splitId = Number(database(`echo $library->database->query('SELECT MAX(id) FROM persons')->fetchColumn();`));
        await page.getByRole('link', { name: 'Alle Personen', exact: true }).click();
        await page.locator(`a[href*="view=persons&person=${splitId}&"]`).click();
        await page.locator('input[name="name"]').fill('Testperson');
        await page.getByRole('button', { name: 'Namen speichern' }).click();
        await page.waitForFunction(() => document.querySelector('h1').textContent === 'Testperson');
        await page.locator('form[data-confirm] select').selectOption(String(group.id));
        assert.match(await page.locator('form[data-confirm] select option:checked').textContent(), /Gleicher Name/);
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('button', { name: 'Zusammenführen …', exact: true }).click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid article').length === 2);
        await page.screenshot({ path: root + '/persons-desktop.png', fullPage: true });
        await page.getByRole('link', { name: 'Fotos dieser Person' }).click();
        await page.waitForFunction(() => document.querySelectorAll('.photo-grid [data-photo]').length === 2);
        assert.equal(await page.locator('#gallery-columns').inputValue(), '7');
        assert.equal(
            await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--sidebar-width').trim()
            ),
            '310px'
        );
        await page.locator('#gallery-sort').selectOption('oldest');
        await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'oldest');
        assert.equal(await page.locator('.search').count(), 0);
        await page.waitForFunction(() => document.querySelectorAll('.photo-grid [data-photo]').length === 2);
        assert.equal(new URL(page.url()).searchParams.get('person'), String(group.id));
        let photoId = await page.locator('.photo-grid [data-photo]').first().getAttribute('data-photo');
        await page.locator('.photo-grid [data-photo]').first().click();
        await page.waitForFunction(() => document.querySelector('#viewer-persons a')?.textContent === 'Testperson');
        await page.waitForFunction(() => {
            let image = document.querySelector('#viewer-persons img');
            return image?.complete && image.naturalWidth === 112;
        });
        let original = await context.request.get(url + `?photo=${photoId}&size=original&download=1`);
        assert.deepEqual(await original.body(), fs.readFileSync(fixtures + '/sample-a.jpg'));
        assert.equal(
            (
                await context.request.post(url, {
                    form: { action: 'face-rename', id: group.id, name: 'Forbidden', csrf: 'wrong' }
                })
            ).status(),
            403
        );
        assert.equal((await context.request.get(url + '?face=1')).status(), 200);
        assert.equal((await context.request.get(url + '?face=999999')).status(), 404);
        await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
        await page.waitForFunction(() => !document.querySelector('#viewer').open);
        await page.goForward();
        await page.waitForFunction(() => document.querySelector('#viewer').open);
        await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
        await page.waitForFunction(() => !document.querySelector('#viewer').open);
        assert.equal(documents, navigationCount, 'gallery and person actions must not reload the document');
        await page.reload();
        await page.waitForSelector('.photo-grid');
        assert.equal(await page.locator('#gallery-columns').inputValue(), '7');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(
            await page
                .locator('.photo-grid')
                .evaluate(grid => getComputedStyle(grid).gridTemplateColumns.split(' ').length),
            2
        );
        await page.getByRole('link', { name: /Personen/ }).click();
        await page.waitForSelector('.people-section');
        await page.locator(`a[href*="view=persons&person=${group.id}&"]`).click();
        await page.waitForSelector('.person-grid article');
        await page.getByRole('button', { name: 'Falschen Treffer ignorieren' }).first().click();
        await page.waitForFunction(() =>
            document.querySelector('.person-grid').textContent.includes('Ignorierter Treffer')
        );
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: root + '/persons-mobile.png', fullPage: true });
        let $move = page.locator('form:has(input[value="face-move"])').first();
        let targetId = await $move.locator('select option').nth(1).getAttribute('value');
        await $move.locator('select').selectOption(targetId);
        await $move.getByRole('button', { name: 'Umordnen', exact: true }).click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid article').length === 1);
        await page.getByRole('button', { name: 'Foto öffnen', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#viewer-persons a'));
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('button', { name: 'Gesichtsdaten löschen …', exact: true }).click();
        await page.waitForFunction(() =>
            document.querySelector('#viewer-message').textContent.includes('Gesichtsdaten gelöscht')
        );
        assert.equal(await page.locator('#viewer-persons a').count(), 0);
        await page.getByRole('button', { name: 'Gesichter erneut prüfen', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#viewer-message').textContent.includes('vorgemerkt'));
        await page.getByRole('button', { name: 'Bildansicht schließen' }).click();
        await page.waitForFunction(() => !document.querySelector('#viewer').open);
        await runFaces();
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.getByRole('link', { name: /^Personen/ }).click();
        await page.waitForSelector('.people-section');
        await openJobs();
        let resetButton = page.getByRole('button', { name: 'KI-Tags und Gesichter zurücksetzen …', exact: true });
        assert.equal(await resetButton.count(), 0);
        assert.equal(
            (await context.request.post(url, { form: { action: 'analysis-reset', csrf: 'wrong' } })).status(),
            403
        );
        let anonymous = await browser.newContext({ javaScriptEnabled: false });
        let loginHtml = await (await anonymous.request.get(url)).text();
        let anonymousCsrf = loginHtml.match(/name="csrf-token" content="([^"]+)"/)[1];
        assert.equal(
            (await anonymous.request.post(url, { form: { action: 'analysis-reset', csrf: anonymousCsrf } })).status(),
            401
        );
        await anonymous.close();
        let originalGroups = Number(database('echo count($library->faces->persons());'));
        await page.screenshot({ path: root + '/jobs-desktop.png', fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await resetButton.count(), 0);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: root + '/jobs-mobile.png', fullPage: true });
        await page.reload();
        await page.waitForSelector('[data-job="tag"]');
        assert.equal(await resetButton.count(), 0);
        assert.equal(await page.locator('[data-job="tag"] [data-job-action="start"]').isEnabled(), true);
        assert.equal(await page.locator('[data-job="faces"] [data-job-action="pause"]').isEnabled(), false);
        assert.equal(Number(database('echo count($library->faces->persons());')), originalGroups);
        database(`$library->resetAnalysis();
            $first = imagecreatefromjpeg(${JSON.stringify(root + '/photos/a.jpg')});
            $other = imagecreatefromjpeg(${JSON.stringify(root + '/photos/b.jpg')});
            $width = imagesx($first); $height = imagesy($first);
            $otherWidth = (int) round(imagesx($other) * $height / imagesy($other));
            $collage = imagecreatetruecolor($width * 2 + $otherWidth, $height);
            imagecopy($collage, $first, 0, 0, 0, 0, $width, $height);
            imagecopy($collage, $first, $width, 0, 0, 0, $width, $height);
            imagecopyresampled($collage, $other, $width * 2, 0, 0, 0, $otherWidth, $height, imagesx($other), imagesy($other));
            imagejpeg($collage, ${JSON.stringify(root + '/photos/album-page.jpg')}, 95);
            $library->index(); $library->database->exec("UPDATE photos SET status='done'");`);
        await runFaces();
        await page.getByRole('link', { name: /Personen/ }).click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid > a').length === 2);
        let albumPage = JSON.parse(
            database(
                `echo json_encode($library->database->query("SELECT COUNT(*) AS faces, COUNT(DISTINCT person_id) AS persons FROM faces WHERE photo_id = (SELECT id FROM photos WHERE name = 'album-page.jpg')")->fetch());`
            )
        );
        assert.deepEqual(albumPage, { faces: 3, persons: 2 });
        let repeated = JSON.parse(database('echo json_encode($library->faces->persons());')).find(
            person => person.total === 3
        );
        assert.ok(repeated, 'two repeated portraits must count as one album-page photo');
        await page.locator(`a[href*="view=persons&person=${repeated.id}&"]`).click();
        await page.waitForFunction(() => document.querySelectorAll('.person-grid article').length === 4);
        await page.screenshot({ path: root + '/album-page-mobile.png', fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: root + '/album-page-desktop.png', fullPage: true });
        await page.getByRole('link', { name: 'Fotos dieser Person' }).click();
        await page.waitForFunction(() => document.querySelectorAll('.photo-grid [data-photo]').length === 3);
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS) {
            fs.mkdirSync(process.env.PHOTOBUTLER_BROWSER_ARTIFACTS, { recursive: true });
            for (let name of [
                'persons-desktop.png',
                'persons-mobile.png',
                'jobs-desktop.png',
                'jobs-mobile.png',
                'album-page-desktop.png',
                'album-page-mobile.png'
            ])
                fs.copyFileSync(root + '/' + name, process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/' + name);
        }
        console.log(
            'PASS: scanned album-page repeated portraits grouped automatically with real CPU inference, distinct person filtering, combined reset absent on desktop/mobile/reload, retained reset endpoint CSRF/auth, real local CPU inference, existing-fragment CLI regroup and idempotence, backfill without AI calls, stop/resume during person navigation, rename/split/explicit same-name merge/ignore, filters, detail crops, original download, CSRF/auth, history, saved layout, desktop/mobile. URL: ' +
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
    console.error(error);
    process.exitCode = 1;
});
