<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthenticationTest extends TestCase
{
    use CloudFixture;

    private string $address;
    private mixed $process;
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->createCloudLibrary(
            "AUTH_USERNAME=auth-test\nAUTH_PASSWORD=isolated-test-password\nJWT_SECRET=isolated-auth-test-signing-secret\n"
        );
        foreach (['public', 'sessions'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700);
        }
        file_put_contents(
            $this->root . '/public/index.php',
            '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; if (isset($_GET["https"])) { $_SERVER["HTTPS"] = "on"; }' .
                ' (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();'
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'display_errors=0',
                '-d',
                'log_errors=1',
                '-d',
                'session.save_path=' . $this->root . '/sessions',
                '-d',
                'session.gc_maxlifetime=1',
                '-S',
                $this->address,
                '-t',
                $this->root . '/public'
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->root . '/server.log', 'a'],
                2 => ['file', $this->root . '/server.log', 'a']
            ],
            $pipes
        );
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if ($this->request()[0] === 200) {
                return;
            }
            usleep(100000);
        }
        $this->fail('Isolated authentication server did not start.');
    }

    protected function tearDown(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        $this->removeCloudLibrary();
    }

    private function request(
        string $path = '',
        ?array $post = null,
        array $requestHeaders = [],
        bool $head = false
    ): array {
        $headers = [];
        $handle = curl_init('http://' . $this->address . '/' . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_NOBODY => $head,
            CURLOPT_COOKIE => http_build_query($this->cookies, '', '; '),
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                $headers[] = trim($line);
                if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $line, $match)) {
                    $this->cookies[$match[1]] = rawurldecode($match[2]);
                }
                return strlen($line);
            }
        ]);
        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($handle);
        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, implode("\n", $headers)];
    }

    private function login(string $path = ''): array
    {
        [, $body] = $this->request();
        preg_match('/name="csrf" value="([^"]+)"/', $body, $match);
        [$status, $body] = $this->request('index.php/login', [
            'username' => 'auth-test',
            'password' => 'isolated-test-password',
            'csrf' => $match[1]
        ]);
        $this->assertSame(200, $status);
        $token = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data']['access_token'];
        $result = $this->request($path, ['action' => 'login', 'access_token' => $token, 'csrf' => $match[1]]);
        $this->assertSame(200, $result[0]);
        return $result;
    }

    public function testThumbnailConditionalRequestsRevalidateStrongEtagsWithoutResendingPreviews(): void
    {
        $this->index([$this->item('a')]);
        $this->library->oneDrive->previews([1]);
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        $cached = file_get_contents($thumbnail);
        $this->login();
        $url = '?photo=1&size=display';
        [$status, $body, $headers] = $this->request($url);
        $this->assertSame(200, $status);
        $this->assertSame($cached, $body);
        $this->assertStringContainsString('Content-Type: image/jpeg', $headers);
        $this->assertSame(1, preg_match('/^ETag: ("[a-f0-9]{64}")$/mi', $headers, $match));
        $etag = $match[1];
        foreach ([false, true] as $head) {
            foreach ([$etag, 'W/' . $etag, '"outdated", ' . $etag, '*'] as $condition) {
                [$status, $body, $headers] = $this->request(
                    $url,
                    requestHeaders: ['If-None-Match: ' . $condition],
                    head: $head
                );
                $this->assertSame(304, $status);
                $this->assertSame('', $body);
                $this->assertStringContainsString('ETag: ' . $etag, $headers);
                $this->assertStringContainsString('Cache-Control: private, no-cache', $headers);
            }
        }
        $this->assertSame(200, $this->request($url, requestHeaders: ['If-None-Match: "outdated"'])[0]);
        [$status, $body, $headers] = $this->request($url . '&download=1', requestHeaders: ['If-None-Match: ' . $etag]);
        $this->assertSame(200, $status);
        $this->assertSame($cached, $body);
        $this->assertStringContainsString('Cache-Control: no-store', $headers);
        $this->assertSame(404, $this->request('?photo=2&size=display')[0]);
        $this->assertSame(['a'], $this->client->downloads);
        $this->cookies = [];
        $this->assertSame(401, $this->request($url, requestHeaders: ['If-None-Match: *'])[0]);
    }

    public function testEmptyGalleryReportsIdleJobsWithoutStartingWork(): void
    {
        $this->login();
        for ($request = 0; $request < 2; $request++) {
            [$status, $body] = $this->request('?jobs=1');
            $this->assertSame(200, $status, file_get_contents($this->root . '/server.log'));
            $jobs = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['scan', 'previews', 'faces', 'tag'], array_keys($jobs));
            foreach ($jobs as $job) {
                $this->assertSame('idle', $job['status']);
                $this->assertSame(0, $job['total']);
                $this->assertSame(0, $job['completed']);
                $this->assertSame([], $job['log']);
            }
        }
    }

    public function testBrowserJobControlsAreRejectedInFavorOfCliAndCron(): void
    {
        $this->login();
        [$status, $body] = $this->request('?view=jobs');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('?cron=CRON_SECRET', $body);
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
        foreach (['job-start', 'job-pause', 'job-step', 'scan', 'tag'] as $action) {
            [$status, $body] = $this->request('', ['action' => $action, 'job' => 'scan', 'csrf' => $match[1]]);
            $this->assertSame(410, $status);
            $this->assertArrayHasKey('error', json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        }
        $this->assertSame(
            'idle',
            $this->library->database->query("SELECT status FROM jobs WHERE job = 'scan'")->fetchColumn()
        );
    }

    public function testCronRequiresTheConfiguredSecretAndResumesOnlyAvailableJobs(): void
    {
        $this->cookies = [];
        [$status, $body, $headers] = $this->request('?cron=' . str_repeat('a', 64));
        $this->assertSame(503, $status);
        $this->assertStringContainsString('CRON_SECRET', $body);
        $secret = bin2hex(random_bytes(32));
        file_put_contents($this->root . '/.data/.env', 'CRON_SECRET=' . $secret . "\n", FILE_APPEND);
        foreach (
            ['?cron=' . str_repeat('a', 64), '?cron=', '?cron[]=' . $secret, '?cron=' . substr($secret, 1)]
            as $url
        ) {
            [$status, $body] = $this->request($url);
            $this->assertSame(403, $status);
            $this->assertSame('', $body);
        }
        $locks = [];
        try {
            foreach (['scan', 'previews'] as $job) {
                $lock = fopen($this->root . '/.data/cli-' . $job . '.lock', 'c');
                $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
                $locks[] = $lock;
            }
            [$status, $body, $headers] = $this->request('?cron=' . $secret);
        } finally {
            foreach ($locks as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $this->assertSame(200, $status, file_get_contents($this->root . '/server.log'));
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $headers);
        $this->assertSame(
            "Galerie einlesen: läuft bereits\n" .
                "Thumbnails downloaden: läuft bereits\n" .
                "Gesichtertagging: übersprungen (Gesichtserkennung nicht installiert)\n" .
                "KI-Bewertung: übersprungen (KI nicht konfiguriert)\n",
            $body
        );
        $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', $headers);
        $this->assertSame([], $this->cookies);
        foreach ($this->library->jobs->all() as $state) {
            $this->assertSame('idle', $state['status']);
        }
    }

    public function testYearCookieSurvivesLostServerSessionAndBrowserSessionCookie(): void
    {
        [, , $headers] = $this->login('?https=1');
        $this->assertMatchesRegularExpression(
            '/Set-Cookie: photobutler_remember=[a-f0-9]{64}; expires=[^;]+; Max-Age=31536000; path=\/; secure; HttpOnly; SameSite=Strict/i',
            $headers
        );
        $remember = $this->cookies['photobutler_remember'];
        $row = $this->library->database->query('SELECT token_hash, expires FROM auth_tokens')->fetch();
        $this->assertNotSame($remember, $row['token_hash']);
        $this->assertEqualsWithDelta(time() + 31536000, $row['expires'], 2);
        foreach (glob($this->root . '/sessions/sess_*') as $session) {
            unlink($session);
        }
        $this->assertSame(200, $this->request('?jobs=1')[0]);
        unset($this->cookies['photobutler']);
        $this->assertSame(200, $this->request('?jobs=1')[0]);
        $this->assertArrayHasKey('photobutler', $this->cookies);
        $this->assertSame($remember, $this->cookies['photobutler_remember']);
        $this->assertSame(
            $row,
            $this->library->database->query('SELECT token_hash, expires FROM auth_tokens')->fetch()
        );
    }

    public function testInvalidAndExpiredCookiesNeverFallBackToAuthenticatedSession(): void
    {
        $this->login();
        $remember = $this->cookies['photobutler_remember'];
        foreach (
            ['', 'invalid', str_repeat('0', 64), substr($remember, 0, 63) . ($remember[63] === 'a' ? 'b' : 'a')]
            as $token
        ) {
            $this->cookies['photobutler_remember'] = $token;
            $this->assertSame(401, $this->request('?jobs=1')[0]);
        }
        $this->cookies['photobutler_remember'] = $remember;
        $this->assertSame(200, $this->request('?jobs=1')[0]);
        $this->library->database->exec('UPDATE auth_tokens SET expires = ' . (time() - 1));
        $this->assertSame(401, $this->request('?jobs=1')[0]);
    }

    public function testLogoutRevokesCopiedCookieAndSession(): void
    {
        $this->login();
        $copiedCookies = $this->cookies;
        [, $body] = $this->request();
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
        $this->assertSame(403, $this->request('', ['action' => 'logout', 'csrf' => 'invalid'])[0]);
        $this->assertSame(200, $this->request('?jobs=1')[0]);
        [$status, , $headers] = $this->request('', ['action' => 'logout', 'csrf' => $match[1]]);
        $this->assertSame(303, $status);
        $this->assertStringContainsString('photobutler_remember=deleted;', $headers);
        $this->assertStringContainsString('photobutler=deleted;', $headers);
        $this->assertSame(0, $this->library->database->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn());
        $this->cookies = $copiedCookies;
        $this->assertSame(401, $this->request('?jobs=1')[0]);
    }

    public function testCredentialChangesRevokeRememberCookie(): void
    {
        $this->login();
        $settings = file_get_contents($this->root . '/.data/.env');
        foreach (
            [
                'AUTH_USERNAME=auth-test',
                'AUTH_PASSWORD=isolated-test-password',
                'JWT_SECRET=isolated-auth-test-signing-secret'
            ]
            as $setting
        ) {
            file_put_contents($this->root . '/.data/.env', str_replace($setting, $setting . '-changed', $settings));
            $this->assertSame(401, $this->request('?jobs=1')[0]);
        }
    }
}
