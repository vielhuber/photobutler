let fs = require('node:fs');
let http = require('node:http');
let https = require('node:https');
let directory = process.argv[2];
let configuration = JSON.parse(fs.readFileSync(directory + '/configuration'));
let active = 0;
let maximum = 0;
let attempts = {};
let upstream = https.createServer(
    { key: fs.readFileSync(directory + '/key.pem'), cert: fs.readFileSync(directory + '/certificate.pem') },
    (request, response) => {
        let body = '';
        request.on('data', chunk => (body += chunk));
        request.on('end', () => {
            if (request.url === '/v1.0/$batch') {
                let batch = JSON.parse(body);
                fs.appendFileSync(directory + '/batches', JSON.stringify(batch) + '\n');
                if (configuration.mode === 'graph-stall') {
                    fs.writeFileSync(directory + '/ready', '');
                    return;
                }
                response.setHeader('Content-Type', 'application/json');
                response.end(
                    JSON.stringify({
                        responses: batch.requests
                            .map(entry => {
                                let [id, kind] = entry.id.split('-');
                                return {
                                    id: entry.id,
                                    status: 200,
                                    body:
                                        kind === 'metadata'
                                            ? { size: 3000000000, cTag: 'v1' }
                                            : { value: [{ large: { url: 'https://127.0.0.1/preview/' + id } }] }
                                };
                            })
                            .reverse()
                    })
                );
                return;
            }
            let id = Number(request.url.split('/').pop());
            fs.appendFileSync(
                directory + '/requests',
                JSON.stringify({
                    id,
                    authorization: request.headers.authorization,
                    port: request.socket.remotePort,
                    time: Date.now()
                }) + '\n'
            );
            attempts[id] = (attempts[id] || 0) + 1;
            active++;
            maximum = Math.max(maximum, active);
            fs.writeFileSync(directory + '/maximum', String(maximum));
            let released = false;
            let finish = () => {
                if (released) {
                    return;
                }
                released = true;
                active--;
            };
            response.once('close', finish);
            let send = body => {
                // socket finish events can arrive after the client starts its next wave
                finish();
                response.end(body);
            };
            if (configuration.mode === 'download-stall' && id > 4) {
                response.writeHead(200, { 'Content-Type': 'image/jpeg' });
                response.write(fs.readFileSync(directory + '/preview').subarray(0, 50));
                fs.writeFileSync(directory + '/ready', '');
                return;
            }
            if (configuration.mode === 'backoff') {
                response.writeHead(429, { 'Retry-After': '30' });
                send();
                fs.writeFileSync(directory + '/ready', '');
                return;
            }
            if (
                id === 2 &&
                ((configuration.mode === 'retry-long' && attempts[id] <= 4) || configuration.mode === 'retry-exhausted')
            ) {
                response.writeHead(configuration.mode === 'retry-long' ? 503 : 504, { 'Retry-After': '1' });
                send();
                return;
            }
            if (configuration.mode === 'retry' && id === 2 && attempts[id] === 1) {
                response.writeHead(429, { 'Retry-After': '1' });
                send();
                return;
            }
            if (configuration.mode === 'invalid' && id === 2) {
                send('not an image');
                return;
            }
            if (configuration.mode === 'oversized' && id === 2) {
                send(Buffer.alloc(16777217));
                return;
            }
            setTimeout(() => send(fs.readFileSync(directory + '/preview')), 100);
        });
    }
);
upstream.keepAliveTimeout = 60000;
let proxy = http.createServer();
proxy.on('connect', (request, socket) => {
    socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
    upstream.emit('connection', socket);
});
proxy.listen(0, '127.0.0.1', () => fs.writeFileSync(directory + '/port', String(proxy.address().port)));
