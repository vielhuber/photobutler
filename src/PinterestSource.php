<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class PinterestSource
{
    private const PAGE_SIZE = 100;

    /**
     * Keep Pinterest independent of the persisted OneDrive catalog and jobs.
     */
    public function __construct(
        private readonly PinterestClient $client,
        private readonly string $boardUrl,
        private readonly string $boardId = ''
    ) {}

    /**
     * Resolve the configured main board from the current authorized board listing.
     *
     * @return array{boards: list<PinterestBoard>, selected: string, url: string}
     */
    public function boards(): array
    {
        if ($this->boardId !== '' && !preg_match('/^\d{1,30}$/D', $this->boardId)) {
            throw PinterestException::invalidRequest();
        }
        $path = array_values(
            array_filter(
                explode('/', trim((string) parse_url($this->boardUrl, PHP_URL_PATH), '/')),
                static fn(string $segment): bool => $segment !== ''
            )
        );
        if (
            $this->boardUrl !== '' &&
            (parse_url($this->boardUrl, PHP_URL_SCHEME) !== 'https' ||
                !preg_match('/^(?:[a-z]+\.)?pinterest\.com$/D', (string) parse_url($this->boardUrl, PHP_URL_HOST)) ||
                count($path) !== 2)
        ) {
            throw PinterestException::invalidRequest();
        }
        $boards = [];
        $selected = '';
        $bookmark = null;
        do {
            $page = $this->client->get('/boards', query: ['page_size' => self::PAGE_SIZE, 'bookmark' => $bookmark]);
            if (!is_array($page['items'] ?? null) || !array_key_exists('bookmark', $page)) {
                throw PinterestException::invalidResponse();
            }
            foreach ($page['items'] as $board) {
                $boards[] = new PinterestBoard(
                    id: (string) $board['id'],
                    name: (string) $board['name'],
                    pinCount: (int) ($board['pin_count'] ?? 0)
                );
                if (
                    ($this->boardId !== '' && $this->boardId === (string) $board['id']) ||
                    ($this->boardId === '' &&
                        $this->boardUrl !== '' &&
                        mb_strtolower((string) ($board['owner']['username'] ?? '')) ===
                            mb_strtolower(rawurldecode($path[0])) &&
                        mb_strtolower(str_replace(' ', '-', $board['name'])) === mb_strtolower(rawurldecode($path[1])))
                ) {
                    $selected = (string) $board['id'];
                }
            }
            if ($page['bookmark'] !== null && (!is_string($page['bookmark']) || $page['bookmark'] === $bookmark)) {
                throw PinterestException::invalidResponse();
            }
            $bookmark = $page['bookmark'];
        } while ($bookmark !== null);
        if (($this->boardId !== '' || $this->boardUrl !== '') && $selected === '') {
            throw PinterestException::boardUnavailable();
        }
        return ['boards' => $boards, 'selected' => $selected ?: $boards[0]->id ?? '', 'url' => $this->boardUrl];
    }

    /**
     * Fetch one live page instead of maintaining an imported copy of Pinterest content.
     *
     * @return array{pins: list<PinterestPin>, bookmark: ?string}
     */
    public function pins(string $board, ?string $bookmark = null): array
    {
        if (!preg_match('/^\d{1,30}$/D', $board)) {
            throw PinterestException::invalidRequest();
        }
        $page = $this->client->get(
            '/boards/' . $board . '/pins',
            query: ['page_size' => self::PAGE_SIZE, 'bookmark' => $bookmark]
        );
        if (
            !is_array($page['items'] ?? null) ||
            !array_key_exists('bookmark', $page) ||
            ($page['bookmark'] !== null && (!is_string($page['bookmark']) || $page['bookmark'] === $bookmark))
        ) {
            throw PinterestException::invalidResponse();
        }
        return [
            'pins' => array_map(static fn(array $pin): PinterestPin => PinterestPin::fromApi($pin), $page['items']),
            'bookmark' => $page['bookmark']
        ];
    }

    /**
     * Open a direct link without enumerating all preceding pages or crossing board boundaries.
     */
    public function pin(string $board, string $id): PinterestPin
    {
        if (!preg_match('/^\d{1,30}$/D', $board) || !preg_match('/^\d{1,30}$/D', $id)) {
            throw PinterestException::invalidRequest();
        }
        $pin = PinterestPin::fromApi($this->client->get('/pins/' . $id));
        if ($pin->boardId !== $board) {
            throw PinterestException::boardUnavailable();
        }
        return $pin;
    }
}
