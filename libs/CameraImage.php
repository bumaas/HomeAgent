<?php

declare(strict_types=1);

/**
 * Bereitet das Standbild einer Kamera für ein Sprachmodell auf (B102): höchstens MAX_WIDTH Pixel breit, als JPEG, PNG,
 * GIF oder WebP. Das Bild einer 4K-Kamera hat 2 MB (nuc 10.11.2026), als Base64 wären das 2,7 MB je Antwort.
 * Ohne Symcon-Abhängigkeit. Verkleinert wird mit GD, das im eingebetteten PHP von Symcon vorhanden ist; reicht der
 * Speicher nicht (ein 4K-Bild braucht entpackt rund 33 MB, das memory_limit liegt je Anlage zwischen 32 und 128 MB),
 * gibt es eine Absage statt eines abgebrochenen Threads.
 */
final class CameraImage
{
    /** Breite, auf die ein größeres Bild verkleinert wird (wie die verbreitete HA-Erweiterung LLM Vision). */
    public const MAX_WIDTH = 1280;

    /** JPEG-Qualität des verkleinerten Bildes. */
    private const QUALITY = 80;

    /** Ein Bild bis MAX_WIDTH geht unverändert durch, solange es nicht größer ist als das. */
    private const MAX_BYTES = 1_000_000;

    /** Formate, die ein Sprachmodell als Bild annimmt; alles andere wird zu JPEG. */
    private const PASS_THROUGH = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Reserve für alles andere in der Anfrage (Antwort, Base64, JSON). */
    private const MEMORY_RESERVE = 8 * 1024 * 1024;

    /**
     * @param null|bool $gd null = selbst feststellen; false erzwingt den Weg ohne GD (Test)
     * @param null|int $freeMemory freier Speicher in Byte; null = aus memory_limit und dem Verbrauch (Test)
     * @param int $maxWidth Zielbreite; die Ansicht in der App nimmt ein kleineres Bild als das Modell
     * @return array{data: string, mimeType: string, width: int, height: int, scaled: bool}|array{error: string, width?: int, height?: int}
     *     error: unreadable (kein Bild), too_large (lässt sich hier nicht verkleinern), format (Format ohne GD nicht lieferbar)
     */
    public static function prepare(string $bytes, ?bool $gd = null, ?int $freeMemory = null, int $maxWidth = self::MAX_WIDTH): array
    {
        $size = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return ['error' => 'unreadable'];
        }
        [$width, $height] = $size;
        $mime = (string)$size['mime'];
        $deliverable = in_array($mime, self::PASS_THROUGH, true);
        if ($deliverable && $width <= $maxWidth && strlen($bytes) <= self::MAX_BYTES) {
            return ['data' => $bytes, 'mimeType' => $mime, 'width' => $width, 'height' => $height, 'scaled' => false];
        }
        $gd ??= function_exists('imagecreatefromstring') && function_exists('imagescale') && function_exists('imagejpeg');
        $freeMemory ??= self::freeMemory();
        if (!$gd || self::memoryNeeded($width, $height, $maxWidth) > $freeMemory) {
            return ['error' => $deliverable || $gd ? 'too_large' : 'format', 'width' => $width, 'height' => $height];
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return ['error' => 'unreadable'];
        }
        if ($width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, -1, IMG_BILINEAR_FIXED);
            if ($scaled === false) {
                return ['error' => 'unreadable'];
            }
            $image = $scaled;
        }
        ob_start();
        $ok = imagejpeg($image, null, self::QUALITY);
        $data = (string)ob_get_clean();
        if (!$ok || $data === '') {
            return ['error' => 'unreadable'];
        }
        return ['data' => $data, 'mimeType' => 'image/jpeg', 'width' => imagesx($image), 'height' => imagesy($image), 'scaled' => true];
    }

    /** Speicher zum Entpacken und Verkleinern: vier Byte je Pixel für das Bild und für das Ergebnis, dazu die Reserve. */
    public static function memoryNeeded(int $width, int $height, int $maxWidth = self::MAX_WIDTH): int
    {
        $targetWidth = min($width, $maxWidth);
        $targetHeight = (int)ceil($height * $targetWidth / max(1, $width));
        return $width * $height * 4 + $targetWidth * $targetHeight * 4 + self::MEMORY_RESERVE;
    }

    /** Was vom memory_limit noch frei ist; ohne Grenze beliebig viel. */
    private static function freeMemory(): int
    {
        $limit = trim((string)ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $bytes = (int)$limit * match (strtolower(substr($limit, -1))) {
            'g'     => 1024 * 1024 * 1024,
            'm'     => 1024 * 1024,
            'k'     => 1024,
            default => 1,
        };
        return max(0, $bytes - memory_get_usage());
    }
}
