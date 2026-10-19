<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final readonly class PinterestPin
{
    /**
     * Expose display metadata without forwarding the complete provider response.
     */
    private function __construct(
        public string $id,
        public string $boardId,
        public string $title,
        public string $description,
        public string $thumbnail,
        public string $image,
        public string $link,
        public string $source
    ) {}

    /**
     * Select supplied image variants without rewriting URLs or downloading originals.
     *
     * @param array<string, mixed> $pin
     */
    public static function fromApi(array $pin): self
    {
        $id = (string) ($pin['id'] ?? '');
        if (!preg_match('/^\d{1,30}$/D', $id)) {
            throw PinterestException::invalidResponse();
        }
        $images = array_values(
            array_filter(
                $pin['media']['images'] ?? [],
                static fn(array $image): bool => is_string($image['url'] ?? null) &&
                    parse_url($image['url'], PHP_URL_SCHEME) === 'https' &&
                    parse_url($image['url'], PHP_URL_HOST) === 'i.pinimg.com'
            )
        );
        usort(
            $images,
            static fn(array $first, array $second): int => (int) ($first['width'] ?? 0) <=>
                (int) ($second['width'] ?? 0)
        );
        $thumbnail = '';
        foreach ($images as $image) {
            $thumbnail = $image['url'];
            if ((int) ($image['width'] ?? 0) >= 600) {
                break;
            }
        }
        $description = (string) ($pin['description'] ?? '');
        $source = (string) ($pin['link'] ?? '');
        return new self(
            id: $id,
            boardId: (string) ($pin['board_id'] ?? ''),
            title: trim((string) ($pin['title'] ?? '')) ?: (mb_substr($description, 0, 120) ?: 'Pin ' . $id),
            description: $description,
            thumbnail: $thumbnail,
            image: $images === [] ? '' : $images[array_key_last($images)]['url'],
            link: 'https://www.pinterest.com/pin/' . $id . '/',
            source: in_array(parse_url($source, PHP_URL_SCHEME), ['https', 'http'], true) ? $source : ''
        );
    }
}
