let { test } = require('node:test');
let assert = require('node:assert/strict');
let { mkdtempSync, readFileSync, readdirSync, rmSync } = require('node:fs');
let { tmpdir } = require('node:os');
let { join } = require('node:path');
let { spawnSync } = require('node:child_process');
let sharp = require('sharp');

test('sticker conversion preserves duration, motion and transparency with a static AI preview', async () => {
    let directory = mkdtempSync(join(tmpdir(), 'photobutler-sticker-'));
    try {
        let target = join(directory, 'preview.jpg');
        let result = spawnSync(process.execPath, ['scripts/render-sticker.cjs', target], {
            input: readFileSync('tests/fixtures/sticker.json'),
            timeout: 10000
        });
        assert.equal(result.status, 0, result.stderr.toString());
        let metadata = await sharp(target + '.webp.tmp', { animated: true }).metadata();
        assert.equal(metadata.pages, 10);
        assert.equal(metadata.loop, 0);
        assert.equal(
            metadata.delay.reduce((sum, delay) => sum + delay, 0),
            1000
        );
        assert.equal(metadata.hasAlpha, true);
        let first = await sharp(target + '.webp.tmp', { page: 0 })
            .raw()
            .toBuffer();
        let last = await sharp(target + '.webp.tmp', { page: 9 })
            .raw()
            .toBuffer();
        assert.notDeepEqual(first, last);
        let preview = await sharp(target + '.tmp').metadata();
        assert.equal(preview.format, 'jpeg');
        assert.equal(preview.width, 64);
        assert.equal(preview.hasAlpha, false);
    } finally {
        rmSync(directory, { recursive: true });
    }
});

test('invalid or oversized sticker animations are rejected without output', () => {
    let directory = mkdtempSync(join(tmpdir(), 'photobutler-sticker-'));
    try {
        let data = JSON.parse(readFileSync('tests/fixtures/sticker.json'));
        for (let input of [
            '{}',
            'invalid json',
            JSON.stringify({ ...data, w: 5000 }),
            JSON.stringify({ ...data, op: 99999 })
        ]) {
            let result = spawnSync(process.execPath, ['scripts/render-sticker.cjs', join(directory, 'preview')], {
                input,
                timeout: 10000
            });
            assert.equal(result.status, 1);
            assert.deepEqual(readdirSync(directory), []);
        }
    } finally {
        rmSync(directory, { recursive: true });
    }
});

test('native animated WebP keeps all frames and delays while producing a JPEG preview', async () => {
    let directory = mkdtempSync(join(tmpdir(), 'photobutler-webp-'));
    try {
        let target = join(directory, 'preview.jpg');
        let result = spawnSync(process.execPath, ['scripts/render-sticker.cjs', target], {
            input: readFileSync('tests/fixtures/animated-sticker.webp'),
            timeout: 10000
        });
        assert.equal(result.status, 0, result.stderr.toString());
        let metadata = await sharp(target + '.webp.tmp', { animated: true }).metadata();
        assert.equal(metadata.pages, 2);
        assert.deepEqual(metadata.delay, [100, 200]);
        assert.equal(metadata.loop, 0);
        assert.equal(metadata.exif, undefined);
        let first = await sharp(target + '.webp.tmp', { page: 0 })
            .raw()
            .toBuffer();
        let last = await sharp(target + '.webp.tmp', { page: 1 })
            .raw()
            .toBuffer();
        assert.notDeepEqual(first, last);
        assert.equal((await sharp(target + '.tmp').metadata()).format, 'jpeg');
    } finally {
        rmSync(directory, { recursive: true });
    }
});

test('larger Lottie stickers are rendered at 320 pixels', async () => {
    let directory = mkdtempSync(join(tmpdir(), 'photobutler-small-sticker-'));
    try {
        let data = JSON.parse(readFileSync('tests/fixtures/sticker.json'));
        data.w = data.h = 640;
        let target = join(directory, 'preview.jpg');
        let result = spawnSync(process.execPath, ['scripts/render-sticker.cjs', target], {
            input: JSON.stringify(data),
            timeout: 10000
        });
        assert.equal(result.status, 0, result.stderr.toString());
        assert.equal((await sharp(target + '.webp.tmp').metadata()).width, 320);
        assert.equal((await sharp(target + '.tmp').metadata()).width, 320);
    } finally {
        rmSync(directory, { recursive: true });
    }
});
