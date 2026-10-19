<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class PinterestClient
{
    private const API = 'https://api.pinterest.com/v5';
    private const TOKEN_EXPIRY_MARGIN = 60;

    /**
     * Prefer renewable app credentials while retaining support for a supplied token.
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly string $dataPath = '',
        private readonly string $appId = '',
        private readonly string $appSecret = ''
    ) {}

    /**
     * Keep bearer credentials on the fixed API origin and never persist returned content.
     *
     * @param array<string, int|string|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $accessToken = $this->token();
        try {
            return $this->request($path, query: $query, authorization: 'Bearer ' . $accessToken);
        } catch (PinterestException $exception) {
            if ($this->appId === '' || $exception->providerStatus !== 401) {
                throw $exception;
            }
            return $this->request($path, query: $query, authorization: 'Bearer ' . $this->token($accessToken));
        }
    }

    /**
     * Replace a rejected token only if another request has not already renewed it.
     */
    private function token(?string $rejectedToken = null): string
    {
        $accessToken = $this->accessToken;
        if ($this->appId !== '' || $this->appSecret !== '') {
            if ($this->appId === '' || $this->appSecret === '' || $this->dataPath === '') {
                throw PinterestException::notConfigured();
            }
            $tokenPath = $this->dataPath . '/pinterest-token.json';
            $oldMask = umask(0077);
            $lock = fopen($this->dataPath . '/pinterest-token.lock', 'c');
            $temporary = $tokenPath . '.' . bin2hex(random_bytes(8));
            try {
                if ($lock === false || !flock($lock, LOCK_EX)) {
                    throw PinterestException::storageUnavailable();
                }
                $contents = is_file($tokenPath) ? file_get_contents($tokenPath) : '{}';
                if (!is_string($contents)) {
                    throw PinterestException::storageUnavailable();
                }
                try {
                    $token = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw PinterestException::storageUnavailable();
                }
                if (!is_array($token)) {
                    throw PinterestException::storageUnavailable();
                }
                $registration = hash('sha256', $this->appId . ':' . $this->appSecret);
                if (
                    ($token['registration'] ?? '') !== $registration ||
                    ($token['expires_at'] ?? 0) <= time() + self::TOKEN_EXPIRY_MARGIN ||
                    !is_string($token['access_token'] ?? null) ||
                    $token['access_token'] === '' ||
                    $token['access_token'] === $rejectedToken
                ) {
                    $response = $this->request(
                        '/oauth/token',
                        query: ['grant_type' => 'client_credentials', 'scope' => 'boards:read,pins:read'],
                        authorization: 'Basic ' . base64_encode($this->appId . ':' . $this->appSecret),
                        post: true
                    );
                    if (
                        empty($response['access_token']) ||
                        !is_string($response['access_token']) ||
                        (int) ($response['expires_in'] ?? 0) <= 0
                    ) {
                        throw PinterestException::invalidResponse();
                    }
                    $token = [
                        'access_token' => $response['access_token'],
                        'expires_at' => time() + (int) $response['expires_in'],
                        'registration' => $registration
                    ];
                    if (
                        file_put_contents($temporary, json_encode($token, JSON_THROW_ON_ERROR)) === false ||
                        !rename($temporary, $tokenPath)
                    ) {
                        throw PinterestException::storageUnavailable();
                    }
                }
                $accessToken = $token['access_token'];
            } finally {
                if ($lock !== false) {
                    fclose($lock);
                }
                if (is_file($temporary)) {
                    unlink($temporary);
                }
                umask($oldMask);
            }
        }
        if ($accessToken === '') {
            throw PinterestException::notConfigured();
        }
        return $accessToken;
    }

    /**
     * Keep token issuance and API reads on the same verified origin without redirects.
     *
     * @param array<string, int|string|null> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query, string $authorization, bool $post = false): array
    {
        $handle = curl_init(self::API . $path . ($post || $query === [] ? '' : '?' . http_build_query($query)));
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Authorization: ' . $authorization, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        ]);
        if ($post) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($query));
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($body)) {
            throw PinterestException::requestFailed(0);
        }
        try {
            $response = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($status === 200) {
                throw PinterestException::invalidResponse();
            }
            throw PinterestException::requestFailed($status);
        }
        if (!is_array($response)) {
            throw PinterestException::invalidResponse();
        }
        if ($status !== 200) {
            throw PinterestException::requestFailed(
                status: $status,
                apiCode: (int) ($response['code'] ?? 0),
                apiMessage: is_string($response['message'] ?? null) ? $response['message'] : ''
            );
        }
        return $response;
    }
}
