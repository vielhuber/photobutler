<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class CaptureDate
{
    private const EARLIEST = '1990-01-01 00:00:00';
    private const FILE_NAME_DATE = '/(?<![0-9a-z])((?:19|20)\d\d)[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])(?:[-_.T ]?([01]\d|2[0-3])[-_.]?([0-5]\d)[-_.]?([0-5]\d)\d*)?(?![0-9a-z])/i';
    private const FOLDER_MONTH = '/^((?:19|20)\d\d)[-_. ](0[1-9]|1[0-2])(?!\d)/';
    private const FOLDER_QUARTER = '/^((?:19|20)\d\d)[-_. ]?Q([1-4])(?!\d)/i';
    private const FOLDER_YEAR = '/^((?:19|20)\d\d)(?!\d)/';

    /**
     * Prefer plausible EXIF dates, then dates in the file name, then the nearest dated folder; null when none is known.
     *
     * @param array<string, mixed> $item OneDrive driveItem metadata
     */
    public function resolve(array $item, string $relativePath): ?string
    {
        $latest = date('Y-m-d H:i:s', time() + 86400);
        $exif = isset($item['photo']['takenDateTime'])
            ? date('Y-m-d H:i:s', strtotime($item['photo']['takenDateTime']))
            : '';
        if ($exif >= self::EARLIEST && $exif <= $latest) {
            return $exif;
        }
        if (preg_match(self::FILE_NAME_DATE, pathinfo($relativePath, PATHINFO_FILENAME), $match) === 1) {
            $date = sprintf(
                '%s-%s-%s %s:%s:%s',
                $match[1],
                $match[2],
                $match[3],
                $match[4] ?? '' ?: '00',
                $match[5] ?? '' ?: '00',
                $match[6] ?? '' ?: '00'
            );
            if (
                checkdate((int) $match[2], (int) $match[3], (int) $match[1]) &&
                $date >= self::EARLIEST &&
                $date <= $latest
            ) {
                return $date;
            }
        }
        foreach (array_reverse(array_slice(explode('/', $relativePath), 0, -1)) as $folder) {
            $date = match (true) {
                preg_match(self::FOLDER_MONTH, $folder, $match) === 1 => $match[1] . '-' . $match[2] . '-01 00:00:00',
                preg_match(self::FOLDER_QUARTER, $folder, $match) === 1 => sprintf(
                    '%s-%02d-01 00:00:00',
                    $match[1],
                    (int) $match[2] * 3 - 2
                ),
                preg_match(self::FOLDER_YEAR, $folder, $match) === 1 => $match[1] . '-01-01 00:00:00',
                default => ''
            };
            if ($date !== '' && $date <= $latest) {
                return $date;
            }
        }
        return null;
    }
}
