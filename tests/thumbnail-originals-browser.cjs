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
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=original-test\nAUTH_PASSWORD=isolated-original-test\nJWT_SECRET=isolated-original-test-signing-secret\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        php(`$image=imagecreatetruecolor(2400,1600); imagefilledrectangle($image,0,0,2399,1599,0x338877); imagejpeg($image,$root.'/photos/photo.jpg',95);
            $archive=new ZipArchive(); $archive->open($root.'/photos/archive.webp',ZipArchive::CREATE); $archive->addFromString('animation/animation.json',file_get_contents(${JSON.stringify(project + '/tests/fixtures/sticker.json')})); $archive->close();
            copy(${JSON.stringify(project + '/tests/fixtures/animated-sticker.webp')},$root.'/photos/animated.webp');
            $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index();`);
        let originals = Object.fromEntries(
            fs.readdirSync(root + '/photos').map(name => [name, fs.readFileSync(root + '/photos/' + name)])
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
        assert.equal(await page.locator('[data-job="previews"] h2').textContent(), 'Thumbnails generieren');
        assert.equal(actions.length, 0);
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-job="previews"] [data-job-status]').textContent ===
                '100 % · Abgeschlossen'
        );
        assert.match(await page.locator('[data-job="previews"] [data-job-count]').textContent(), /^3 \/ 3 · 0 Fehler$/);
        let cache = Object.fromEntries(
            fs
                .readdirSync(root + '/.data/thumbnails')
                .map(name => [name, fs.readFileSync(root + '/.data/thumbnails/' + name)])
        );
        assert.equal(Object.keys(cache).length, 5);
        assert.equal(
            Object.keys(cache).some(name => name.includes('.detail')),
            false
        );
        for (let name of Object.keys(cache).filter(name => name.endsWith('.webp')))
            assert.ok(cache[name].includes('ANIM'));
        let rows = JSON.parse(
            php(
                `$library=new \\vielhuber\\photobutler\\PhotoButler($root); echo json_encode($library->database->query('SELECT id,name FROM photos')->fetchAll());`
            )
        );
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            await page.setViewportSize({ width, height });
            for (let row of rows) {
                await page.goto(url + '?relevance=all&image=' + row.id);
                await page.waitForFunction(
                    () =>
                        document.querySelector('#viewer-image').naturalWidth > 0 &&
                        document.querySelector('#viewer').open
                );
                let expected = row.name === 'archive.webp' ? 'display' : 'original';
                assert.equal(
                    await page.locator('#viewer-image').getAttribute('src'),
                    `?photo=${row.id}&size=${expected}`
                );
                if (row.name === 'photo.jpg')
                    assert.equal(await page.locator('#viewer-image').evaluate(image => image.naturalWidth), 2400);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                    await page.screenshot({
                        path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/original-' + name + '-' + row.name + '.png',
                        fullPage: true
                    });
                let sourceUrl = url + `?photo=${row.id}&size=original`;
                let original = await page.request.get(sourceUrl);
                assert.deepEqual(await original.body(), originals[row.name]);
                assert.equal(
                    (
                        await page.request.get(sourceUrl, { headers: { 'If-None-Match': original.headers().etag } })
                    ).status(),
                    304
                );
                assert.deepEqual(await (await page.request.get(sourceUrl + '&download=1')).body(), originals[row.name]);
                assert.deepEqual(
                    await (await page.request.get(url + `?photo=${row.id}&size=detail`)).body(),
                    originals[row.name]
                );
                await page.reload();
                await page.waitForFunction(() => document.querySelector('#viewer-image').naturalWidth > 0);
                assert.equal(
                    await page.locator('#viewer-image').getAttribute('src'),
                    `?photo=${row.id}&size=${expected}`
                );
            }
        }
        let before = actions.length;
        await page.goto(url + '?view=jobs');
        await page.waitForSelector('[data-job="previews"]');
        assert.equal(actions.length, before);
        let finished = page.waitForResponse(
            async response =>
                response.request().postData()?.includes('job-step') && (await response.json()).status === 'done'
        );
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await finished;
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
        for (let [name, data] of Object.entries(originals))
            assert.deepEqual(fs.readFileSync(root + '/photos/' + name), data);
        assert.deepEqual(errors, []);
        console.log(
            'PASS: thumbnail-only job, both animated sticker formats, order, original popup dimensions/bytes, sticker fallback, desktop/mobile/reload, original and legacy HTTP sources, authenticated 304, downloads and warm cache. URL: ' +
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
