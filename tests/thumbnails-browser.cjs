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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-originals-'));
    let server, browser;
    let runPreviews = () =>
        execFileSync('php', [project + '/bin/photobutler-index', '--root=' + root, '--previews-only'], {
            encoding: 'utf8'
        });
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
        for (let directory of ['.data', 'public', 'photos']) fs.mkdirSync(root + '/' + directory);
        fs.writeFileSync(
            root + '/.data/.env',
            `AUTH_USERNAME=original-test\nAUTH_PASSWORD=isolated-original-test\nJWT_SECRET=isolated-original-test-signing-secret\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        php(`$image=imagecreatetruecolor(2400,1600); imagefilledrectangle($image,0,0,2399,1599,0x338877); imagejpeg($image,$root.'/photos/photo.jpg',95);
            imagepng(imagecreatetruecolor(40,30),$root.'/photos/graphic.png');
            imagewebp(imagecreatetruecolor(40,30),$root.'/photos/without-preview.webp');`);
        // the fixture client downloads previews in-process, so the CLI job below only checks the cache offline
        let ids = JSON.parse(
            execFileSync(
                'php',
                [
                    project + '/tests/fixtures/seed-cloud.php',
                    root,
                    JSON.stringify({
                        directory: root + '/photos',
                        thumbnails: true,
                        unavailable_previews: ['without-preview.webp']
                    })
                ],
                { encoding: 'utf8' }
            )
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
        let page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        let errors = [],
            actions = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (request.postData()?.includes('job-')) actions.push(request.postData());
        });
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('original-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-original-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('[data-job="previews"]');
        assert.deepEqual(await page.locator('[data-job]').evaluateAll(cards => cards.map(card => card.dataset.job)), [
            'scan',
            'previews',
            'tag',
            'faces'
        ]);
        assert.equal(await page.locator('[data-job="previews"] h2').textContent(), 'Thumbnails downloaden');
        assert.equal(actions.length, 0);
        assert.equal(await page.locator('[data-job-action="start"], [data-job-action="pause"]').count(), 0);
        runPreviews();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-job="previews"] [data-job-status]').textContent ===
                '100 % · Abgeschlossen'
        );
        assert.match(await page.locator('[data-job="previews"] [data-job-count]').textContent(), /^3 \/ 3 · 0 Fehler$/);
        php(
            `$library=new \\vielhuber\\photobutler\\PhotoButler($root); imagepng(imagecreatetruecolor(533,800),$library->imagePath(${ids['photo.jpg']},cachedOnly:true));`
        );
        let cache = Object.fromEntries(
            fs
                .readdirSync(root + '/.data/thumbnails')
                .map(name => [name, fs.readFileSync(root + '/.data/thumbnails/' + name)])
        );
        assert.equal(Object.keys(cache).length, 2);
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            await page.setViewportSize({ width, height });
            for (let id of [ids['photo.jpg'], ids['graphic.png']]) {
                await page.goto(url + '?relevance=all&image=' + id);
                // originals stream from OneDrive; offline the popup falls back to the cached thumbnail
                await page.waitForFunction(
                    () =>
                        document.querySelector('#viewer-image').naturalWidth > 0 &&
                        document.querySelector('#viewer').open
                );
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                    await page.screenshot({
                        path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/thumbnail-' + name + '-' + id + '.png',
                        fullPage: true
                    });
                let preview = await page.request.get(url + `?photo=${id}`);
                assert.equal(preview.status(), 200);
                assert.equal(preview.headers()['content-type'], 'image/png');
                assert.equal(
                    (
                        await page.request.get(url + `?photo=${id}`, {
                            headers: { 'If-None-Match': preview.headers().etag }
                        })
                    ).status(),
                    304
                );
                if (id === ids['photo.jpg']) {
                    let $preview = page.locator(`img[src="?photo=${id}&size=display"]`).first();
                    await $preview.evaluate(image => image.decode());
                    assert.equal(await $preview.evaluate(image => image.naturalWidth), 533);
                }
                await page.reload();
                await page.waitForFunction(() => document.querySelector('#viewer-image').naturalWidth > 0);
            }
        }
        let before = actions.length;
        await page.goto(url + '?view=jobs');
        await page.waitForSelector('[data-job="previews"]');
        runPreviews();
        assert.equal(actions.length, before);
        await page.waitForFunction(
            () =>
                document.querySelector('[data-job="previews"] [data-job-status]').textContent ===
                '100 % · Abgeschlossen'
        );
        assert.deepEqual(
            Object.fromEntries(
                fs
                    .readdirSync(root + '/.data/thumbnails')
                    .map(name => [name, fs.readFileSync(root + '/.data/thumbnails/' + name)])
            ),
            cache
        );
        let fallbackId = ids['without-preview.webp'];
        for (let [width, height] of [
            [1440, 1000],
            [390, 844]
        ]) {
            await page.setViewportSize({ width, height });
            await page.goto(url + '?relevance=all');
            let $fallback = page.locator(`img[src="?photo=${fallbackId}&size=display"]`).first();
            await $fallback.evaluate(image => image.decode());
            assert.ok(await $fallback.evaluate(image => image.naturalWidth > 0));
            let response = await page.request.get(url + `?photo=${fallbackId}`);
            assert.equal(response.status(), 200);
            assert.equal(response.headers()['content-type'], 'image/svg+xml');
            assert.deepEqual(await response.body(), fs.readFileSync(project + '/assets/favicon.svg'));
            assert.equal(
                (
                    await page.request.get(url + `?photo=${fallbackId}`, {
                        headers: { 'If-None-Match': response.headers().etag }
                    })
                ).status(),
                304
            );
            await page.reload();
            await $fallback.evaluate(image => image.decode());
        }
        assert.deepEqual(errors, []);
        console.log(
            'PASS: thumbnail-only CLI job, order, cached JPEG/PNG previews, offline popup fallback, desktop/mobile/reload, authenticated 304, warm cache and persistent fallback icons. URL: ' +
                url
        );
    } finally {
        if (browser) await browser.close();
        if (server) {
            server.kill();
            await once(server, 'exit');
        }
        fs.rmSync(root, { recursive: true, force: true });
        assert.equal(fs.existsSync(root), false);
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
