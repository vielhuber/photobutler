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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-resets-browser-'));
    let server, browser;
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
        fs.mkdirSync(root + '/sessions');
        fs.mkdirSync(root + '/public');
        fs.mkdirSync(root + '/photos');
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=reset-test\nAUTH_PASSWORD=isolated-reset-test\nJWT_SECRET=isolated-reset-test-signing-secret-123456\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; ob_start(); (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run(); if (($_POST['action'] ?? '') === 'job-step') { usleep(250000); } ob_end_flush();`
        );
        database(`$image = imagecreatetruecolor(80, 60); imagejpeg($image, ${JSON.stringify(root + '/photos/b.jpg')}); imagejpeg($image, ${JSON.stringify(root + '/photos/c.jpg')}); $library->index(); $library->favorite(1, true); $library->saveTags(1, 'Manuell');
            $library->database->exec("UPDATE photos SET ai_tags = '[\\\"KI\\\"]', description = 'KI', status = 'done';
                INSERT INTO persons (id, name) VALUES (1, 'Manuell'), (2, '');
                INSERT INTO faces (id, photo_id, person_id, modified, bytes, model, box, embedding, crop, origin) SELECT id, id, id, modified, bytes, 'fixture', '[]', '[]', 'crop', CASE WHEN id = 1 THEN 'manual' ELSE 'auto' END FROM photos;
                INSERT INTO face_state SELECT id, modified, bytes, 'fixture', 'done', 42 FROM photos;");`);
        let original = fs.readFileSync(root + '/photos/b.jpg');
        let socket = net.createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        let port = socket.address().port;
        await new Promise(resolve => socket.close(resolve));
        server = spawn(
            'php',
            ['-d', `session.save_path=${root}/sessions`, '-S', `127.0.0.1:${port}`, '-t', root + '/public'],
            {
                stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
            }
        );
        let url = `http://127.0.0.1:${port}/`;
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        let page = await context.newPage();
        let errors = [],
            actions = [],
            external = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (!request.url().startsWith(url)) external.push(request.url());
            let body = request.postData() || '';
            if (body.includes('job-')) actions.push(body);
        });
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('reset-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-reset-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('[data-job="scan"]');
        assert.equal(await page.locator('[data-job-action="reset"]').count(), 4);
        assert.equal(actions.length, 0);
        assert.equal(
            await (await context.request.get(url + '?asset=jobs.js')).text(),
            fs.readFileSync(project + '/assets/jobs.js', 'utf8')
        );
        let denied = await context.request.post(url, { form: { action: 'job-reset', job: 'scan', csrf: 'invalid' } });
        assert.equal(denied.status(), 403);
        for (let job of ['scan', 'previews', 'tag', 'faces']) {
            page.once('dialog', dialog => dialog.dismiss());
            await page.locator(`[data-job="${job}"] [data-job-action="reset"]`).click();
        }
        assert.equal(actions.length, 0);
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="previews"] [data-job-status]').textContent.includes('Abgeschlossen')
        );
        let thumbnails = JSON.parse(
            database(`echo json_encode(glob(${JSON.stringify(root + '/.data/thumbnails/*.jpg')}));`)
        ).filter(file => !file.includes('.detail.'));
        assert.equal(thumbnails.length, 2);
        for (let job of ['previews', 'tag', 'faces', 'scan']) {
            let others = database(
                `echo json_encode($library->database->query("SELECT * FROM jobs WHERE job <> '${job}'")->fetchAll());`
            );
            let count = actions.length;
            page.once('dialog', dialog => {
                assert.equal(dialog.type(), 'confirm');
                return dialog.accept();
            });
            let response = page.waitForResponse(response => response.request().postData()?.includes('job-reset'));
            await page.locator(`[data-job="${job}"] [data-job-action="reset"]`).click();
            assert.equal((await response).status(), 200);
            await page.waitForFunction(
                job =>
                    document
                        .querySelector(`[data-job="${job}"] [data-job-message]`)
                        .textContent.includes('Daten zurückgesetzt'),
                job
            );
            assert.equal(actions.length, count + 1);
            assert.equal(
                database(
                    `echo json_encode($library->database->query("SELECT * FROM jobs WHERE job <> '${job}'")->fetchAll());`
                ),
                others
            );
            if (job === 'previews') for (let thumbnail of thumbnails) assert.equal(fs.existsSync(thumbnail), false);
            if (job === 'tag')
                assert.equal(
                    Number(
                        database(
                            `echo $library->database->query("SELECT COUNT(*) FROM photos WHERE ai_tags = '[]' AND description = '' AND status = 'pending'")->fetchColumn();`
                        )
                    ),
                    2
                );
            if (job === 'faces') {
                assert.equal(
                    database(
                        'echo $library->database->query("SELECT GROUP_CONCAT(id) FROM faces")->fetchColumn();'
                    ).trim(),
                    '1'
                );
                assert.equal(
                    database('echo $library->database->query("SELECT name FROM persons")->fetchColumn();').trim(),
                    'Manuell'
                );
            }
            if (job === 'scan')
                assert.equal(
                    Number(database('echo $library->database->query("SELECT COUNT(*) FROM photos")->fetchColumn();')),
                    0
                );
            await page.reload();
            await page.waitForSelector('[data-job="scan"]');
            assert.equal(actions.length, count + 1, 'reload must not start processing');
            assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());'))[job].status, 'idle');
        }
        assert.deepEqual(fs.readFileSync(root + '/photos/b.jpg'), original);
        fs.copyFileSync(root + '/photos/b.jpg', root + '/photos/a.jpg');
        await page.locator('[data-job="scan"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="scan"] [data-job-status]').textContent.includes('Abgeschlossen')
        );
        assert.equal(database('echo $library->photo(1)->name;').trim(), 'b.jpg');
        assert.equal(database('echo (int) $library->photo(1)->favorite;').trim(), '1');
        assert.equal(
            database(
                'echo $library->database->query("SELECT manual_tags FROM photos WHERE id = 1")->fetchColumn();'
            ).trim(),
            '["Manuell"]'
        );
        assert.deepEqual(await (await context.request.get(url + '?photo=1&size=original&download=1')).body(), original);
        let request = page.waitForRequest(request => request.postData()?.includes('job-step'));
        await page.locator('[data-job="scan"] [data-job-action="start"]').click();
        await request;
        page.once('dialog', dialog => dialog.accept());
        await page.locator('[data-job="scan"] [data-job-action="reset"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="scan"] [data-job-message]').textContent.includes('Daten zurückgesetzt')
        );
        assert.equal(
            Number(database('echo $library->database->query("SELECT COUNT(*) FROM photos")->fetchColumn();')),
            0
        );
        assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());')).scan.status, 'idle');
        for (let job of ['scan', 'previews', 'tag', 'faces']) {
            await page.evaluate(job => {
                document.querySelector(`[data-job="${job}"] [data-job-action="start"]`).click();
                document.querySelector(`[data-job="${job}"] [data-job-action="pause"]`).click();
            }, job);
            await page.waitForFunction(
                job => !document.querySelector(`[data-job="${job}"] [data-job-action="start"]`).disabled,
                job
            );
            assert.equal(JSON.parse(database('echo json_encode($library->jobs->all());'))[job].status, 'paused');
        }
        if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/reset-desktop.png',
                fullPage: true
            });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('[data-job-action="reset"]:visible').count(), 4);
        page.once('dialog', dialog => dialog.accept());
        await page.locator('[data-job="tag"] [data-job-action="reset"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="tag"] [data-job-message]').textContent.includes('Daten zurückgesetzt')
        );
        await page.reload();
        await page.waitForSelector('[data-job="scan"]');
        if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/reset-mobile.png',
                fullPage: true
            });
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        console.log(
            'PASS: real app, four confirmed resets/cancellation, CSRF rejection, isolated generated thumbnails/AI/face fixtures, protected metadata, reload, manual reimport, stable IDs/original download, in-flight response, independent starts/pauses, desktop/mobile. URL: ' +
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
