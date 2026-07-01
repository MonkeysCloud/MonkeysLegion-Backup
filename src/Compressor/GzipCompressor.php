<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Compressor;

use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Exception\BackupException;

/**
 * Gzip compressor implemented via PHP's native zlib stream wrapper.
 *
 * No subprocess is spawned and no shell command is executed — compression and
 * decompression are done entirely in-process through `compress.zlib://` and
 * `zlib://` stream filters, keeping the zero-dependency promise intact.
 *
 * Compression level: configurable, defaults to 6 (zlib default).
 */
final class GzipCompressor implements CompressorInterface
{
    public function __construct(
        /** @var int<1,9> */
        private int $level = 6
    ) {}

    // -------------------------------------------------------------------------
    // CompressorInterface
    // -------------------------------------------------------------------------

    /**
     * Compress $sourcePath → $targetPath using gzip (zlib deflate).
     *
     * @throws BackupException on I/O failure.
     */
    public function compress(string $sourcePath, string $targetPath): void
    {
        if (!\is_file($sourcePath) || !\is_readable($sourcePath)) {
            throw new BackupException("GzipCompressor: cannot open source file \"{$sourcePath}\" for reading.");
        }

        $in = \fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new BackupException("GzipCompressor: cannot open source file \"{$sourcePath}\" for reading.");
        }

        $level = $this->level;
        $out = \fopen("compress.zlib://{$targetPath}", 'wb' . $level);
        if ($out === false) {
            \fclose($in);
            throw new BackupException("GzipCompressor: cannot open target file \"{$targetPath}\" for writing.");
        }

        try {
            while (!\feof($in)) {
                $chunk = \fread($in, 65536);
                if ($chunk === false) {
                    throw new BackupException("GzipCompressor: read error on \"{$sourcePath}\".");
                }
                if (\fwrite($out, $chunk) === false) {
                    throw new BackupException("GzipCompressor: write error on \"{$targetPath}\".");
                }
            }
        } finally {
            \fclose($in);
            \fclose($out);
        }
    }

    /**
     * Decompress a gzip file at $sourcePath → $targetPath.
     *
     * @throws BackupException on I/O failure or if the file is not valid gzip.
     */
    public function decompress(string $sourcePath, string $targetPath): void
    {
        if (!\is_file($sourcePath) || !\is_readable($sourcePath)) {
            throw new BackupException("GzipCompressor: cannot open gzip source \"{$sourcePath}\" for reading.");
        }

        $this->assertGzip($sourcePath);

        $in = \fopen("compress.zlib://{$sourcePath}", 'rb');
        if ($in === false) {
            throw new BackupException("GzipCompressor: cannot open gzip source \"{$sourcePath}\" for reading.");
        }

        $out = \fopen($targetPath, 'wb');
        if ($out === false) {
            \fclose($in);
            throw new BackupException("GzipCompressor: cannot open target file \"{$targetPath}\" for writing.");
        }

        try {
            while (!\feof($in)) {
                $chunk = \fread($in, 65536);
                if ($chunk === false) {
                    throw new BackupException("GzipCompressor: read error on \"{$sourcePath}\".");
                }
                if ($chunk !== '' && \fwrite($out, $chunk) === false) {
                    throw new BackupException("GzipCompressor: write error on \"{$targetPath}\".");
                }
            }
        } finally {
            \fclose($in);
            \fclose($out);
        }
    }

    /**
     * File extension appended to compressed files.
     */
    public function extension(): string
    {
        return 'gz';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the gzip magic number (1f 8b) in the first two bytes of the file.
     *
     * @throws BackupException if the file is not a valid gzip stream.
     */
    private function assertGzip(string $path): void
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new BackupException("GzipCompressor: cannot open \"{$path}\" to verify gzip header.");
        }

        $fh = \fopen($path, 'rb');
        if ($fh === false) {
            throw new BackupException("GzipCompressor: cannot open \"{$path}\" to verify gzip header.");
        }

        $magic = \fread($fh, 2);
        \fclose($fh);

        if ($magic !== "\x1f\x8b") {
            throw new BackupException("GzipCompressor: \"{$path}\" does not appear to be a valid gzip file.");
        }
    }
}
