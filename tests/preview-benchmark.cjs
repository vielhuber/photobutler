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
    let controlsOnly = process.argv.includes('--controls-only');
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-preview-benchmark-'));
    let server, browser;
    let php = code =>
        execFileSync(
            'php',
            [
                '-r',
                `require ${JSON.stringify(project + '/vendor/autoload.php')}; $root = ${JSON.stringify(root)}; ${code}`
            ],
            { encoding: 'utf8' }
        );
    try {
        fs.mkdirSync(root + '/.data');
        fs.mkdirSync(root + '/public');
        fs.mkdirSync(root + '/photos');
        fs.writeFileSync(
            root + '/.data/.env',
            `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\nAUTH_USERNAME=preview-test\nAUTH_PASSWORD=preview-test\nJWT_SECRET=isolated-preview-benchmark-secret-123456\n`
        );
        fs.writeFileSync(
            root + '/public/index.php',
            `<?php declare(strict_types=1); $started=(hrtime(true)/1000000000); ob_start(); require ${JSON.stringify(project + '/vendor/autoload.php')}; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run(); header('Server-Timing: app;dur=' . (((hrtime(true)/1000000000)-$started)*1000)); ob_end_flush();`
        );
        php(`foreach ([[4000,3000],[1920,1280],[640,480]] as $size) {
            $image=imagecreatetruecolor($size[0],$size[1]);
            for($y=0;$y<$size[1];$y+=8) for($x=0;$x<$size[0];$x+=8) imagefilledrectangle($image,$x,$y,$x+7,$y+7,(($x*73856093)^($y*19349663))&0xffffff);
            for($i=0;$i<6;$i++) { imagefilledrectangle($image,0,0,15,15,imagecolorallocate($image,$i*40,0,0)); imagejpeg($image,$root.'/photos/'.$size[0].'-'.$i.'.jpg',95); }
        }
        $image=imagecreatetruecolor(80,60); imagefilledrectangle($image,0,0,79,59,0x338877);
        for($i=0;$i<320;$i++) { imagejpeg($image,$root.'/photos/small-'.$i.'.jpg',95); file_put_contents($root.'/photos/small-'.$i.'.jpg',(string)$i,FILE_APPEND); }
        $archive=new ZipArchive(); $archive->open($root.'/photos/sticker.webp',ZipArchive::CREATE);
        $archive->addFromString('animation/animation.json',file_get_contents(${JSON.stringify(project + '/tests/fixtures/sticker.json')})); $archive->setMtimeName('animation/animation.json',1234567890); $archive->close();
        copy(${JSON.stringify(project + '/tests/fixtures/animated-sticker.webp')},$root.'/photos/animated.webp');
        $library=new \\vielhuber\\photobutler\\PhotoButler($root); $library->index(); $library->importProgress(refresh:true);`);
        let component = JSON.parse(
            php(`$library=new \\vielhuber\\photobutler\\PhotoButler($root); $rows=$library->database->query('SELECT id,path FROM photos ORDER BY path')->fetchAll(); $result=[];
        foreach($rows as $row) {
            $name=basename($row['path']); if(!in_array($name,['4000-0.jpg','1920-0.jpg','640-0.jpg','sticker.webp','animated.webp'])) continue;
            $t=(hrtime(true)/1000000000); $thumbnail=$library->imagePath($row['id'],animated:true); $a=(hrtime(true)/1000000000);
            $library->imagePath($row['id'],animated:true);
            $result[$name]=['thumbnailMs'=>1000*($a-$t),'cachedThumbnailMs'=>1000*((hrtime(true)/1000000000)-$a)];
        }
        $image=$root.'/photos/4000-0.jpg'; $t=(hrtime(true)/1000000000); for($i=0;$i<3;$i++) { $decoded=imagecreatefromjpeg($image); unset($decoded); } $result['jpegDecodeMs']=((hrtime(true)/1000000000)-$t)*1000/3;
        echo json_encode($result);`)
        );
        let socket = net.createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        let port = socket.address().port;
        await new Promise(resolve => socket.close(resolve));
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root + '/public'], {
            stdio: ['ignore', 'ignore', fs.openSync(root + '/server.log', 'a')]
        });
        browser = await chromium.launch({
            executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome',
            args: ['--no-sandbox']
        });
        let page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.setDefaultTimeout(120000);
        await page.context().addInitScript(() => {
            let capture = () => {
                let $estimates = [...document.querySelectorAll('[data-job-eta]')];
                if ($estimates.length === 4 && $estimates[0].getBoundingClientRect().width > 0) {
                    window.firstEstimates = $estimates.map($estimate => $estimate.textContent);
                    return;
                }
                requestAnimationFrame(capture);
            };
            requestAnimationFrame(capture);
        });
        let errors = [],
            samples = [],
            active = false;
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => {
            if (active && response.request().postData()?.includes('job-step'))
                samples.push({
                    serverMs: Number(response.headers()['server-timing']?.split('=')[1]),
                    at: performance.now()
                });
        });
        let url = `http://127.0.0.1:${port}/`;
        await page.goto(url + '?view=jobs');
        await page.getByLabel('Benutzername').fill('preview-test');
        await page.getByLabel('Passwort', { exact: true }).fill('preview-test');
        await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await page.waitForSelector('[data-job="previews"]');
        let results = [],
            hashes;
        for (let repeat = 0; repeat < (controlsOnly ? 0 : 3); repeat++)
            for (let mode of ['cold', 'warm']) {
                if (mode === 'cold')
                    for (let file of fs.readdirSync(root + '/.data/thumbnails'))
                        fs.unlinkSync(root + '/.data/thumbnails/' + file);
                samples = [];
                active = true;
                let start = performance.now();
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
                let elapsedMs = performance.now() - start;
                active = false;
                results.push({
                    repeat,
                    mode,
                    elapsedMs,
                    steps: samples.length,
                    serverMs: samples.reduce((sum, item) => sum + item.serverMs, 0),
                    maxStepMs: Math.max(...samples.map(item => item.serverMs))
                });
                let state = JSON.parse(
                    php(`$library=new \\vielhuber\\photobutler\\PhotoButler($root); $rows=$library->database->query('SELECT id,path FROM photos ORDER BY path')->fetchAll(); $hashes=[];
                foreach($rows as $row) { $thumbnail=$library->imagePath($row['id'],animated:true);
                    if(!$thumbnail) throw new RuntimeException('Missing cache.');
                    $hashes[]=hash_file('sha256',$row['path']).hash_file('sha256',$thumbnail);
                    if(str_ends_with($row['path'],'.jpg')&&(max(getimagesize($thumbnail)[0],getimagesize($thumbnail)[1])>640)) throw new RuntimeException('Invalid dimensions or bytes.');
                    if(str_ends_with($row['path'],'.webp')&&!str_contains(file_get_contents($thumbnail),'ANIM')) throw new RuntimeException('Animation lost.');
                }
                if(glob($root.'/.data/thumbnails/*.detail*')) throw new RuntimeException('Unexpected medium cache.');
                echo json_encode(['hash'=>hash('sha256',implode('',$hashes)),'state'=>$library->jobs->all()['previews']]);`)
                );
                hashes ??= state.hash;
                assert.equal(state.hash, hashes);
                assert.equal(state.state.completed, 340);
                assert.equal(state.state.errors, 0);
            }
        if (controlsOnly) {
            for (let file of fs.readdirSync(root + '/.data/thumbnails'))
                fs.unlinkSync(root + '/.data/thumbnails/' + file);
            let actions = [];
            page.on('request', request => {
                if (request.postData()?.includes('job-')) actions.push(request.postData());
            });
            let counts = [];
            for (let [name, width, height] of [
                ['desktop', 1440, 1000],
                ['mobile', 390, 844]
            ]) {
                let before = JSON.parse(
                    php(
                        `$library=new \\vielhuber\\photobutler\\PhotoButler($root); echo json_encode($library->jobs->all()['previews']);`
                    )
                );
                let requests = actions.length;
                await page.setViewportSize({ width, height });
                await page.reload();
                await page.waitForSelector('[data-job="previews"]');
                assert.match(
                    await page.locator('[data-job="previews"] [data-job-count]').textContent(),
                    new RegExp('^' + before.completed + ' / 340')
                );
                await page.waitForFunction(() => window.firstEstimates);
                assert.equal((await page.evaluate(() => window.firstEstimates))[1], before.eta);
                assert.equal(actions.length, requests);
                let processing = page.waitForRequest(request => request.postData()?.includes('job-step'));
                await page.locator('[data-job="previews"] [data-job-action="start"]').click();
                await processing;
                await page.evaluate(() =>
                    document.querySelector('[data-job="previews"] [data-job-action="pause"]').click()
                );
                await page.waitForFunction(
                    () =>
                        document
                            .querySelector('[data-job="previews"] [data-job-status]')
                            .textContent.includes('Pausiert') &&
                        !document.querySelector('[data-job="previews"] [data-job-action="start"]').disabled
                );
                let after = JSON.parse(
                    php(
                        `$library=new \\vielhuber\\photobutler\\PhotoButler($root); echo json_encode($library->jobs->all());`
                    )
                );
                assert.equal(after.previews.status, 'paused');
                assert.ok(after.previews.eta_seconds > 0);
                assert.equal(
                    await page.locator('[data-job="previews"] [data-job-eta]').textContent(),
                    after.previews.eta
                );
                assert.ok(after.previews.completed > before.completed);
                assert.ok(after.previews.completed <= before.completed + 25);
                for (let job of ['scan', 'tag', 'faces']) assert.equal(after[job].status, 'idle');
                counts.push(after.previews.completed);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                    await page.screenshot({
                        path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/preview-speed-' + name + '.png',
                        fullPage: true
                    });
            }
            php(`$library=new \\vielhuber\\photobutler\\PhotoButler($root);
                foreach ($library->jobs->all() as $job=>$state) {
                    $library->database->prepare('INSERT OR REPLACE INTO job_timings (job,seconds_per_file) VALUES (?,?)')->execute([$job === 'previews' ? 'thumbnails' : $job,4799/$state['remaining_files']]);
                }`);
            for (let [name, width, height] of [
                ['desktop', 1440, 1000],
                ['mobile', 390, 844]
            ]) {
                let requests = actions.length;
                await page.setViewportSize({ width, height });
                await page.reload();
                await page.waitForFunction(() => window.firstEstimates);
                assert.deepEqual(await page.evaluate(() => window.firstEstimates), Array(4).fill('ca. 1 Std. 20 Min.'));
                assert.equal(actions.length, requests);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                if (process.env.PHOTOBUTLER_BROWSER_ARTIFACTS)
                    await page.screenshot({
                        path: process.env.PHOTOBUTLER_BROWSER_ARTIFACTS + '/job-eta-' + name + '.png',
                        fullPage: true
                    });
            }
            console.log(
                'PASS: real preview start/pause/resume, partial counts ' +
                    counts.join(' → ') +
                    ', real measured preview ETA persists, all four formatted ETAs checked with seeded timing history on desktop/mobile first paint/reload, no automatic requests.'
            );
        }
        assert.deepEqual(errors, []);
        console.log(
            JSON.stringify(
                {
                    fixtures: '340 files: 6x12MP, 6x2.46MP, 6x0.31MP, 320x80x60, 2 animated stickers',
                    hash: hashes,
                    component,
                    results
                },
                null,
                2
            )
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
