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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-import-e2e-'));
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
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=jobs-test\nAUTH_PASSWORD=isolated-jobs-test\nJWT_SECRET=isolated-jobs-test-signing-secret-123456\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        database(`$image = imagecreatetruecolor(80, 60);
            for ($i = 0; $i < 4; $i++) { imagejpeg($image, ${JSON.stringify(root + '/photos/')} . $i . '.jpg'); }
            file_put_contents(${JSON.stringify(root + '/photos/video.mp4')}, 'unsupported');
            $library->index(limit: 2);
            $library->importProgress(refresh: true);
            $library->jobs->pause('scan');`);
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
                let $card = document.querySelector('[data-job="scan"]');
                if ($card && $card.getBoundingClientRect().width > 0) {
                    window.firstImport = {
                        percent: $card.querySelector('progress').value,
                        count: $card.querySelector('[data-job-count]').textContent.trim()
                    };
                    return;
                }
                requestAnimationFrame(capture);
            };
            requestAnimationFrame(capture);
        });
        let page = await context.newPage();
        let errors = [],
            actions = [],
            external = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (!request.url().startsWith(url)) external.push(request.url());
            if (request.postData()?.includes('job-')) actions.push(request.postData());
        });
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('jobs-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-jobs-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForFunction(() => window.firstImport);
        let checkpoint = database('echo $library->database->query("SELECT state FROM scan_state")->fetchColumn();');
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            await page.setViewportSize({ width, height });
            await page.reload();
            await page.waitForFunction(() => window.firstImport);
            let first = await page.evaluate(() => window.firstImport);
            assert.equal(first.percent, 50);
            assert.match(first.count, /^2 \/ 4/);
            assert.equal(actions.length, 0);
            assert.equal(
                database('echo $library->database->query("SELECT state FROM scan_state")->fetchColumn();'),
                checkpoint
            );
            assert.equal(Number(database('echo count($library->photos());')), 2);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/import-progress-' + name + '.png',
                    fullPage: true
                });
        }
        await page.locator('[data-job="scan"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () => document.querySelector('[data-job="scan"] [data-job-status]').textContent === '100 % · Abgeschlossen'
        );
        assert.equal(Number(database('echo count($library->photos());')), 4);
        await page.reload();
        await page.waitForFunction(() => window.firstImport);
        assert.equal((await page.evaluate(() => window.firstImport)).percent, 100);

        fs.copyFileSync(root + '/photos/0.jpg', root + '/photos/new.JPG');
        await page.evaluate(() => {
            document.querySelector('[data-job="scan"] [data-job-action="start"]').click();
            document.querySelector('[data-job="scan"] [data-job-action="pause"]').click();
        });
        await page.waitForFunction(
            () => !document.querySelector('[data-job="scan"] [data-job-action="start"]').disabled
        );
        assert.equal(await page.locator('[data-job="scan"] progress').evaluate($progress => $progress.value), 80);
        assert.match(await page.locator('[data-job="scan"] [data-job-count]').textContent(), /^4 \/ 5/);
        assert.equal(Number(database('echo count($library->photos());')), 4);
        let posts = actions.length;
        await page.reload();
        await page.waitForFunction(() => window.firstImport);
        assert.equal((await page.evaluate(() => window.firstImport)).percent, 80);
        assert.equal(actions.length, posts);
        await page.locator('[data-job="scan"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () => document.querySelector('[data-job="scan"] [data-job-status]').textContent === '100 % · Abgeschlossen'
        );
        assert.equal(Number(database('echo count($library->photos());')), 5);
        fs.unlinkSync(root + '/photos/0.jpg');
        await page.evaluate(() => {
            document.querySelector('[data-job="scan"] [data-job-action="start"]').click();
            document.querySelector('[data-job="scan"] [data-job-action="pause"]').click();
        });
        await page.waitForFunction(
            () => !document.querySelector('[data-job="scan"] [data-job-action="start"]').disabled
        );
        assert.match(await page.locator('[data-job="scan"] [data-job-count]').textContent(), /^4 \/ 4/);
        assert.equal(
            Number(database('echo $library->database->query("SELECT SUM(attempted) FROM photos")->fetchColumn();')),
            0
        );
        assert.equal(
            Number(database('echo $library->database->query("SELECT COUNT(*) FROM face_state")->fetchColumn();')),
            0
        );
        assert.deepEqual(JSON.parse(database('echo json_encode(array_keys($library->jobs->all()));')), [
            'scan',
            'previews',
            'tag',
            'faces'
        ]);
        for (let job of ['previews', 'tag', 'faces'])
            assert.match(await page.locator(`[data-job="${job}"] [data-job-status]`).textContent(), /Bereit/);
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        console.log(
            'PASS: persistent import counts from real files/SQLite (2/4, 4/4, 4/5, 5/5, deletion 4/4), desktop/mobile first paint and reload, unchanged checkpoint without manual start, explicit resume, MP4 excluded, no other job or external call. URL: ' +
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
