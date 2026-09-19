<?php

namespace App\Services;

use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Verschlüsselte Ablage sensibler Model-Dateien (Altersnachweis, Personen-Fotos)
 * auf der privaten `local`-Disk.
 *
 * Verschlüsselung: AES-256-GCM, chunked streaming über das Paket
 * `ercsctt/laravel-file-encryption` (FileEncrypter-Facade). Der Key kommt aus
 * `FILE_ENCRYPTION_KEY` (nicht APP_KEY); Key-Rotation erfolgt über
 * `FILE_ENCRYPTION_PREVIOUS_KEYS`. Verschlüsselung auf Disk und Auslieferung
 * erfolgen chunkweise (kein vollständiger Datei-Puffer nötig); das optionale
 * EXIF/GPS-Stripping per GD-Re-Encoding sowie `getDecrypted()` lesen die Datei
 * bewusst vollständig in den Speicher.
 *
 * Zusätzlich werden Bild-Metadaten (EXIF/GPS) per GD-Re-Encoding entfernt —
 * sofern GD verfügbar ist; ansonsten bleibt die Datei unverändert (aber
 * weiterhin verschlüsselt).
 */
class ModelFileStore
{
    public const DISK = 'local';

    private const STRIPPABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Store an uploaded file encrypted.
     *
     * @return array{path: string, mime: string, size: int}
     */
    public function storeUpload(string $directory, UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $sourcePath = $file->getRealPath();
        $contents = (string) file_get_contents($sourcePath);

        [$sanitized, $mime] = $this->stripImageMetadata($contents, $mime);

        $extension = $this->extensionFor($mime, $file->extension());
        $path = $this->buildPath($directory, $extension);
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($path));

        if ($sanitized === $contents) {
            // No re-encoding happened → encrypt straight from PHP's upload temp
            // file. This avoids writing an extra plaintext copy to app storage.
            FileEncrypter::encryptFile($sourcePath, $disk->path($path));
        } else {
            $this->putEncrypted($path, $sanitized);
        }

        return ['path' => $path, 'mime' => $mime, 'size' => strlen($sanitized)];
    }

    /**
     * Write plaintext contents to disk, encrypted at the given path.
     */
    public function putEncrypted(string $path, string $contents): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($path));

        // The package needs real filesystem paths; encrypt chunk-wise from a
        // temporary plaintext file so nothing is loaded into memory as a whole.
        // The temp name carries a `.plain-` marker so a crash between put() and
        // the finally-block can be swept by cleanupOrphanedTempFiles().
        $temporary = $path.'.plain-'.bin2hex(random_bytes(8));
        $disk->put($temporary, $contents);

        try {
            FileEncrypter::encryptFile($disk->path($temporary), $disk->path($path));
        } finally {
            $disk->delete($temporary);
        }
    }

    /**
     * Remove temporary plaintext files (`.plain-*`) left behind by a crash
     * between writing the plaintext and encrypting it. Intended to run on a
     * schedule (see ProcessModelLifecycle) so no plaintext age proof/photo can
     * survive a hard failure.
     *
     * @param  string  $directory  Restrict the sweep to a subtree ('' = all).
     * @return int Number of removed files.
     */
    public function cleanupOrphanedTempFiles(string $directory = '', int $olderThanMinutes = 60): int
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if (! str_contains(basename($file), '.plain-')) {
                continue;
            }

            if (($disk->lastModified($file) ?: 0) > $cutoff) {
                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Read and decrypt a stored file into memory. Prefer streamDecrypted() for
     * delivery responses.
     */
    public function getDecrypted(string $path): string
    {
        return FileEncrypter::decryptedContents($this->absolutePath($path));
    }

    /**
     * Stream the decrypted file chunk-wise to a callback (e.g. a download
     * response) without loading it fully into memory.
     */
    public function streamDecrypted(string $path, callable $callback): void
    {
        FileEncrypter::decryptedStream($this->absolutePath($path), $callback);
    }

    /**
     * Whether the encrypted file format is present (heuristic header check).
     */
    public function isEncrypted(string $path): bool
    {
        $absolute = $this->absolutePath($path);

        if (! is_file($absolute)) {
            return false;
        }

        return FileEncrypter::isEncrypted($absolute);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete(string|array $paths): void
    {
        Storage::disk(self::DISK)->delete($paths);
    }

    private function absolutePath(string $path): string
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        if (! is_file($absolute)) {
            throw new RuntimeException("Model-Datei nicht gefunden: {$path}");
        }

        return $absolute;
    }

    private function buildPath(string $directory, ?string $extension): string
    {
        $name = bin2hex(random_bytes(20)).($extension ? '.'.$extension : '');

        return trim($directory, '/').'/'.$name;
    }

    private function extensionFor(string $mime, ?string $fallback): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => $fallback ?: 'bin',
        };
    }

    /**
     * Re-encode raster images through GD to drop EXIF/GPS. PDFs and unknown
     * types are returned untouched. Falls back to the original bytes if GD is
     * not available or fails.
     *
     * @return array{0: string, 1: string}
     */
    private function stripImageMetadata(string $contents, string $mime): array
    {
        if (! in_array($mime, self::STRIPPABLE_MIMES, true)) {
            return [$contents, $mime];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return [$contents, $mime];
        }

        try {
            $image = @imagecreatefromstring($contents);
            if ($image === false) {
                return [$contents, $mime];
            }

            ob_start();
            $ok = match ($mime) {
                'image/png' => imagepng($image),
                'image/webp' => function_exists('imagewebp') ? imagewebp($image) : false,
                default => imagejpeg($image, null, 90),
            };
            $encoded = (string) ob_get_clean();
            imagedestroy($image);

            if (! $ok || $encoded === '') {
                return [$contents, $mime];
            }

            return [$encoded, $mime];
        } catch (\Throwable) {
            return [$contents, $mime];
        }
    }
}
