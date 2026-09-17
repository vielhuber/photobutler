let sharp = require('sharp');
let { createInterface } = require('node:readline');

sharp.concurrency(1);
sharp.cache(false);

(async () => {
    for await (let line of createInterface({ input: process.stdin })) {
        try {
            let { source, target, edge, quality } = JSON.parse(line);
            await sharp(source, { limitInputPixels: 120000000 })
                .autoOrient()
                .resize({
                    width: edge,
                    height: edge,
                    fit: 'inside',
                    withoutEnlargement: true,
                    fastShrinkOnLoad: true,
                    kernel: 'linear'
                })
                .flatten({ background: '#f6f5f1' })
                .jpeg({ quality })
                .toFile(target + '.tmp');
            process.stdout.write('ok\n');
        } catch {
            process.stdout.write('error\n');
        }
    }
})().catch(() => {
    process.exitCode = 1;
});
