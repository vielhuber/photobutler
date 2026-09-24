let fs = require('node:fs');
let os = require('node:os');
let path = require('node:path');
let http = require('node:http');
let net = require('node:net');
let { once } = require('node:events');
let { spawn, execFileSync } = require('node:child_process');
let assert = require('node:assert/strict');
let { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    let project = path.resolve(__dirname, '..');
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-preloading-'));
    let server, proxy, browser;
    let held = new Map();
    let hold = () => false;
    let received = [];
    let writes = [];
    let errors = [];
    try {
        for (let directory of ['.data', 'public', 'photos']) fs.mkdirSync(root + '/' + directory);
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=preload-test\nAUTH_PASSWORD=isolated-preload-test\nJWT_SECRET=isolated-preload-test-signing-secret\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        execFileSync('php', [
            '-r',
            `require ${JSON.stringify(project + '/vendor/autoload.php')};
            $root=${JSON.stringify(root)};
            $image=imagecreatetruecolor(640,480); imagefilledrectangle($image,0,0,639,479,0x338877);
            for($i=1;$i<=66;$i++) { imagefilledrectangle($image,0,0,15,15,imagecolorallocate($image,$i*3,0,0)); imagejpeg($image,$root.'/photos/photo-'.sprintf('%02d',$i).'.jpg',85); }
            $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index();
            foreach($library->database->query('SELECT id FROM photos')->fetchAll() as $row) { $library->imagePath((int)$row['id']); }
        `
        ]);
        let originals = fs.readdirSync(root + '/photos').map(name => [name, fs.readFileSync(root + '/photos/' + name)]);
        let socket = net.createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        let port = socket.address().port;
        await new Promise(resolve => socket.close(resolve));
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
            stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
        });
        // Delay real upstream responses without replacing image bytes or application handlers.
        proxy = http.createServer((request, response) => {
            received.push(request.url);
            let upstream = http.request(
                { hostname: '127.0.0.1', port, path: request.url, method: request.method, headers: request.headers },
                result => {
                    let chunks = [];
                    result.on('data', chunk => chunks.push(chunk));
                    result.on('end', () => {
                        let send = () => {
                            held.delete(request.url);
                            response.writeHead(result.statusCode, result.headers);
                            response.end(Buffer.concat(chunks));
                        };
                        if (hold(new URL(request.url, 'http://localhost'))) held.set(request.url, send);
                        else send();
                    });
                }
            );
            upstream.on('error', error => {
                errors.push(error.message);
                response.destroy();
            });
            request.pipe(upstream);
        });
        proxy.listen(0, '127.0.0.1');
        await once(proxy, 'listening');
        let url = `http://127.0.0.1:${proxy.address().port}/`;
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let login = await browser.newPage();
        await login.goto(url + '?view=jobs');
        await login.getByLabel('Benutzername').fill('preload-test');
        await login.getByLabel('Passwort', { exact: true }).fill('isolated-preload-test');
        await login.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await login.waitForSelector('[data-job]');
        let storageState = await login.context().storageState();
        await login.close();
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            let context = await browser.newContext({
                storageState,
                viewport: { width, height },
                isMobile: name === 'mobile',
                hasTouch: name === 'mobile'
            });
            let page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            let requests = [];
            page.on('request', request => {
                requests.push(request);
                if (request.method() === 'POST') writes.push(request.postData());
            });
            let originalIds = () =>
                requests
                    .filter(request => new URL(request.url()).searchParams.get('size') === 'original')
                    .map(request => new URL(request.url()).searchParams.get('photo'));
            let release = () => {
                hold = () => false;
                for (let send of [...held.values()]) send();
            };
            hold = target => target.searchParams.get('size') === 'display' && held.size < 2;
            await page.goto(url + '?relevance=all&sort=newest', { waitUntil: 'domcontentloaded' });
            await page.waitForSelector('[data-photo]');
            await page.waitForFunction(() => document.querySelector('[data-photo] img').complete === false);
            let cards = await page.locator('[data-photo]').evaluateAll(cards => cards.map(card => card.dataset.photo));
            assert.equal(cards.length, 60);
            assert.deepEqual(originalIds(), []);
            let hovered = cards[3];
            let hoverRequest = page.waitForRequest(request =>
                request.url().endsWith(`?photo=${hovered}&size=original`)
            );
            let hoverResponse = page.waitForResponse(response =>
                response.url().endsWith(`?photo=${hovered}&size=original`)
            );
            await page.locator(`[data-photo="${hovered}"]`).hover();
            await hoverRequest;
            await hoverResponse;
            assert.ok(held.size > 0, 'original responds while real thumbnail responses remain outstanding');
            assert.deepEqual(originalIds(), [hovered]);
            release();
            await page.waitForLoadState('networkidle');
            let offscreen = await page
                .locator('[data-photo]')
                .evaluateAll(cards =>
                    cards
                        .filter(card => card.getBoundingClientRect().top >= innerHeight)
                        .map(card => card.dataset.photo)
                );
            assert.ok(
                requests.some(request => {
                    let target = new URL(request.url());
                    return (
                        target.searchParams.get('size') === 'display' &&
                        offscreen.includes(target.searchParams.get('photo'))
                    );
                }),
                'thumbnail preloading includes photos beyond the viewport'
            );
            assert.ok(
                await page
                    .locator('[data-photo] img')
                    .first()
                    .evaluate(image => image.naturalWidth > 0)
            );
            await page.mouse.move(0, 0);
            await page.locator(`[data-photo="${hovered}"]`).hover();
            await page.waitForLoadState('networkidle');
            assert.deepEqual(originalIds(), [hovered], 'hover cache reuse');
            await page.mouse.move(0, 0);
            for (let index of [0, 4, 59]) {
                requests = [];
                await page.goto(url + `?relevance=all&sort=newest&image=${cards[index]}`);
                await page.waitForLoadState('networkidle');
                let expected = cards.slice(Math.max(0, index - 2), index + 3);
                assert.deepEqual(
                    [...new Set(originalIds())].sort(),
                    expected.slice().sort(),
                    name + ' popup neighbours ' + index
                );
                assert.ok(await page.locator('#viewer-image').evaluate(image => image.naturalWidth > 0));
                requests = [];
                await page.reload();
                await page.waitForLoadState('networkidle');
                assert.deepEqual(
                    [...new Set(originalIds())].sort(),
                    expected.slice().sort(),
                    name + ' reload neighbours'
                );
            }
            requests = [];
            await page.goto(url + `?relevance=all&sort=newest&image=${cards[4]}`);
            await page.waitForLoadState('networkidle');
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/preloading-' + name + '.png',
                    fullPage: false
                });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            let nextNeighbour = page.waitForRequest(request =>
                request.url().endsWith(`?photo=${cards[7]}&size=original`)
            );
            await page.locator('.viewer-next').click();
            await nextNeighbour;
            await page.waitForLoadState('networkidle');
            assert.deepEqual(
                [...new Set(originalIds())].sort(),
                cards.slice(2, 8).sort(),
                'navigation adds only the new second neighbour'
            );

            requests = [];
            hold = target =>
                target.searchParams.get('size') === 'original' &&
                [cards[19], cards[21]].includes(target.searchParams.get('photo'));
            let pendingNeighbour = page.waitForRequest(request =>
                request.url().endsWith(`?photo=${cards[19]}&size=original`)
            );
            await page.goto(url + `?relevance=all&sort=newest&image=${cards[20]}`, { waitUntil: 'domcontentloaded' });
            await pendingNeighbour;
            await page.locator('.viewer-next').click();
            await page.locator('.viewer-next').click();
            release();
            await page.waitForLoadState('networkidle');
            assert.equal(
                originalIds().includes(cards[18]),
                false,
                'rapid navigation discards the stale second previous neighbour'
            );
            await page.locator('.viewer-close').click();
            await page.waitForFunction(() => !document.querySelector('#viewer').open);
            await page.mouse.move(0, 0);
            let before = originalIds().length;
            await page.locator('#gallery-sort').selectOption('oldest');
            await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'oldest');
            await page.waitForLoadState('networkidle');
            assert.equal(originalIds().length, before, 'sorting does not speculate originals');
            await page.locator('#gallery-favorites').selectOption('1');
            await page.waitForFunction(() => document.querySelectorAll('[data-photo]').length === 0);
            await page.waitForLoadState('networkidle');
            assert.equal(originalIds().length, before, 'filtering does not speculate originals');
            assert.equal(
                requests.filter(request => request.isNavigationRequest()).length,
                1,
                'sort and filter stay reload-free'
            );
            requests = [];
            await page.goto(url + '?relevance=all&sort=newest&page=2');
            await page.waitForLoadState('networkidle');
            assert.equal(await page.locator('[data-photo]').count(), 6);
            assert.deepEqual(originalIds(), []);
            await page.reload();
            await page.waitForLoadState('networkidle');
            assert.deepEqual(originalIds(), []);
            await context.close();
            console.log(
                'PASS: ' +
                    name +
                    ' real delayed thumbnails, parallel hover, cache reuse, two popup neighbours, edges, rapid navigation, sort/filter/page/reload. ' +
                    url
            );
        }
        assert.equal(
            received.some(value => /size=(detail|medium)/.test(value)),
            false
        );
        assert.deepEqual(writes, [], 'gallery interactions never start jobs or other writes');
        for (let [name, bytes] of originals) assert.deepEqual(fs.readFileSync(root + '/photos/' + name), bytes);
        assert.deepEqual(errors, []);
    } finally {
        hold = () => false;
        for (let send of [...held.values()]) send();
        if (browser) await browser.close();
        if (proxy) {
            proxy.closeAllConnections();
            await new Promise(resolve => proxy.close(resolve));
        }
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
