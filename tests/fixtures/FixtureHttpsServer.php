<?php
declare(strict_types=1);

final class FixtureConnection
{
    public string $phase;
    public string $input = '';
    public string $output = '';
    public bool $continued = false;
    public bool $closeAfterWrite = false;
    /** @var list<callable> */
    public array $pending = [];

    public function __construct(public readonly mixed $socket, public readonly int $port, bool $proxy)
    {
        $this->phase = $proxy ? 'connect' : 'handshake';
    }
}

/**
 * Single-threaded HTTPS fixture server, optionally reached through an HTTP CONNECT proxy on the same port.
 */
final class FixtureHttpsServer
{
    public int $port = 0;
    /** @var array<int, FixtureConnection> */
    private array $connections = [];
    /** @var list<array{at: float, connection: int, response: array}> */
    private array $timers = [];

    /**
     * @param list<string> $names subjectAltName entries such as "DNS:graph.microsoft.com" or "IP:127.0.0.1"
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $proxy,
        private readonly string $commonName,
        private readonly array $names
    ) {}

    /**
     * Serve until killed; the handler returns a response array or null to never answer.
     *
     * Response keys: status, headers (list of "Name: value"), body, delay (seconds), stall (send only the body
     * prefix and never finish), sent (callable invoked once when sending starts or the connection closes).
     */
    public function run(callable $handler): never
    {
        $this->createCertificate();
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $this->directory . '/certificate.pem',
                'local_pk' => $this->directory . '/key.pem',
                'verify_peer' => false
            ]
        ]);
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($server === false) {
            throw new RuntimeException('Fixture server could not listen: ' . $errorMessage);
        }
        stream_set_blocking($server, false);
        $this->port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        file_put_contents($this->directory . '/port', (string) $this->port);
        while (true) {
            $read = [
                $server,
                ...array_map(static fn(FixtureConnection $connection) => $connection->socket, $this->connections)
            ];
            $write = array_values(
                array_map(
                    static fn(FixtureConnection $connection) => $connection->socket,
                    array_filter(
                        $this->connections,
                        static fn(FixtureConnection $connection): bool => $connection->output !== ''
                    )
                )
            );
            $except = null;
            $timeout = $this->timers === [] ? 1 : max(0, min(array_column($this->timers, 'at')) - microtime(true));
            if (stream_select($read, $write, $except, (int) $timeout, (int) (fmod($timeout, 1) * 1000000)) === false) {
                continue;
            }
            foreach ($read as $socket) {
                if ($socket === $server) {
                    $client = stream_socket_accept($server, 0, $peer);
                    if ($client !== false) {
                        stream_set_blocking($client, false);
                        stream_set_read_buffer($client, 0);
                        $this->connections[get_resource_id($client)] = new FixtureConnection(
                            $client,
                            (int) substr(strrchr($peer, ':'), 1),
                            $this->proxy
                        );
                    }
                    continue;
                }
                $this->receive(get_resource_id($socket), $handler);
            }
            foreach ($write as $socket) {
                $this->flush(get_resource_id($socket));
            }
            foreach ($this->timers as $index => $timer) {
                if ($timer['at'] <= microtime(true)) {
                    unset($this->timers[$index]);
                    $this->send($timer['connection'], $timer['response']);
                }
            }
            $this->timers = array_values($this->timers);
        }
    }

    private function createCertificate(): void
    {
        $configuration = $this->directory . '/openssl.cnf';
        file_put_contents(
            $configuration,
            "[req]\ndistinguished_name = name\nx509_extensions = extensions\nprompt = no\n[name]\nCN = " .
                $this->commonName .
                "\n[extensions]\nbasicConstraints = critical, CA:TRUE\nsubjectAltName = " .
                implode(',', $this->names) .
                "\n"
        );
        $options = ['config' => $configuration, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, ...$options]);
        $request = openssl_csr_new(['commonName' => $this->commonName], $key, $options);
        $certificate = openssl_csr_sign(
            $request,
            null,
            $key,
            1,
            [...$options, 'x509_extensions' => 'extensions'],
            random_int(1, PHP_INT_MAX)
        );
        openssl_x509_export_to_file($certificate, $this->directory . '/certificate.pem');
        openssl_pkey_export_to_file($key, $this->directory . '/key.pem', null, $options);
    }

    private function receive(int $id, callable $handler): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        if ($connection->phase === 'handshake') {
            $result = stream_socket_enable_crypto($connection->socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($result === false) {
                $this->close($id);
            }
            if ($result !== true) {
                return;
            }
            $connection->phase = 'http';
        }
        $data = '';
        while (($chunk = fread($connection->socket, 1048576)) !== false && $chunk !== '') {
            $data .= $chunk;
        }
        if ($data === '' && feof($connection->socket)) {
            $this->close($id);
            return;
        }
        $connection->input .= $data;
        if ($connection->phase === 'connect') {
            if (str_contains($connection->input, "\r\n\r\n")) {
                $connection->input = '';
                fwrite($connection->socket, "HTTP/1.1 200 Connection Established\r\n\r\n");
                $connection->phase = 'handshake';
            }
            return;
        }
        while (($end = strpos($connection->input, "\r\n\r\n")) !== false) {
            $lines = explode("\r\n", substr($connection->input, 0, $end));
            [$method, $url] = explode(' ', array_shift($lines));
            $headers = [];
            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            $length = (int) ($headers['content-length'] ?? 0);
            if (strlen($connection->input) < $end + 4 + $length) {
                if (strtolower($headers['expect'] ?? '') === '100-continue' && !$connection->continued) {
                    $connection->continued = true;
                    $connection->output .= "HTTP/1.1 100 Continue\r\n\r\n";
                }
                return;
            }
            $body = substr($connection->input, $end + 4, $length);
            $connection->input = (string) substr($connection->input, $end + 4 + $length);
            $connection->continued = false;
            $connection->closeAfterWrite = strtolower($headers['connection'] ?? '') === 'close';
            $response = $handler([
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'port' => $connection->port
            ]);
            if ($response === null) {
                continue;
            }
            $response['method'] = $method;
            if (isset($response['sent'])) {
                $connection->pending[] = $response['sent'];
            }
            if (($response['delay'] ?? 0) > 0) {
                $this->timers[] = [
                    'at' => microtime(true) + $response['delay'],
                    'connection' => $id,
                    'response' => $response
                ];
                continue;
            }
            $this->send($id, $response);
        }
    }

    private function send(int $id, array $response): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        foreach ($connection->pending as $sent) {
            $sent();
        }
        $connection->pending = [];
        $body = $response['body'] ?? '';
        $length = strlen($body);
        if ($response['stall'] ?? false) {
            $length = (int) $response['length'];
        }
        $connection->output .=
            'HTTP/1.1 ' .
            ($response['status'] ?? 200) .
            " Fixture\r\n" .
            implode('', array_map(static fn(string $header): string => $header . "\r\n", $response['headers'] ?? [])) .
            'Content-Length: ' .
            $length .
            "\r\n\r\n" .
            ($response['method'] === 'HEAD' ? '' : $body);
        $this->flush($id);
    }

    private function flush(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null || $connection->output === '') {
            return;
        }
        $written = fwrite($connection->socket, $connection->output);
        if ($written === false) {
            $this->close($id);
            return;
        }
        $connection->output = substr($connection->output, $written);
        if ($connection->output === '' && $connection->closeAfterWrite) {
            $this->close($id);
        }
    }

    private function close(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        foreach ($connection->pending as $sent) {
            $sent();
        }
        fclose($connection->socket);
        unset($this->connections[$id]);
        $this->timers = array_values(
            array_filter($this->timers, static fn(array $timer): bool => $timer['connection'] !== $id)
        );
    }
}
