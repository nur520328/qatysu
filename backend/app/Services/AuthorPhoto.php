<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * «Платформа туралы» бетіндегі автордың суреті. Жүктелген сурет қайта кодталады: өлшемі кішірейеді және
 * ішіндегі қосымша дерек (түсірілген орны, телефон үлгісі сияқты EXIF) жойылады, себебі бет интернетте ашық.
 * Сурет дерекқорда емес, `storage/app/about/author.jpg` файлында; сақтық көшірмеге кірмейді.
 */
class AuthorPhoto
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Ұзын қабырғасы осыдан аспайды, пиксель. */
    public const MAX_SIDE = 720;

    private const SOURCE_LIMIT = 8000;

    public function path(): string
    {
        return storage_path('app/about/author.jpg');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Суреттің өзгергенін браузер байқауы үшін мекенжайға қосылатын белгі. */
    public function version(): ?int
    {
        return $this->exists() ? (int) filemtime($this->path()) : null;
    }

    /**
     * @return string|null қате коды (`photo_too_large`, `photo_invalid`), сәтті болса null
     */
    public function store(UploadedFile $file): ?string
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            return 'photo_too_large';
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < 100 || $info[1] < 100 || $info[0] > self::SOURCE_LIMIT || $info[1] > self::SOURCE_LIMIT) {
            return 'photo_invalid';
        }
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            default => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $source instanceof GdImage) {
            return 'photo_invalid';
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->upright($source, $file->getRealPath());
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        [$w, $h] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
        $target = imagecreatetruecolor($w, $h);
        // Мөлдір фон (PNG, WebP) ақ түске ауысады: JPEG мөлдірлікті сақтамайды.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        $dir = dirname($this->path());
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Сурет қалтасы жасалмады.');
        }
        $temp = $this->path().'.tmp';
        if (! imagejpeg($target, $temp, 85) || ! rename($temp, $this->path())) {
            @unlink($temp);
            throw new RuntimeException('Сурет сақталмады.');
        }
        clearstatcache(true, $this->path());

        return null;
    }

    public function delete(): void
    {
        @unlink($this->path());
        clearstatcache(true, $this->path());
    }

    /** Телефон суретті «жатқызып» сақтап, бағытын EXIF-ке жазады: қайта кодтағанда сол бағыт қолданылады. */
    private function upright(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        $rotated = $angle === 0 ? $image : imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
