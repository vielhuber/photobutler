let fs = require('node:fs');
let path = require('node:path');

try {
    let { roots, known, extensions } = JSON.parse(fs.readFileSync(0, 'utf8'));
    let indexed = known === null ? null : new Set(known);
    let pending = indexed ? [...new Set(known.map(file => path.dirname(file)))] : [...roots];
    let visited = new Set();
    let files = new Set();
    while (pending.length) {
        let directory = pending.pop();
        if (visited.has(directory)) continue;
        visited.add(directory);
        let entries;
        try {
            if (fs.realpathSync.native(directory) !== directory) continue;
            // Directory entry types avoid one remote filesystem stat per indexed photo.
            entries = fs.readdirSync(directory, { withFileTypes: true });
        } catch (error) {
            if (indexed && ['ENOENT', 'ENOTDIR'].includes(error.code)) continue;
            throw error;
        }
        for (let entry of entries) {
            let file = path.join(directory, entry.name);
            if (!indexed && entry.isDirectory()) pending.push(file);
            if (!entry.isFile() || !extensions.includes(path.extname(file).slice(1).toLowerCase())) continue;
            if (!indexed || indexed.has(file)) files.add(file);
        }
    }
    process.stdout.write(JSON.stringify([...files]));
} catch {
    process.exitCode = 1;
}
