<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class AuthenticationTest extends TestCase
{
    private string $root;
    private string $address;
    private mixed $process;
    private array $cookies = [];
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-auth-' . bin2hex(random_bytes(8));
        foreach (['.data', 'public', 'sessions'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
        file_put_contents(
            $this->root . '/.data/.env',
            "AUTH_USERNAME=auth-test\nAUTH_PASSWORD=isolated-test-password\nJWT_SECRET=isolated-auth-test-signing-secret\nPHOTO_PATHS='[]'\n"
        );
        file_put_contents(
            $this->root . '/public/index.php',
            '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; if (isset($_GET["https"])) { $_SERVER["HTTPS"] = "on"; }' .
                ' (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();'
        );
        $this->library = new PhotoButler($this->root);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->process = proc_open(
            [
                PHP_BINARY,
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
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function request(string $path = '', ?array $post = null): array
    {
        $headers = [];
        $handle = curl_init('http://' . $this->address . '/' . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
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

    public function testAuthenticatedPreviewBatchCanSaveConcurrentWorkerResults(): void
    {
        mkdir($this->root . '/photos');
        $config = file_get_contents($this->root . '/.data/.env');
        file_put_contents(
            $this->root . '/.data/.env',
            str_replace("PHOTO_PATHS='[]'", "PHOTO_PATHS='" . json_encode([$this->root . '/photos']) . "'", $config)
        );
        $image = imagecreatetruecolor(80, 60);
        imagejpeg($image, $this->root . '/photos/first.jpg');
        imagejpeg($image, $this->root . '/photos/second.jpg');
        file_put_contents($this->root . '/photos/second.jpg', 'second', FILE_APPEND);
        $library = new PhotoButler($this->root);
        $library->index();
        $this->login();
        [, $body] = $this->request('?view=jobs');
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
        $parameters = ['job' => 'previews', 'csrf' => $match[1]];
        [$status, $body] = $this->request('', ['action' => 'job-start'] + $parameters);
        $this->assertSame(200, $status);
        $state = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        [$status, $body] = $this->request('', ['action' => 'job-step', 'token' => $state['token']] + $parameters);
        $this->assertSame(200, $status, $body);
        $state = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('done', $state['status']);
        $this->assertSame(2, $state['completed']);
        $this->assertSame(0, $state['errors']);
        $this->assertCount(2, glob($this->root . '/.data/thumbnails/*'));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.detail*'));
        [$status, $body] = $this->request('?jobs=1');
        $this->assertSame(200, $status);
        $this->assertSame(100, json_decode($body, true, flags: JSON_THROW_ON_ERROR)['previews']['percent']);
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
