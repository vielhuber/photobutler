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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-jobs-e2e-'));
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
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=jobs-test\nAUTH_PASSWORD=isolated-jobs-test\nJWT_SECRET=isolated-jobs-test-signing-secret-123456\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        database(
            `$image = imagecreatetruecolor(80, 60); for ($i = 0; $i < 12; $i++) { imagefill($image, 0, 0, imagecolorallocate($image, $i * 10, 0, 0)); imagejpeg($image, ${JSON.stringify(root + '/photos/')} . $i . '.jpg'); }`
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
        let page = await context.newPage();
        let errors = [],
            external = [],
            actions = [];
        let documents = 0;
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (!request.url().startsWith(url)) external.push(request.url());
            if (request.isNavigationRequest()) documents++;
            let body = request.postData() || '';
            if (body.includes('job-')) actions.push(body);
        });
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('jobs-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-jobs-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('[data-job="scan"]');
        assert.equal(actions.length, 0);
        assert.equal(await page.locator('[data-job]').count(), 4);
        let count = documents;
        for (let job of ['tag', 'faces', 'scan', 'previews']) {
            await page.evaluate(job => {
                document.querySelector(`[data-job="${job}"] [data-job-action="start"]`).click();
                document.querySelector(`[data-job="${job}"] [data-job-action="pause"]`).click();
            }, job);
            await page.waitForFunction(
                job => !document.querySelector(`[data-job="${job}"] [data-job-action="start"]`).disabled,
                job
            );
            assert.match(await page.locator(`[data-job="${job}"] [data-job-status]`).textContent(), /Pausiert/);
        }
        assert.equal(Number(database('echo count($library->photos());')), 0);
        await page.locator('[data-job="scan"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () => document.querySelector('[data-job="scan"] [data-job-status]').textContent === '100 % · Abgeschlossen'
        );
        assert.equal(Number(database('echo count($library->photos());')), 12);
        assert.equal(
            Number(database('echo $library->database->query("SELECT COUNT(*) FROM face_state")->fetchColumn();')),
            0
        );
        assert.equal(
            Number(database('echo $library->database->query("SELECT SUM(attempted) FROM photos")->fetchColumn();')),
            0
        );

        let original = fs.readFileSync(root + '/photos/0.jpg');
        let originalStat = fs.statSync(root + '/photos/0.jpg');
        fs.writeFileSync(root + '/photos/0.jpg', '');
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document
                .querySelector('[data-job="previews"] [data-job-status]')
                .textContent.includes('Mit Fehlern beendet')
        );
        let actionsBeforeReload = actions.length;
        for (let width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.reload();
            await page.waitForFunction(() =>
                document
                    .querySelector('[data-job="previews"] [data-job-message]')
                    ?.textContent.includes('Fehler prüfen')
            );
            assert.equal(
                await page.locator('[data-job="previews"] [data-job-message]').textContent(),
                'Fehler prüfen und manuell erneut starten.'
            );
            assert.equal(await page.locator('[data-job="previews"] [data-job-action="start"]').isEnabled(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.equal(actions.length, actionsBeforeReload);
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + `/thumbnail-error-${width}.png`,
                    fullPage: true
                });
        }
        fs.writeFileSync(root + '/photos/0.jpg', original);
        fs.utimesSync(root + '/photos/0.jpg', originalStat.atime, originalStat.mtime);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="previews"] [data-job-status]').textContent.includes('Abgeschlossen')
        );
        assert.equal(await page.locator('[data-job="previews"] [data-job-message]').textContent(), '');
        for (let job of ['tag', 'faces'])
            assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());'))[job].status, 'paused');

        await page.locator('[data-job="faces"] [data-job-action="start"]').click();
        await page.waitForResponse(response => response.request().postData()?.includes('job-step'));
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.getByRole('link', { name: /Alle Fotos/ }).click();
        await page.locator('#gallery-relevance').selectOption('all');
        await page.waitForSelector('.photo-card');
        assert.equal(await page.locator('[data-job]').count(), 0);
        assert.equal(await page.locator('.sidebar .worker').count(), 0);
        await page.locator('#gallery-columns').selectOption('9');
        await page.locator('#gallery-sort').selectOption('oldest');
        await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'oldest');
        await page.getByRole('link', { name: 'Jobs', exact: true }).click();
        await page.waitForSelector('[data-job="faces"]');
        if (await page.locator('[data-job="faces"] [data-job-action="pause"]').isEnabled()) {
            await page.locator('[data-job="faces"] [data-job-action="pause"]').click();
            await page.waitForFunction(
                () => !document.querySelector('[data-job="faces"] [data-job-action="start"]').disabled
            );
        }
        let progress = JSON.parse(database('echo json_encode($library->jobs->all());'));
        assert.equal(progress.tag.status, 'paused');
        assert.ok(['running', 'done'].includes(progress.previews.status));
        let faceCount = progress.faces.completed;
        assert.ok(faceCount > 0);
        await page.reload();
        await page.waitForSelector('[data-job="faces"]');
        assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());')).faces.completed, faceCount);
        await page.locator('[data-job="faces"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () => document.querySelector('[data-job="faces"] [data-job-status]').textContent === '100 % · Abgeschlossen'
        );

        await page.waitForFunction(
            () => !document.querySelector('[data-job="previews"] [data-job-action="start"]').disabled
        );
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-job="previews"] [data-job-status]').textContent ===
                '100 % · Abgeschlossen'
        );
        assert.equal(Number(database(`echo count(glob(${JSON.stringify(root + '/.data/thumbnails/*.jpg')}));`)), 12);
        assert.equal(
            fs.readdirSync(root + '/.data/thumbnails').some(name => name.includes('.detail')),
            false
        );
        let hash = fs.readFileSync(root + '/photos/0.jpg');
        let id = database(
            `echo $library->database->query("SELECT id FROM photos WHERE name = '0.jpg'")->fetchColumn();`
        ).trim();
        assert.deepEqual(await (await context.request.get(url + `?photo=${id}&size=original&download=1`)).body(), hash);

        await page.locator('[data-job="tag"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="tag"] [data-job-status]').textContent.includes('Mit Fehlern beendet')
        );
        assert.match(await page.locator('[data-job="tag"] [data-job-status]').textContent(), /0 %/);
        assert.match(await page.locator('[data-job="tag"] [data-job-message]').textContent(), /nach einer Stunde/);
        assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());')).faces.completed, 12);
        await page.locator('[data-job="tag"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="tag"] [data-job-status]').textContent.includes('Mit Fehlern beendet')
        );
        assert.equal(
            Number(
                database(
                    'echo $library->database->query("SELECT COUNT(*) FROM photos WHERE status = \'error\'")->fetchColumn();'
                )
            ),
            12
        );
        assert.equal(documents, count + 3, 'only the explicit reloads may reload the document');

        if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/jobs-desktop.png',
                fullPage: true
            });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('[data-job-action="start"]:visible').count(), 4);
        if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/jobs-mobile.png',
                fullPage: true
            });
        await page.getByRole('link', { name: /Alle Fotos/ }).click();
        await page.locator('#gallery-relevance').selectOption('all');
        await page.waitForSelector('.photo-card');
        assert.equal(await page.locator('#gallery-columns').inputValue(), '9');
        assert.equal(
            await page
                .locator('.photo-grid')
                .evaluate($grid => getComputedStyle($grid).gridTemplateColumns.split(' ').length),
            2
        );
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        console.log(
            'PASS: four manual independent starts/pauses, scan without chained processing, real CPU face steps, navigation/sort/columns while running, persisted pause/reload/resume, cached previews, original bytes, explicit AI configuration errors without external requests, percentages, desktop/mobile. URL: ' +
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
