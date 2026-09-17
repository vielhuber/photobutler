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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-menu-'));
    let live = process.env.PHOTOBUTLER_MENU_LIVE === '1';
    let url = 'https://photobutler.rebuhleiv.xyz/';
    let credentials = { username: 'menu-test', password: 'isolated-menu-test' };
    let server;
    let browser;
    try {
        if (live) {
            credentials = JSON.parse(
                execFileSync(
                    'php',
                    [
                        '-r',
                        `require ${JSON.stringify(project + '/vendor/autoload.php')}; $settings = \\Dotenv\\Dotenv::parse(file_get_contents(${JSON.stringify(project + '/.data/.env')})); echo json_encode(['username' => $settings['AUTH_USERNAME'], 'password' => $settings['AUTH_PASSWORD']]);`
                    ],
                    { encoding: 'utf8' }
                )
            );
        } else {
            for (let directory of ['.data', 'public', 'photos']) fs.mkdirSync(root + '/' + directory);
            fs.writeFileSync(
                root + '/.data/.env',
                `AUTH_USERNAME=${credentials.username}\nAUTH_PASSWORD=${credentials.password}\nJWT_SECRET=isolated-menu-test-signing-secret\nPHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\n`
            );
            fs.writeFileSync(
                root + '/public/index.php',
                `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
            );
            let socket = net.createServer();
            socket.listen(0, '127.0.0.1');
            await once(socket, 'listening');
            let port = socket.address().port;
            await new Promise(resolve => socket.close(resolve));
            server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
                stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
            });
            url = `http://127.0.0.1:${port}/`;
        }
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        let page = await context.newPage();
        let errors = [],
            writes = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (request.method() === 'POST' && !request.url().includes('/index.php/login')) {
                let body = request.postData() || '';
                let action =
                    new URLSearchParams(body).get('action') || body.match(/name="action"\r\n\r\n([^\r]+)/)?.[1];
                if (!['login', 'logout'].includes(action)) writes.push(action);
            }
        });
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill(credentials.username);
        await page.getByLabel('Passwort').fill(credentials.password);
        await page.getByRole('button', { name: 'Anmelden' }).click();
        await page.waitForSelector('.job-card');
        assert.equal(
            await (await context.request.get(url + '?asset=app.css')).text(),
            fs.readFileSync(project + '/assets/app.css', 'utf8'),
            'served CSS matches the worktree'
        );
        await page.evaluate(() => {
            localStorage.setItem('photobutler.sidebarWidth', '420');
            localStorage.setItem('photobutler.galleryColumns', '7');
        });
        await page.addInitScript(() => {
            window.menuFrames = [];
            let sample = () => {
                let $links = [...document.querySelectorAll('nav[aria-label="Bibliothek"] .nav-item')];
                if ($links.length === 4 && document.styleSheets.length) {
                    window.menuFrames.push({
                        sidebar: document.querySelector('.sidebar').getBoundingClientRect().width,
                        columns: document.querySelector('#gallery-columns')?.value,
                        offsets: $links.map($link => {
                            let $text = [...$link.childNodes].find(
                                $node => $node.nodeType === 3 && $node.textContent.trim()
                            );
                            let range = document.createRange();
                            range.selectNodeContents($text);
                            return range.getBoundingClientRect().x - $link.getBoundingClientRect().x;
                        })
                    });
                }
                if (window.menuFrames.length < 5) requestAnimationFrame(sample);
            };
            requestAnimationFrame(sample);
        });
        for (let width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.reload();
            await page.waitForFunction(() => window.menuFrames.length === 5);
            let frames = await page.evaluate(() => window.menuFrames);
            console.log(JSON.stringify({ width, firstFrame: frames[0] }));
            for (let frame of frames) {
                assert.equal(frame.sidebar, width > 760 ? 420 : width, 'persisted first-frame sidebar width');
                assert.equal(frame.columns, '7', 'persisted first-frame dropdown');
                assert.ok(
                    Math.max(...frame.offsets) - Math.min(...frame.offsets) < 0.1,
                    'all four labels have identical horizontal indentation'
                );
            }
            if (!live) {
                let emoji = await page
                    .locator('nav[aria-label="Bibliothek"] .nav-item')
                    .last()
                    .evaluate($link => {
                        let $icon = $link.firstElementChild;
                        $icon.style.fontFamily = '"Noto Color Emoji"';
                        let range = document.createRange();
                        range.selectNodeContents($icon.nextSibling);
                        let result = {
                            width: $icon.getBoundingClientRect().width,
                            offset: range.getBoundingClientRect().x - $link.getBoundingClientRect().x
                        };
                        $icon.style.removeProperty('font-family');
                        return result;
                    });
                assert.equal(emoji.width, 24, 'a color emoji must not widen the icon column');
                assert.equal(emoji.offset, frames[0].offsets[3], 'emoji fallback must not move the Jobs label');
            }
            await page.evaluate(() => {
                window.menuDocument = true;
            });
            for (let label of ['Alle Fotos', 'Favoriten', 'Personen', 'Jobs']) {
                let $link = page.locator('nav[aria-label="Bibliothek"] .nav-item').filter({ hasText: label });
                let destination = await $link.getAttribute('href');
                await $link.click();
                await page.waitForFunction(
                    destination =>
                        location.href === new URL(destination, location.href).href &&
                        !document.documentElement.classList.contains('page-loading'),
                    destination
                );
                assert.equal(await page.evaluate(() => window.menuDocument), true, 'navigation does not reload');
                assert.ok(
                    await page.locator('nav[aria-label="Bibliothek"] .active').filter({ hasText: label }).count()
                );
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                assert.equal(
                    await page.locator('.sidebar').evaluate($sidebar => $sidebar.getBoundingClientRect().width),
                    width > 760 ? 420 : width
                );
                if (label === 'Alle Fotos') {
                    assert.equal(await page.locator('#gallery-columns').inputValue(), '7');
                    assert.equal(
                        await page
                            .locator('.photo-grid')
                            .evaluate($grid => getComputedStyle($grid).gridTemplateColumns.split(' ').length),
                        width > 760 ? 7 : 2
                    );
                }
                if (label === 'Jobs') assert.equal(await page.locator('.job-card').count(), 4);
            }
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS) {
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + `/menu-${live ? 'live' : 'local'}-${width}.png`,
                    fullPage: true
                });
            }
            if (width > 760) {
                await page.locator('#sidebar-resize').focus();
                await page.keyboard.press('ArrowRight');
                assert.equal(
                    await page.locator('.sidebar').evaluate($sidebar => $sidebar.getBoundingClientRect().width),
                    430
                );
                assert.equal(await page.evaluate(() => localStorage.getItem('photobutler.sidebarWidth')), '430');
                await page.keyboard.press('ArrowLeft');
            }
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.reload();
        await page.waitForFunction(() => window.menuFrames.length === 5);
        assert.equal(
            await page.evaluate(() => window.menuFrames[0].sidebar),
            420,
            'desktop width survives mobile reload'
        );
        await page.getByRole('button', { name: /Abmelden/ }).click();
        await page.waitForSelector('#login-form');
        assert.deepEqual(errors, []);
        assert.deepEqual(writes, [], 'no jobs or photo changes');
        console.log(
            'PASS: menu alignment, persisted first frames, resize, desktop/mobile navigation and columns; no mocks or job starts. URL: ' +
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
    console.error(error.message);
    process.exitCode = 1;
});
