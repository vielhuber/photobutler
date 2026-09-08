<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class PhotoButlerTest extends TestCase
{
    private string $root;
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/photos/Urlaub', 0700, true);
        mkdir($this->root . '/.data', 0700);
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" .
                json_encode([$this->root . '/photos']) .
                "'\nAUTH_USERNAME=test-user\nAUTH_PASSWORD=test-password\nJWT_SECRET=photobutler-test-signing-secret-32-bytes\n"
        );
        $image = imagecreatetruecolor(80, 60);
        imagejpeg($image, $this->root . '/photos/Urlaub/Meer.jpg');
        $this->library = new PhotoButler($this->root);
    }

    protected function tearDown(): void
    {
        unset($this->library);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testIndexIsIdempotentAndKeepsOriginals(): void
    {
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        $hash = hash_file('sha256', $path);
        $this->library->index();
        $this->library->index();
        $photos = $this->library->photos();
        $this->assertCount(1, $photos);
        $this->assertSame('Urlaub', $photos[0]->album);
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertFileExists($this->library->imagePath($photos[0]->id));
    }

    public function testSearchTagsAndFavorites(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, ' Küste, Meer, Meer ');
        $this->library->favorite($id, true);
        $this->assertCount(1, $this->library->photos(query: 'KÜSTE', favorites: true));
        $this->assertCount(1, $this->library->photos(tag: 'Meer'));
        $this->assertCount(0, $this->library->photos(query: '%'));
        $this->assertSame(['Küste', 'Meer'], $this->library->photo($id)->tags);
    }

    public function testMissingAndEscapingFilesAreNotServed(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        rename($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/outside.jpg');
        symlink($this->root . '/outside.jpg', $this->root . '/photos/Urlaub/Meer.jpg');
        $this->assertNull($this->library->imagePath($id, original: true));
        $this->assertNull($this->library->imagePath($id));
        $this->library->index();
        $this->assertCount(0, $this->library->photos());
    }

    public function testChangedFileIsQueuedAgainWithoutLosingManualTags(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, 'Familie');
        $this->library->favorite($id, true);
        touch($this->root . '/photos/Urlaub/Meer.jpg', time() + 10);
        $this->library->index();
        $this->assertSame(['Familie'], $this->library->photo($id)->tags);
        $this->assertTrue($this->library->photo($id)->favorite);
        $this->assertSame('pending', $this->library->photo($id)->status);
    }

    public function testAiResultRejectsMalformedAndOversizedTags(): void
    {
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '```json' . "\n" . '{"description":"Ein Strand.","tags":["Meer","Meer"," Strand "]}' . "\n```"
        );
        $this->assertSame(['Meer', 'Strand'], $result->tags);
        $this->expectException(UnexpectedValueException::class);
        new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '{"description":"x","tags":[{"bad":true}]}'
        );
    }

    public function testUnavailableRootPreservesIndex(): void
    {
        $this->library->index();
        rename($this->root . '/photos', $this->root . '/offline');
        try {
            $this->library->index();
            $this->fail('Expected an unavailable source to abort indexing.');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->library->photos());
        }
    }

    public function testAihelperDecodedJsonResponseIsAccepted(): void
    {
        $response = json_decode('{"description":"Ein Strand.","tags":["Meer","Strand"]}');
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke($this->library, $response);
        $this->assertSame('Ein Strand.', $result->description);
        $this->assertSame(['Meer', 'Strand'], $result->tags);
    }

    public function testLimitedScanDoesNotHideUnvisitedPhotos(): void
    {
        $this->library->index();
        copy($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Berge.jpg');
        copy($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Wald.jpg');
        $this->assertSame(1, $this->library->index(limit: 1));
        $this->assertCount(2, $this->library->photos());
        $this->library->index();
        $this->assertCount(3, $this->library->photos());
    }

    public function testScanDiscoversAlbumsWithoutOpeningImageContents(): void
    {
        for ($number = 0; $number < 12; $number++) {
            mkdir($this->root . '/photos/album-' . $number);
            file_put_contents($this->root . '/photos/album-' . $number . '/photo.jpg', 'not downloaded');
        }
        $this->assertSame(13, $this->library->index());
        $this->assertSame(
            13,
            (int) $this->library->database->query('SELECT COUNT(DISTINCT album) FROM photos')->fetchColumn()
        );
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.jpg'));
    }

    public function testScanResumesAcrossInstancesAndFinishesUnchangedBatches(): void
    {
        for ($number = 0; $number < 5; $number++) {
            copy($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/photo-' . $number . '.jpg');
        }
        $this->assertSame(2, $this->library->index(limit: 2));
        $this->library = new PhotoButler($this->root);
        $this->assertSame(2, $this->library->index(limit: 2));
        $this->library->index();
        $this->assertCount(6, $this->library->photos());
        unlink($this->root . '/photos/Urlaub/photo-4.jpg');
        $this->assertSame(0, $this->library->index(limit: 2));
        $this->assertCount(6, $this->library->photos());
        $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM scan_state')->fetchColumn());
        $this->library->index();
        $this->assertCount(5, $this->library->photos());
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM scan_state')->fetchColumn());
    }

    public function testMissingThumbnailPreservesAiTagsAndIsRebuiltOnDemand(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $thumbnail = $this->library->imagePath($id);
        $this->library->database->exec("UPDATE photos SET status = 'done', ai_tags = '[\"Meer\"]'");
        unlink($thumbnail);
        $this->assertSame(0, $this->library->index());
        $this->assertSame('done', $this->library->photo($id)->status);
        $this->assertSame(['Meer'], $this->library->photo($id)->tags);
        $this->assertFileExists($this->library->imagePath($id));
    }

    public function testUnreadablePreviewIsDeferredWithoutBlockingOtherPhotos(): void
    {
        file_put_contents($this->root . '/photos/Urlaub/Meer.jpg', 'unavailable image contents');
        imagejpeg(imagecreatetruecolor(80, 60), $this->root . '/photos/Urlaub/Zweiter.jpg');
        file_put_contents(
            $this->root . '/.data/.env',
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=test\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=test-only\n",
            FILE_APPEND
        );
        $this->library = new PhotoButler($this->root);
        $this->library->index();
        $this->assertSame(0, $this->library->tag(limit: 1));
        $this->assertSame('error', $this->library->photo(1)->status);
        $this->assertSame('pending', $this->library->photo(2)->status);
        $stats = new ReflectionMethod(PhotoButler::class, 'photoStats')->invoke($this->library);
        $this->assertSame(1, $stats['queued']);
    }

    public function testOversizedManualTagsAreRejectedWithoutReplacingExistingTags(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, 'Meer');
        try {
            $this->library->saveTags($id, str_repeat('a', 61));
            $this->fail('Expected oversized tags to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(['Meer'], $this->library->photo($id)->tags);
        }
    }

    public function testExifTransposeAndMetadataStripping(): void
    {
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        $image = imagecreatetruecolor(80, 60);
        imagefilledrectangle($image, 0, 0, 39, 29, imagecolorallocate($image, 240, 0, 0));
        imagejpeg($image, $path, 100);
        $jpeg = file_get_contents($path);
        $exif = "Exif\0\0II" . pack('vVv', 42, 8, 1) . pack('vvVv', 0x0112, 3, 1, 5) . "\0\0" . pack('V', 0);
        file_put_contents(
            $path,
            substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2)
        );
        $this->assertSame(5, exif_read_data($path)['Orientation']);
        $this->library->index();
        $photo = $this->library->photos()[0];
        $thumbnail = $this->library->imagePath($photo->id);
        $photo = $this->library->photo($photo->id);
        $this->assertSame(60, $photo->width);
        $this->assertSame(80, $photo->height);
        $preview = imagecreatefromjpeg($thumbnail);
        $color = imagecolorsforindex($preview, imagecolorat($preview, 5, 5));
        $this->assertGreaterThan(200, $color['red']);
        $this->assertArrayNotHasKey('Orientation', exif_read_data($thumbnail));
    }

    public function testInitializerPreservesIndentedCredentials(): void
    {
        $envPath = $this->root . '/.data/.env';
        $env = str_replace('AUTH_USERNAME=test-user', '  AUTH_USERNAME = test-user', file_get_contents($envPath));
        file_put_contents($envPath, $env);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-init'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->root . '/init.log', 'a'],
                2 => ['file', $this->root . '/init.log', 'a']
            ],
            $pipes,
            $this->root
        );
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame($env, file_get_contents($envPath));
    }

    public function testConfigurationUsesDotenvQuotingAndComments(): void
    {
        file_put_contents(
            $this->root . '/.data/.env',
            "AUTH_USERNAME=test-user # account\nAUTH_PASSWORD='spaces # and " . '$' . "igns'\n"
        );
        $configuration = new PhotoButler($this->root);
        $this->assertSame('test-user', $configuration->getSetting('AUTH_USERNAME'));
        $this->assertSame('spaces # and ' . '$' . 'igns', $configuration->getSetting('AUTH_PASSWORD'));
    }

    public function testInitializerMigratesOnlyUnmodifiedLegacyEntryPoints(): void
    {
        mkdir($this->root . '/public');
        $entry = file_get_contents(dirname(__DIR__) . '/public/index.php');
        $legacy = str_replace(
            'new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__))->run();',
            'new \\vielhuber\\photobutler\\WebApp(new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();',
            $entry
        );
        $custom = $legacy . "\n// Custom deployment settings.\n";
        foreach ([$legacy, $custom, $entry] as $existing) {
            file_put_contents($this->root . '/public/index.php', $existing);
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-init'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $this->root . '/init.log', 'a'],
                    2 => ['file', $this->root . '/init.log', 'a']
                ],
                $pipes,
                $this->root
            );
            fclose($pipes[0]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame(
                $existing === $custom ? $custom : $entry,
                file_get_contents($this->root . '/public/index.php')
            );
        }
    }

    public function testInvalidConfigurationDoesNotExposeItsContents(): void
    {
        file_put_contents($this->root . '/.data/.env', 'AUTH_PASSWORD=private-test-value invalid space');
        try {
            new PhotoButler($this->root);
            $this->fail('Expected malformed dotenv syntax to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-test-value', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testWebAuthenticationCsrfAndPhotoAccess(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        mkdir($this->root . '/public');
        file_put_contents(
            $this->root . '/public/index.php',
            '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();'
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $this->root . '/public'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->root . '/server.log', 'a'],
                2 => ['file', $this->root . '/server.log', 'a']
            ],
            $pipes
        );
        fclose($pipes[0]);
        $accessToken = '';
        $request = function (string $path, ?array $post = null) use ($address, &$accessToken): array {
            $handle = curl_init('http://' . $address . '/' . $path);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEJAR => $this->root . '/cookies',
                CURLOPT_COOKIEFILE => $this->root . '/cookies',
                CURLOPT_TIMEOUT => 5
            ]);
            if ($accessToken !== '') {
                curl_setopt($handle, CURLOPT_COOKIE, 'access_token=' . $accessToken);
            }
            if ($post !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $body = curl_exec($handle);
            return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body];
        };
        try {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                [$status, $body] = $request('');
                if ($status === 200) {
                    break;
                }
                usleep(100000);
            }
            $this->assertSame(200, $status);
            preg_match('/name="csrf" value="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            foreach (['scan', 'tag'] as $action) {
                $this->assertSame(401, $request('', ['action' => $action, 'csrf' => $csrf])[0]);
            }
            $this->assertSame(401, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(404, $request('.data/.env')[0]);
            $this->assertSame(404, $request('vendor/autoload.php')[0]);
            $this->assertSame(
                403,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'test-password',
                    'csrf' => 'wrong'
                ])[0]
            );
            $this->assertSame(
                401,
                $request('index.php/login', ['username' => 'test-user', 'password' => 'wrong', 'csrf' => $csrf])[0]
            );
            $passwordHash = $this->library->database->query('SELECT password FROM users')->fetchColumn();
            $this->assertSame(
                401,
                $request('index.php/login', [
                    'username' => 'wrong-user',
                    'password' => 'test-password',
                    'csrf' => $csrf
                ])[0]
            );
            $this->assertSame(
                $passwordHash,
                $this->library->database->query('SELECT password FROM users')->fetchColumn()
            );
            [$status, $body] = $request('index.php/login', [
                'username' => 'test-user',
                'password' => 'test-password',
                'csrf' => $csrf
            ]);
            $this->assertSame(200, $status);
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['success']);
            $accessToken = $result['data']['access_token'];
            $this->assertSame(401, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(
                401,
                $request('', ['action' => 'login', 'access_token' => 'invalid', 'csrf' => $csrf])[0]
            );
            $this->assertSame(
                200,
                $request('', ['action' => 'login', 'access_token' => $accessToken, 'csrf' => $csrf])[0]
            );
            $accessToken = '';
            $this->assertSame(403, $request('', ['action' => 'logout', 'csrf' => $csrf])[0]);
            $this->assertStringContainsString('#HttpOnly_', file_get_contents($this->root . '/cookies'));
            [$status, $body] = $request('');
            $this->assertSame(200, $status);
            $this->assertStringContainsString('data-photo="' . $id . '"', $body);
            preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            foreach (['scan', 'tag'] as $action) {
                $this->assertSame(403, $request('', ['action' => $action, 'csrf' => 'wrong'])[0]);
            }
            [$status, $body] = $request('', ['action' => 'tag', 'csrf' => $csrf]);
            $this->assertSame(503, $status);
            $this->assertArrayHasKey('error', json_decode($body, true, flags: JSON_THROW_ON_ERROR));
            for ($number = 0; $number < 11; $number++) {
                copy($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/extra-' . $number . '.jpg');
            }
            foreach ([11, 0] as $expected) {
                [$status, $body] = $request('', ['action' => 'scan', 'csrf' => $csrf]);
                $this->assertSame(200, $status);
                $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $result['processed']);
            }
            $this->assertSame(12, $result['stats']['total']);
            $this->assertSame(12, $result['stats']['queued']);
            $lock = fopen($this->root . '/.data/index.lock', 'c');
            flock($lock, LOCK_EX);
            try {
                $this->assertSame(409, $request('', ['action' => 'scan', 'csrf' => $csrf])[0]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $this->assertSame(200, $request('?photo=' . $id . '&size=thumb')[0]);
            [$status, $body] = $request('?detail=' . $id);
            $this->assertSame(200, $status);
            $detail = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($id, $detail['id']);
            $this->assertSame(
                ['id', 'name', 'album', 'taken', 'description', 'tags', 'favorite', 'status', 'width', 'height'],
                array_keys($detail)
            );
            $this->assertSame(404, $request('?photo=999999&size=original')[0]);
            $this->assertSame(403, $request('', ['action' => 'favorite', 'id' => $id, 'favorite' => '1'])[0]);
            [$status, $body] = $request('', ['action' => 'favorite', 'id' => $id, 'favorite' => '1', 'csrf' => $csrf]);
            $this->assertSame(200, $status);
            $this->assertTrue(json_decode($body, true)['favorite']);
            $this->assertSame(
                200,
                $request('', [
                    'action' => 'tags',
                    'id' => $id,
                    'tags' => '<script>alert(1)</script>',
                    'csrf' => $csrf
                ])[0]
            );
            $this->assertStringNotContainsString('<script>alert(1)</script>', $request('')[1]);
            file_put_contents(
                $this->root . '/.data/.env',
                str_replace(
                    'AUTH_PASSWORD=test-password',
                    'AUTH_PASSWORD=updated-password',
                    file_get_contents($this->root . '/.data/.env')
                )
            );
            $this->assertSame(401, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(
                401,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'test-password',
                    'csrf' => $csrf
                ])[0]
            );
            [$status, $body] = $request('index.php/login', [
                'username' => 'test-user',
                'password' => 'updated-password',
                'csrf' => $csrf
            ]);
            $this->assertSame(200, $status);
            $token = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data']['access_token'];
            $this->assertSame(200, $request('', ['action' => 'login', 'access_token' => $token, 'csrf' => $csrf])[0]);
            [, $body] = $request('');
            preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            $this->assertSame(200, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(303, $request('', ['action' => 'logout', 'csrf' => $csrf])[0]);
            $accessToken = '';
            $this->assertSame(401, $request('?photo=' . $id . '&size=original')[0]);
            [, $body] = $request('');
            preg_match('/name="csrf" value="([^"]+)"/', $body, $match);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->assertSame(
                    401,
                    $request('index.php/login', [
                        'username' => 'test-user',
                        'password' => 'wrong',
                        'csrf' => $match[1]
                    ])[0]
                );
            }
            $this->assertSame(
                429,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'updated-password',
                    'csrf' => $match[1]
                ])[0]
            );
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
