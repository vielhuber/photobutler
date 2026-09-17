let test = require('node:test');
let assert = require('node:assert/strict');
let fs = require('node:fs');
let os = require('node:os');
let path = require('node:path');
let { spawnSync } = require('node:child_process');
let sharp = require('sharp');

test('photo renderer reuses a process while preserving limits, transparency and originals', async () => {
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-render-'));
    try {
        let source = root + '/source.png';
        await sharp({ create: { width: 2400, height: 1600, channels: 4, background: '#00000000' } })
            .png()
            .toFile(source);
        let original = fs.readFileSync(source);
        let requests = [
            { source, target: root + '/first-thumb', edge: 640, quality: 65 },
            { source, target: root + '/thumb', edge: 640, quality: 65 },
            { source: root + '/missing', target: root + '/missing-output', edge: 640, quality: 65 },
            { source, target: root + '/after-error', edge: 640, quality: 65 }
        ];
        let result = spawnSync('node', ['scripts/render-photo.cjs'], {
            input: requests.map(request => JSON.stringify(request)).join('\n') + '\n',
            encoding: 'utf8',
            env: { ...process.env, UV_THREADPOOL_SIZE: '1' }
        });
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(result.stdout.trim().split('\n'), ['ok', 'ok', 'error', 'ok']);
        for (let [name, edge] of [
            ['first-thumb', 640],
            ['thumb', 640],
            ['after-error', 640]
        ]) {
            let output = fs.readFileSync(root + '/' + name + '.tmp');
            let metadata = await sharp(output).metadata();
            assert.equal(metadata.width, edge);
            assert.equal(metadata.format, 'jpeg');
            assert.equal(metadata.hasAlpha, false);
            assert.equal(metadata.exif, undefined);
            assert.ok(output.length <= 100000);
            let pixel = await sharp(output).raw().toBuffer();
            assert.ok(Math.abs(pixel[0] - 246) <= 2);
            assert.ok(Math.abs(pixel[1] - 245) <= 2);
            assert.ok(Math.abs(pixel[2] - 241) <= 2);
        }
        assert.deepEqual(fs.readFileSync(source), original);
        assert.equal(fs.existsSync(root + '/missing-output.tmp'), false);
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('thumbnail resizing preserves dimensions and never enlarges small originals', async () => {
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-render-budget-'));
    try {
        let data = Buffer.alloc(2400 * 1600 * 3);
        for (let index = 0; index < data.length; index++) data[index] = (index * 73) ^ (index >>> 9);
        await sharp(data, { raw: { width: 2400, height: 1600, channels: 3 } })
            .jpeg({ quality: 100 })
            .toFile(root + '/large.jpg');
        await sharp({ create: { width: 100, height: 60, channels: 3, background: '#338877' } })
            .jpeg()
            .toFile(root + '/small.jpg');
        let requests = ['large', 'small'].map(name => ({
            source: root + '/' + name + '.jpg',
            target: root + '/' + name,
            edge: 640,
            quality: 65
        }));
        let result = spawnSync('node', ['scripts/render-photo.cjs'], {
            input: requests.map(request => JSON.stringify(request)).join('\n') + '\n',
            encoding: 'utf8',
            env: { ...process.env, UV_THREADPOOL_SIZE: '1' }
        });
        assert.equal(result.status, 0, result.stderr);
        assert.equal(result.stdout, 'ok\nok\n');
        let large = await sharp(root + '/large.tmp').metadata();
        assert.equal(large.width, 640);
        assert.ok(Math.abs(large.width / large.height - 1.5) < 0.02);
        let small = await sharp(root + '/small.tmp').metadata();
        assert.deepEqual([small.width, small.height], [100, 60]);
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('108 megapixel JPEGs render on demand and in manual jobs without changing originals or cache limits', async () => {
    let root = fs.mkdtempSync(path.join(os.tmpdir(), 'photobutler-large-jpeg-'));
    try {
        fs.mkdirSync(root + '/photos');
        fs.mkdirSync(root + '/.data');
        fs.writeFileSync(root + '/.data/.env', `PHOTO_PATHS='${JSON.stringify([root + '/photos'])}'\n`);
        let source = root + '/photos/large.jpg';
        await sharp({ create: { width: 12000, height: 9000, channels: 3, background: '#338877' } })
            .jpeg()
            .toFile(source);
        let original = fs.readFileSync(source);
        let result = spawnSync(
            'php',
            [
                '-r',
                `
            require ${JSON.stringify(path.resolve('vendor/autoload.php'))};
            $root = ${JSON.stringify(root)};
            $library = new \\vielhuber\\photobutler\\PhotoButler($root);
            $library->index();
            $thumbnail = $library->imagePath(1);
            if ($thumbnail === null) throw new RuntimeException('Large on-demand thumbnail failed.');
            $size = array_slice(getimagesize($thumbnail), 0, 2);
            unlink($thumbnail);
            $job = $library->jobs->start('previews');
            $state = $library->jobs->step('previews', $job['token']);
            touch($thumbnail, 1234567890);
            $job = $library->jobs->start('previews');
            $repeat = $library->jobs->step('previews', $job['token']);
            clearstatcache();
            echo json_encode([$size, $state['status'], $repeat['errors'], filemtime($thumbnail)]);
        `
            ],
            { encoding: 'utf8', timeout: 60000 }
        );
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(JSON.parse(result.stdout), [[640, 480], 'done', 0, 1234567890]);
        assert.deepEqual(fs.readFileSync(source), original);

        let oversized = Buffer.from(original);
        let marker = oversized.indexOf(Buffer.from([0xff, 0xc0]));
        assert.ok(marker >= 0);
        oversized.writeUInt16BE(15000, marker + 7);
        fs.writeFileSync(root + '/oversized.jpg', oversized);
        let requests = [
            { source: root + '/oversized.jpg', target: root + '/rejected', edge: 640, quality: 65 },
            { source, target: root + '/accepted', edge: 640, quality: 65 }
        ];
        let bounded = spawnSync('node', ['scripts/render-photo.cjs'], {
            input: requests.map(request => JSON.stringify(request)).join('\n') + '\n',
            encoding: 'utf8',
            env: { ...process.env, UV_THREADPOOL_SIZE: '1' }
        });
        assert.equal(bounded.status, 0, bounded.stderr);
        assert.equal(bounded.stdout, 'error\nok\n');
        assert.equal(fs.existsSync(root + '/rejected.tmp'), false);
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});
