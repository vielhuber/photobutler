let { readFileSync } = require('node:fs');
let { DotLottie } = require('@lottiefiles/dotlottie-web');
let sharp = require('sharp');

(async () => {
    let input = readFileSync(0);
    let target = process.argv[2];
    if (input.toString('ascii', 0, 4) === 'RIFF' && input.toString('ascii', 8, 12) === 'WEBP') {
        await sharp(input, { animated: true, limitInputPixels: 60000000 })
            .resize({ width: 320, height: 320, fit: 'inside', withoutEnlargement: true })
            .webp({ quality: 65, effort: 2, loop: 0 })
            .toFile(target + '.webp.tmp');
        await sharp(input, { limitInputPixels: 60000000 })
            .resize({ width: 320, height: 320, fit: 'inside', withoutEnlargement: true })
            .flatten({ background: '#f6f5f1' })
            .jpeg({ quality: 65 })
            .toFile(target + '.tmp');
        return;
    }
    let data = JSON.parse(input.toString('utf8'));
    let duration = (data.op - data.ip) / data.fr;
    if (
        !Number.isInteger(data.w) ||
        !Number.isInteger(data.h) ||
        data.w < 1 ||
        data.h < 1 ||
        data.w > 4096 ||
        data.h > 4096 ||
        !Number.isFinite(duration) ||
        duration <= 0 ||
        duration > 30 ||
        !Number.isFinite(data.fr) ||
        data.fr <= 0 ||
        data.fr > 120 ||
        !Array.isArray(data.layers)
    )
        throw new Error('Invalid sticker animation.');
    let scale = Math.min(1, 320 / Math.max(data.w, data.h));
    let width = Math.max(1, Math.round(data.w * scale));
    let height = Math.max(1, Math.round(data.h * scale));
    let frameCount = Math.min(Math.ceil(duration * Math.min(30, data.fr)), Math.floor(60000000 / (width * height)));
    let frameBytes = width * height * 4;
    let frames = Buffer.alloc(frameCount * frameBytes);
    DotLottie.setWasmUrl(
        'data:application/wasm;base64,' +
            readFileSync(require.resolve('@lottiefiles/dotlottie-web/dotlottie-player.wasm')).toString('base64')
    );
    let animation = new DotLottie({
        canvas: { width, height },
        data,
        autoplay: false,
        assetResolver: () => null,
        renderConfig: { autoResize: false, devicePixelRatio: 1 }
    });
    try {
        await new Promise((resolve, reject) => {
            animation.addEventListener('load', resolve);
            animation.addEventListener('loadError', reject);
        });
        for (let frame = 0; frame < frameCount; frame++) {
            animation.setFrame((frame * (animation.totalFrames - 1)) / Math.max(1, frameCount - 1));
            if (animation.buffer?.length !== frameBytes) throw new Error('Invalid rendered frame.');
            frames.set(animation.buffer, frame * frameBytes);
        }
        await sharp(frames, { raw: { width, height: height * frameCount, channels: 4, pageHeight: height } })
            .webp({ quality: 65, effort: 2, loop: 0, delay: Math.round((duration * 1000) / frameCount) })
            .toFile(target + '.webp.tmp');
        let middle = Math.floor(frameCount / 2) * frameBytes;
        await sharp(frames.subarray(middle, middle + frameBytes), { raw: { width, height, channels: 4 } })
            .flatten({ background: '#f6f5f1' })
            .jpeg({ quality: 65 })
            .toFile(target + '.tmp');
    } finally {
        animation.destroy();
    }
})().catch(() => {
    process.stderr.write('Sticker rendering failed.\n');
    process.exitCode = 1;
});
