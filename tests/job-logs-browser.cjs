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
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-job-logs-'));
    let server, browser, blocker;
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
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=logs-test\nAUTH_PASSWORD=isolated-logs-test\nJWT_SECRET=isolated-job-logs-signing-secret\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();`
        );
        php(
            `$image=imagecreatetruecolor(80,60); for($i=0;$i<4;$i++) { imagefill($image,0,0,imagecolorallocate($image,$i*40,0,0)); imagejpeg($image,$root.'/photos/'.$i.'.jpg'); } $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index(); $library->jobs->log('tag','<img src=x onerror=alert(1)>');`
        );
        let socket = net.createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        let port = socket.address().port;
        await new Promise(resolve => socket.close(resolve));
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
            detached: true,
            env: { ...process.env, PHP_CLI_SERVER_WORKERS: '2' },
            stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
        });
        blocker = spawn(
            'php',
            [
                '-r',
                `$lock=fopen(${JSON.stringify(root + '/.data/thumbnail-1.lock')},'c'); flock($lock,LOCK_EX); echo 'ready'; fgets(STDIN);`
            ],
            { stdio: ['pipe', 'pipe', 'ignore'] }
        );
        await once(blocker.stdout, 'data');
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
        let url = `http://127.0.0.1:${port}/`;
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('logs-test');
        await page.getByLabel('Passwort', { exact: true }).fill('isolated-logs-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('[data-job-log]');
        assert.equal(await page.locator('[data-job-log]').count(), 4);
        assert.equal(await page.locator('[data-job-log] img').count(), 0);
        assert.match(await page.locator('[data-job="tag"] [data-job-log]').textContent(), /<img/);
        assert.equal(actions.length, 0);
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document
                .querySelector('[data-job="previews"] [data-job-log]')
                .textContent.includes('Erzeuge Thumbnail für Foto')
        );
        assert.match(await page.locator('[data-job="previews"] [data-job-count]').textContent(), /^0 \/ 4/);
        await page.locator('[data-job="previews"] [data-job-action="pause"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="previews"] [data-job-log]').textContent.includes('Pause angefordert')
        );
        blocker.stdin.end('\n');
        await once(blocker, 'exit');
        blocker = null;
        await page.waitForFunction(
            () => !document.querySelector('[data-job="previews"] [data-job-action="start"]').disabled
        );
        assert.match(await page.locator('[data-job="previews"] [data-job-status]').textContent(), /Pausiert/);
        for (let [name, width, height] of [
            ['desktop', 1440, 1000],
            ['mobile', 390, 844]
        ]) {
            let count = actions.length;
            await page.setViewportSize({ width, height });
            await page.reload();
            await page.waitForSelector('[data-job-log]');
            assert.match(await page.locator('[data-job="previews"] [data-job-log]').textContent(), /Pause angefordert/);
            assert.equal(actions.length, count);
            assert.equal(await page.getByText('Eingelesene / vorhandene Dateien.', { exact: false }).count(), 0);
            let heights = await page
                .locator('.job-card')
                .evaluateAll($cards => $cards.map($card => $card.getBoundingClientRect().height));
            assert.ok(Math.max(...heights) - Math.min(...heights) < 1, name + ': equal job card heights');
            assert.equal(
                await page
                    .locator('[data-job="previews"] [data-job-log]')
                    .evaluate($log => Math.abs($log.scrollHeight - $log.clientHeight - $log.scrollTop) < 2),
                true
            );
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                await page.screenshot({
                    path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/job-logs-' + name + '.png',
                    fullPage: true
                });
        }
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="previews"] [data-job-status]').textContent.includes('Abgeschlossen')
        );
        assert.match(await page.locator('[data-job="previews"] [data-job-log]').textContent(), /Abgeschlossen/);
        await page.locator('[data-job="previews"] [data-job-action="start"]').click();
        await page.waitForFunction(() =>
            document
                .querySelector('[data-job="previews"] [data-job-log]')
                .textContent.includes('4 vorhandene Thumbnails übernommen')
        );
        page.once('dialog', dialog => dialog.accept());
        await page.locator('[data-job="previews"] [data-job-action="reset"]').click();
        await page.waitForFunction(() =>
            document.querySelector('[data-job="previews"] [data-job-log]').textContent.includes('Daten zurückgesetzt')
        );
        assert.doesNotMatch(
            await page.locator('[data-job="previews"] [data-job-log]').textContent(),
            /Erzeuge Thumbnail/
        );
        assert.match(await page.locator('[data-job="tag"] [data-job-log]').textContent(), /<img/);
        assert.deepEqual(errors, []);
        console.log(
            'PASS: four escaped logs, updates during a blocked real worker, pause/resume, desktop/mobile/reload, cache hits and independent reset. URL: ' +
                url
        );
    } finally {
        if (blocker) {
            blocker.stdin.end('\n');
            blocker.kill();
        }
        if (browser) await browser.close();
        if (server) {
            process.kill(-server.pid, 'SIGTERM');
            await once(server, 'exit');
        }
        fs.rmSync(root, { recursive: true, force: true });
        assert.equal(fs.existsSync(root), false);
    }
})().catch(error => {
    console.error(error.message);
    process.exitCode = 1;
});
