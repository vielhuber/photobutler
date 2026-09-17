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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-auth-browser-'));
    let live = process.env.PHOTOBUTLER_AUTH_LIVE === '1';
    let url = 'https://photobutler.rebuhleiv.xyz/';
    let credentials = { username: 'auth-browser', password: 'isolated-browser-password' };
    let server;
    let context;
    let errors = [];
    let unexpectedRequests = [];
    let launch = async () => {
        context = await chromium.launchPersistentContext(root + '/profile', {
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox'],
            viewport: { width: 1440, height: 1000 }
        });
        context.on('request', request => {
            if (!request.url().startsWith(url)) unexpectedRequests.push('external request');
            if (request.method() === 'POST' && !request.url().includes('/index.php/login')) {
                let body = request.postData() || '';
                let action =
                    new URLSearchParams(body).get('action') || body.match(/name="action"\r\n\r\n([^\r]+)/)?.[1];
                if (!['login', 'logout'].includes(action)) unexpectedRequests.push('unexpected write');
            }
        });
        let page = context.pages()[0];
        page.on('pageerror', () => errors.push('browser error'));
        return page;
    };
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
            for (let directory of ['.data', 'public', 'sessions', 'photos']) fs.mkdirSync(root + '/' + directory);
            fs.writeFileSync(
                root + '/.data/.env',
                `AUTH_USERNAME=${credentials.username}\nAUTH_PASSWORD=${credentials.password}\nJWT_SECRET=isolated-browser-signing-secret\nPHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\n`
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
            server = spawn(
                'php',
                [
                    '-d',
                    'session.save_path=' + root + '/sessions',
                    '-d',
                    'session.gc_maxlifetime=1',
                    '-S',
                    `127.0.0.1:${port}`,
                    '-t',
                    root + '/public'
                ],
                { stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')] }
            );
            url = `http://127.0.0.1:${port}/`;
        }
        let page = await launch();
        await page.goto(url + '?view=jobs');
        if (!live) {
            for (let file of fs.readdirSync(root + '/sessions')) fs.unlinkSync(root + '/sessions/' + file);
            await page.getByLabel('Benutzername').fill(credentials.username);
            await page.getByLabel('Passwort').fill(credentials.password);
            let rejectedLogin = page.waitForResponse(response => response.url().endsWith('/index.php/login'));
            await page.getByRole('button', { name: 'Anmelden' }).click();
            assert.equal((await rejectedLogin).status(), 403);
            await page
                .getByText('Sitzung ist abgelaufen oder ungültig. Bitte die Seite neu laden und erneut anmelden.')
                .waitFor();
            assert.equal(
                (await context.cookies(url)).some(cookie => cookie.name === 'photobutler_remember'),
                false
            );
            await page.setViewportSize({ width: 390, height: 844 });
            assert.equal(await page.locator('#login-error').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.reload();
            await page.setViewportSize({ width: 1440, height: 1000 });
        }
        await page.getByLabel('Benutzername').fill(credentials.username);
        await page.getByLabel('Passwort').fill(credentials.password);
        await page.getByRole('button', { name: 'Anmelden' }).click();
        await page.waitForSelector('.job-card');
        assert.equal(await page.locator('.job-card').count(), 4);
        let cookie = (await context.cookies(url)).find(cookie => cookie.name === 'photobutler_remember');
        assert.ok(cookie, 'Persistent cookie issued by running application');
        assert.ok(/^[a-f0-9]{64}$/.test(cookie.value));
        assert.equal(cookie.httpOnly, true);
        assert.equal(cookie.secure, live);
        assert.equal(cookie.sameSite, 'Strict');
        assert.equal(cookie.path, '/');
        assert.equal(cookie.domain, new URL(url).hostname);
        assert.ok(Math.abs(cookie.expires - Date.now() / 1000 - 31536000) < 10);
        assert.equal(await page.evaluate(() => document.cookie.includes('photobutler_remember')), false);
        if (!live && process.env.PHOTOBUTLER_BROWSER_ARTIFACTS) {
            fs.mkdirSync(process.env.PHOTOBUTLER_BROWSER_ARTIFACTS, { recursive: true });
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/auth-desktop.png',
                fullPage: true
            });
        }
        await context.close();
        context = null;
        if (!live) {
            for (let file of fs.readdirSync(root + '/sessions')) fs.unlinkSync(root + '/sessions/' + file);
        }
        page = await launch();
        let persistedCookie = (await context.cookies(url)).find(cookie => cookie.name === 'photobutler_remember');
        assert.ok(persistedCookie && persistedCookie.value === cookie.value, 'Cookie survived browser process restart');
        await context.clearCookies({ name: 'photobutler' });
        await page.goto(url + '?view=jobs');
        await page.waitForSelector('.job-card');
        assert.equal(await page.locator('#login-form').count(), 0);
        await page.reload();
        await page.waitForSelector('.job-card');
        assert.ok((await context.cookies(url)).find(item => item.name === cookie.name).expires === cookie.expires);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('[data-job-action="start"]:visible').count(), 4);
        if (!live && process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
            await page.screenshot({
                path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/auth-mobile.png',
                fullPage: true
            });
        let logout = page.getByRole('button', { name: /Abmelden/ });
        await logout.click();
        await page.waitForSelector('#login-form');
        assert.equal(
            (await context.cookies(url)).some(item => item.name === cookie.name),
            false
        );
        await context.addCookies([cookie]);
        await page.goto(url + '?view=jobs');
        await page.waitForSelector('#login-form');
        assert.equal((await context.request.get(url + '?jobs=1')).status(), 401);
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpectedRequests, []);
        console.log(
            'PASS: login, 365-day cookie attributes, real browser restart, missing short-session cookie' +
                (live ? '' : ' and deleted isolated server session, expired login form rejected with reload recovery') +
                ', reload without renewal, desktop/mobile, logout and revoked-token replay; no jobs or external requests. URL: ' +
                url
        );
    } finally {
        if (context) await context.close();
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
