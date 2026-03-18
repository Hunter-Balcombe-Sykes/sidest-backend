<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcodes a video original into MP4 + HLS variants and a poster image,
 * stores all artifacts on the media disk, and persists MediaVariant rows.
 *
 * Processing order (avoids extra re-encodes):
 *   1. FFprobe  – extract metadata + validate duration
 *   2. FFmpeg   – encode optimized MP4 (720p)
 *   3. FFmpeg   – encode maximized MP4 (1080p)
 *   4. FFmpeg   – package HLS segments from each MP4 (stream-copy, no re-encode)
 *   5. Build    – write master adaptive HLS playlist
 *   6. FFmpeg   – extract poster JPEG at 1s mark
 *   7. Upload   – stream all artifacts to media disk
 *   8. DB upsert – persist one MediaVariant row per logical artifact
 *   9. SiteImage update – set processing_state, duration_ms, poster_path
 *
 * Storage layout under the media disk:
 *   videos/{proId}/{mediaId}/
 *     original_{hash}.{ext}
 *     optimized.mp4
 *     maximized.mp4
 *     hls/
 *       optimized/playlist.m3u8  + seg_*.ts
 *       maximized/playlist.m3u8  + seg_*.ts
 *       adaptive.m3u8             (master playlist)
 *     poster.jpg
 *
 * Requires ffmpeg and ffprobe to be available (configured via config/comet.php).
 */
class VideoVariantService
{
    /* ------------------------------------------------------------------ */
    /*  Public API                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Run FFprobe on a local file and return metadata array.
     * Throws \RuntimeException if the binary fails.
     *
     * @return array<string, mixed>
     */
    public function probe(string $localPath): array
    {
        $ffprobe = $this->ffprobeBinary();
        $cmd     = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellcmd($ffprobe),
            escapeshellarg($localPath),
        );

        $output = $this->exec($cmd, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffprobe failed (exit {$exitCode}): {$output}");
        }

        $data = json_decode($output, true);
        if (! is_array($data)) {
            throw new \RuntimeException('ffprobe returned non-JSON output.');
        }

        return $data;
    }

    /**
     * Process a video original into all configured artifacts.
     *
     * @throws \RuntimeException on unrecoverable processing errors
     */
    public function processVariants(
        string $localOriginalPath,
        string $mediaId,
        string $basePath,
    ): void {
        Log::info('VideoVariantService: starting', [
            'media_id'  => $mediaId,
            'base_path' => $basePath,
        ]);

        // --- 1. Probe metadata ---
        $probe      = $this->probe($localOriginalPath);
        $durationMs = $this->extractDurationMs($probe);
        $maxDur     = (int) config('comet.video_max_duration_seconds', 300);

        if ($durationMs > $maxDur * 1000) {
            throw new \RuntimeException(
                "Video duration ({$durationMs}ms) exceeds maximum ({$maxDur}s)."
            );
        }

        $variantDefs = (array) config('comet.video_variants', []);
        $tmpDirs     = [];

        try {
            // --- 2 & 3. Encode MP4 variants ---
            $mp4Paths = [];
            foreach ($variantDefs as $variantKey => $def) {
                $tmpMp4 = $this->makeTmpFile("comet_mp4_{$variantKey}_", '.mp4');
                $this->encodeMp4($localOriginalPath, $tmpMp4, $def);
                $mp4Paths[$variantKey] = $tmpMp4;
            }

            // --- 4. Package HLS from each MP4 ---
            $hlsDirs = [];
            foreach ($mp4Paths as $variantKey => $mp4) {
                $tmpHlsDir = sys_get_temp_dir() . '/comet_hls_' . $variantKey . '_' . uniqid();
                if (! mkdir($tmpHlsDir, 0755, true)) {
                    throw new \RuntimeException("Failed to create HLS temp dir: {$tmpHlsDir}");
                }
                $tmpDirs[]            = $tmpHlsDir;
                $this->packageHls($mp4, $tmpHlsDir . '/playlist.m3u8');
                $hlsDirs[$variantKey] = $tmpHlsDir;
            }

            // --- 5. Build adaptive master playlist ---
            $adaptiveContent = $this->buildAdaptivePlaylist($variantDefs);

            // --- 6. Extract poster ---
            $tmpPoster = $this->makeTmpFile('comet_poster_', '.jpg');
            $this->extractPoster($localOriginalPath, $tmpPoster);

            // --- 7. Upload all artifacts ---
            $disk     = $this->disk();
            $diskName = $this->resolvedDiskName();

            // Upload MP4s
            foreach ($mp4Paths as $variantKey => $mp4) {
                $remotePath = "{$basePath}/{$variantKey}.mp4";
                $stream     = fopen($mp4, 'rb');
                $disk->put($remotePath, $stream, 'public');
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $def = $variantDefs[$variantKey] ?? [];
                // Unique key: (media_id, variant_key, artifact_type)
                MediaVariant::updateOrCreate(
                    ['media_id' => $mediaId, 'variant_key' => $variantKey, 'artifact_type' => 'mp4'],
                    [
                        'disk'            => $diskName,
                        'path'            => $remotePath,
                        'mime'            => 'video/mp4',
                        'bitrate_kbps'    => (int) ($def['video_bitrate_kbps'] ?? 0) + (int) ($def['audio_bitrate_kbps'] ?? 0),
                        'file_size_bytes' => filesize($mp4) ?: null,
                        'duration_ms'     => $durationMs,
                        'metadata'        => ['resolution' => $def['resolution'] ?? null],
                    ]
                );
            }

            // Upload HLS segments + playlists
            foreach ($hlsDirs as $variantKey => $hlsDir) {
                $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
                foreach (scandir($hlsDir) ?: [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $localFile  = "{$hlsDir}/{$file}";
                    $remotePath = "{$remoteHlsBase}/{$file}";
                    $mime       = str_ends_with($file, '.m3u8') ? 'application/vnd.apple.mpegurl' : 'video/mp2t';
                    $stream     = fopen($localFile, 'rb');
                    $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $playlistPath = "{$remoteHlsBase}/playlist.m3u8";
                $def          = $variantDefs[$variantKey] ?? [];
                MediaVariant::updateOrCreate(
                    ['media_id' => $mediaId, 'variant_key' => $variantKey, 'artifact_type' => 'hls_playlist'],
                    [
                        'disk'        => $diskName,
                        'path'        => $playlistPath,
                        'mime'        => 'application/vnd.apple.mpegurl',
                        'duration_ms' => $durationMs,
                        'metadata'    => ['resolution' => $def['resolution'] ?? null],
                    ]
                );
            }

            // Upload adaptive master playlist
            $adaptiveRemotePath = "{$basePath}/hls/adaptive.m3u8";
            $disk->put($adaptiveRemotePath, $adaptiveContent, [
                'visibility'  => 'public',
                'ContentType' => 'application/vnd.apple.mpegurl',
            ]);

            MediaVariant::updateOrCreate(
                ['media_id' => $mediaId, 'variant_key' => 'adaptive', 'artifact_type' => 'hls_playlist'],
                [
                    'disk' => $diskName,
                    'path' => $adaptiveRemotePath,
                    'mime' => 'application/vnd.apple.mpegurl',
                ]
            );

            // Upload poster
            $posterRemotePath = "{$basePath}/poster.jpg";
            $stream           = fopen($tmpPoster, 'rb');
            $disk->put($posterRemotePath, $stream, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
            if (is_resource($stream)) {
                fclose($stream);
            }

            MediaVariant::updateOrCreate(
                ['media_id' => $mediaId, 'variant_key' => 'poster', 'artifact_type' => 'poster'],
                [
                    'disk'            => $diskName,
                    'path'            => $posterRemotePath,
                    'mime'            => 'image/jpeg',
                    'file_size_bytes' => filesize($tmpPoster) ?: null,
                ]
            );

            // --- 8. Update SiteImage ---
            SiteImage::query()
                ->where('id', $mediaId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteImage::PROCESSING_STATE_READY,
                    'processing_error' => null,
                    'duration_ms'      => $durationMs,
                    'poster_path'      => $posterRemotePath,
                ]);

            Log::info('VideoVariantService: completed', [
                'media_id'    => $mediaId,
                'duration_ms' => $durationMs,
            ]);
        } finally {
            // Cleanup temp files and dirs
            foreach ($mp4Paths ?? [] as $mp4) {
                if (file_exists($mp4)) {
                    @unlink($mp4);
                }
            }
            if (isset($tmpPoster) && file_exists($tmpPoster)) {
                @unlink($tmpPoster);
            }
            foreach ($tmpDirs as $dir) {
                $this->removeDir($dir);
            }
        }
    }

    /**
     * Delete all media_variants DB rows and all files under $basePath on disk.
     * Called by DeleteMediaArtifactsJob (async) for video cleanup.
     */
    public function deleteVariants(string $mediaId, string $basePath): void
    {
        $disk       = $this->disk();
        $basePrefix = $this->normalizeVideoCleanupBasePath($basePath);

        // Delete all files under the normalized video prefix.
        try {
            $files = $disk->allFiles($basePrefix);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to list video artifacts for cleanup at [{$basePrefix}].",
                0,
                $e
            );
        }

        foreach ($files as $file) {
            try {
                $deleted = $disk->delete($file);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Failed to delete video artifact [{$file}].",
                    0,
                    $e
                );
            }

            if ($deleted === false) {
                throw new \RuntimeException("Failed to delete video artifact [{$file}].");
            }
        }

        // Delete DB rows only after storage cleanup succeeds.
        MediaVariant::where('media_id', $mediaId)->delete();
    }

    /**
     * Resolve the effective media disk name.
     * Mirrors the logic in ImageVariantService for consistency.
     */
    public function resolvedDiskName(): string
    {
        return $this->diskName();
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function encodeMp4(string $input, string $output, array $def): void
    {
        $ffmpeg     = $this->ffmpegBinary();
        $resolution = (string) ($def['resolution'] ?? '1280x720');
        [$width, $height] = array_map('intval', explode('x', $resolution, 2));
        $videoBitrate = (int) ($def['video_bitrate_kbps'] ?? 2000);
        $audioBitrate = (int) ($def['audio_bitrate_kbps'] ?? 128);

        // Scale to fit within resolution bounds while maintaining aspect ratio.
        // The scale filter uses -2 to keep dimensions divisible by 2 (required by libx264).
        $scaleFilter = "scale='if(gt(iw,{$width}),{$width},-2)':'if(gt(ih,{$height}),{$height},-2)'";

        $cmd = sprintf(
            '%s -y -i %s -vf %s -c:v libx264 -b:v %dk -maxrate %dk -bufsize %dk '
            . '-profile:v main -level 4.0 -movflags +faststart '
            . '-c:a aac -b:a %dk -ar 44100 %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($input),
            escapeshellarg($scaleFilter),
            $videoBitrate,
            (int) ($videoBitrate * 1.5),
            (int) ($videoBitrate * 2),
            $audioBitrate,
            escapeshellarg($output),
        );

        $output_str = $this->exec($cmd, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg MP4 encoding failed (exit {$exitCode}): {$output_str}");
        }
    }

    private function packageHls(string $mp4Input, string $playlistOutput): void
    {
        $ffmpeg    = $this->ffmpegBinary();
        $outputDir = dirname($playlistOutput);
        $segmentPattern = $outputDir . '/seg_%03d.ts';

        $cmd = sprintf(
            '%s -y -i %s -c copy -f hls -hls_time 6 -hls_playlist_type vod '
            . '-hls_segment_filename %s %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($mp4Input),
            escapeshellarg($segmentPattern),
            escapeshellarg($playlistOutput),
        );

        $output = $this->exec($cmd, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg HLS packaging failed (exit {$exitCode}): {$output}");
        }
    }

    private function extractPoster(string $input, string $output): void
    {
        $ffmpeg = $this->ffmpegBinary();

        $cmd = sprintf(
            '%s -y -i %s -ss 00:00:01 -frames:v 1 -q:v 3 %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($input),
            escapeshellarg($output),
        );

        $outputStr = $this->exec($cmd, $exitCode);

        if ($exitCode !== 0 || ! file_exists($output)) {
            // Non-fatal: try frame at 0s as fallback
            $cmd2 = sprintf(
                '%s -y -i %s -frames:v 1 -q:v 3 %s 2>&1',
                escapeshellcmd($ffmpeg),
                escapeshellarg($input),
                escapeshellarg($output),
            );
            $this->exec($cmd2, $exitCode2);

            if ($exitCode2 !== 0 || ! file_exists($output)) {
                Log::warning('VideoVariantService: could not extract poster frame.', [
                    'error' => $outputStr,
                ]);
                // Create a 1-byte placeholder so downstream code doesn't fail
                file_put_contents($output, '');
            }
        }
    }

    /**
     * Build the adaptive (master) HLS playlist content.
     *
     * @param  array<string, array<string, mixed>>  $variantDefs
     */
    private function buildAdaptivePlaylist(array $variantDefs): string
    {
        $lines = ['#EXTM3U'];

        foreach ($variantDefs as $variantKey => $def) {
            $videoBitrate = (int) ($def['video_bitrate_kbps'] ?? 2000);
            $audioBitrate = (int) ($def['audio_bitrate_kbps'] ?? 128);
            $bandwidth    = ($videoBitrate + $audioBitrate) * 1000;
            $resolution   = strtoupper((string) ($def['resolution'] ?? '1280x720'));

            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$resolution}";
            $lines[] = "{$variantKey}/playlist.m3u8";
        }

        return implode("\n", $lines) . "\n";
    }

    private function extractDurationMs(array $probe): int
    {
        $duration = $probe['format']['duration'] ?? null;
        if ($duration === null) {
            // Fall back to the first video stream
            foreach ($probe['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video' && isset($stream['duration'])) {
                    $duration = $stream['duration'];
                    break;
                }
            }
        }

        return $duration !== null ? (int) round((float) $duration * 1000) : 0;
    }

    private function makeTmpFile(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if (! $path) {
            throw new \RuntimeException("Failed to create temp file ({$prefix}).");
        }

        // Rename to add the desired extension so FFmpeg knows the container.
        $withExt = $path . $suffix;
        rename($path, $withExt);

        return $withExt;
    }

    /**
     * Execute a shell command and return its output.
     * $exitCode is set by reference.
     */
    private function exec(string $cmd, ?int &$exitCode = null): string
    {
        $output   = [];
        $exitCode = 0;

        exec($cmd, $output, $exitCode);

        return implode("\n", $output);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Legacy jobs may pass the original file path instead of the media directory.
     * Normalize to the directory prefix expected by allFiles().
     */
    private function normalizeVideoCleanupBasePath(string $basePath): string
    {
        $normalized = trim($basePath);
        if ($normalized === '') {
            throw new \RuntimeException('Video cleanup base path cannot be empty.');
        }

        $leaf = basename($normalized);
        if (str_contains($leaf, '.')) {
            $normalized = dirname($normalized);
        }

        $normalized = trim($normalized, '/');
        if ($normalized === '' || $normalized === '.') {
            throw new \RuntimeException('Video cleanup base path resolved to an invalid directory.');
        }

        return $normalized;
    }

    private function diskName(): string
    {
        $configured = (string) config('comet.media_disk', 'media');

        $explicit = $_ENV['COMET_MEDIA_DISK'] ?? $_SERVER['COMET_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return $configured;
        }

        if ($configured === 'media') {
            $default       = (string) config('filesystems.default', 'local');
            $defaultConfig = config("filesystems.disks.{$default}");

            if (
                $default !== '' &&
                $default !== 'local' &&
                $default !== 'media' &&
                is_array($defaultConfig) &&
                (($defaultConfig['driver'] ?? null) === 's3')
            ) {
                return $default;
            }
        }

        return $configured;
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function ffmpegBinary(): string
    {
        return (string) config('comet.ffmpeg_binary', 'ffmpeg');
    }

    private function ffprobeBinary(): string
    {
        return (string) config('comet.ffprobe_binary', 'ffprobe');
    }
}
