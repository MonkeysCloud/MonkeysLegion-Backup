<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Contract;

interface CompressorInterface
{
    /**
     * Compress a source file to a target destination.
     *
     * @param string $sourcePath Path to the input plain file.
     * @param string $targetPath Path where the compressed file should be saved.
     */
    public function compress(string $sourcePath, string $targetPath): void;

    /**
     * Decompress a compressed file to a target destination.
     *
     * @param string $sourcePath Path to the compressed file.
     * @param string $targetPath Path where the plain decompressed file should be saved.
     */
    public function decompress(string $sourcePath, string $targetPath): void;

    /**
     * Get the file extension associated with this compression (e.g., 'gz').
     */
    public function extension(): string;
}
