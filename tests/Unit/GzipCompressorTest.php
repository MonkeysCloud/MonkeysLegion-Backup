<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Compressor\GzipCompressor;
use MonkeysLegion\Backup\Exception\BackupException;
use PHPUnit\Framework\TestCase;

final class GzipCompressorTest extends TestCase
{
    private GzipCompressor $compressor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->compressor = new GzipCompressor();
        $this->tempDir = \sys_get_temp_dir() . '/mb_gzip_test_' . \uniqid();
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $files = \glob("{$this->tempDir}/*");
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_file($file)) {
                        \unlink($file);
                    }
                }
            }
            \rmdir($this->tempDir);
        }
    }

    public function testExtension(): void
    {
        $this->assertSame('gz', $this->compressor->extension());
    }

    public function testCompressAndDecompressRoundtrip(): void
    {
        $originalFile = "{$this->tempDir}/original.txt";
        $compressedFile = "{$this->tempDir}/compressed.gz";
        $decompressedFile = "{$this->tempDir}/decompressed.txt";

        $content = "Hello World! This is some test content for GzipCompressor.\n" . \str_repeat("Repeat this line. ", 100);
        \file_put_contents($originalFile, $content);

        // Compress
        $this->compressor->compress($originalFile, $compressedFile);
        $this->assertFileExists($compressedFile);
        $this->assertLessThan(\filesize($originalFile), \filesize($compressedFile));

        // Decompress
        $this->compressor->decompress($compressedFile, $decompressedFile);
        $this->assertFileExists($decompressedFile);
        $this->assertSame($content, \file_get_contents($decompressedFile));
    }

    public function testCompressThrowsOnMissingSource(): void
    {
        $missingFile = "{$this->tempDir}/non_existent.txt";
        $targetFile = "{$this->tempDir}/out.gz";

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageIsOrContains("cannot open source file");

        $this->compressor->compress($missingFile, $targetFile);
    }

    public function testDecompressThrowsOnInvalidHeader(): void
    {
        $invalidGzip = "{$this->tempDir}/invalid.gz";
        $targetFile = "{$this->tempDir}/out.txt";

        // Plain text file (no gzip magic number)
        \file_put_contents($invalidGzip, "Not a gzip file");

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageIsOrContains("does not appear to be a valid gzip file");

        $this->compressor->decompress($invalidGzip, $targetFile);
    }

    public function testCustomCompressionLevel(): void
    {
        // Level 1 (Fastest) vs Level 9 (Best)
        $level1 = new GzipCompressor(1);
        $level9 = new GzipCompressor(9);

        $originalFile = "{$this->tempDir}/large.txt";
        $compressed1File = "{$this->tempDir}/comp_1.gz";
        $compressed9File = "{$this->tempDir}/comp_9.gz";

        $content = \str_repeat("Testing compressibility with a lot of repeated words.\n", 2000);
        \file_put_contents($originalFile, $content);

        $level1->compress($originalFile, $compressed1File);
        $level9->compress($originalFile, $compressed9File);

        $this->assertFileExists($compressed1File);
        $this->assertFileExists($compressed9File);

        // Level 9 should be smaller than or equal to level 1 for compressible content
        $this->assertLessThanOrEqual(\filesize($compressed1File), \filesize($compressed9File));
    }
}
