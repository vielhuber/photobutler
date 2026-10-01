<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\CaptureDate;

final class CaptureDateTest extends TestCase
{
    public static function dates(): array
    {
        return [
            'plausible exif wins' => ['2008.06/IMG_20161001_152611.jpg', '2019-05-04T10:11:12Z', '2019-05-04 10:11:12'],
            'future exif is ignored' => ['2009.01/100_0835.MOV', '2033-09-11T02:14:27Z', '2009-01-01 00:00:00'],
            'ancient exif is ignored' => ['2024/clock.jpg', '1980-01-01T00:00:00Z', '2024-01-01 00:00:00'],
            'camera file name with time' => ['2016.Q4/IMG_20161001_152611.jpg', null, '2016-10-01 15:26:11'],
            'pixel file name with milliseconds' => ['2025/PXL_20250810_201051690.jpg', null, '2025-08-10 20:10:51'],
            'whatsapp file name' => ['_WHATSAPP/WhatsApp Images/IMG-20230819-WA0007.jpg', null, '2023-08-19 00:00:00'],
            'whatsapp sticker' => ['_WHATSAPP/WhatsApp Stickers/STK-20200904-WA0040.webp', null, '2020-09-04 00:00:00'],
            'dashed file name' => ['misc/Screenshot_2021-03-05-07-08-09.png', null, '2021-03-05 07:08:09'],
            'invalid file name date falls back to folder' => [
                '2014.Q1/IMG_20140231_000000.jpg',
                null,
                '2014-01-01 00:00:00'
            ],
            'month folder' => ['2008.06/100_0163.JPG', null, '2008-06-01 00:00:00'],
            'month folder with title' => [
                '_ARCHIV/material/2009.07 Regensommer/100_1494.JPG',
                null,
                '2009-07-01 00:00:00'
            ],
            'quarter folder' => ['2022.Q2/IMG_6954.JPEG', null, '2022-04-01 00:00:00'],
            'fourth quarter folder' => ['2020.Q4/IMG_5790.JPEG', null, '2020-10-01 00:00:00'],
            'year folder' => ['2024/IMG_1.jpg', null, '2024-01-01 00:00:00'],
            'year folder with title' => [
                '_ARCHIV/_BESTELLUNG/2016 - Best of Bestellung Q3/IMG_1082.JPG',
                null,
                '2016-01-01 00:00:00'
            ],
            'nearest dated folder wins' => [
                '_ARCHIV/_OLGA/BILDER/2011/JGA/JGA - Veröffentlichung/21.JPG',
                null,
                '2011-01-01 00:00:00'
            ],
            'uuid name uses folder' => [
                '2019.Q1/b27b1535-df55-4ddd-8d29-167a0e32be99.jpg',
                null,
                '2019-01-01 00:00:00'
            ],
            'digits inside uuid are no date' => [
                '2022.Q4/8b5d1904-1208-4743-929e-f7053de443a8.jpg',
                null,
                '2022-10-01 00:00:00'
            ],
            'name date before 1990 is ignored' => ['2024/IMG_19850101.jpg', null, '2024-01-01 00:00:00'],
            'no date is unknown' => ['_ARCHIV/_KINDER/Bauch-Shooting/P4078809.jpg', null, null]
        ];
    }

    #[DataProvider('dates')]
    public function testResolvesTheMostReliableCaptureDate(string $path, ?string $exif, ?string $expected): void
    {
        $item = ['lastModifiedDateTime' => '2026-04-02T22:46:22Z'];
        if ($exif !== null) {
            $item['photo'] = ['takenDateTime' => $exif, 'alternateTakenDateTime' => '2026-04-02T22:46:22Z'];
        }
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame($expected, new CaptureDate()->resolve($item, $path));
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
