<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Storage;

use MonkeysLegion\Backup\Contract\StorageAdapterInterface;

/**
 * Local storage adapter.
 *
 * Saves backups to a configured local directory.
 * - Automatically creates nested directories as needed.
 * - Performs atomic writes using temporary files and renames to prevent corruption.
 * - Prevents path traversal vulnerabilities.
 */
final class LocalStorageAdapter implements StorageAdapterInterface
{
    private string $root;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $root = $options['root'] ?? null;
        if ($root === null || $root === '') {
            throw new \InvalidArgumentException('LocalStorageAdapter requires a "root" directory option.');
        }

        $this->root = \rtrim(\str_replace('\\', '/', (string)$root), '/');
        if (!\is_dir($this->root)) {
            if (!@\mkdir($this->root, 0755, true) && !\is_dir($this->root)) {
                throw new \RuntimeException("Failed to create root directory: {$this->root}");
            }
        }
    }

    // -------------------------------------------------------------------------
    // StorageAdapterInterface
    // -------------------------------------------------------------------------

    public function upload(string $localPath, string $remoteKey, array $metadata = []): string
    {
        if (!\is_file($localPath) || !\is_readable($localPath)) {
            throw new \RuntimeException("Local file is not readable: {$localPath}");
        }

        $dest = $this->getFullPath($remoteKey);
        $this->copyAtomically($localPath, $dest);

        if (!empty($metadata)) {
            $metaDest = "{$dest}.meta";
            $uniq = \uniqid('', true);
            $metaTmp = "{$metaDest}.{$uniq}.tmp";
            if (\file_put_contents($metaTmp, \json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
                throw new \RuntimeException("Failed to write metadata temp file.");
            }
            if (!@\rename($metaTmp, $metaDest)) {
                @\unlink($metaTmp);
                throw new \RuntimeException("Failed to atomically write metadata.");
            }
        }

        return $dest;
    }

    public function download(string $remoteKey, string $localPath): void
    {
        $src = $this->getFullPath($remoteKey);
        if (!\is_file($src) || !\is_readable($src)) {
            throw new \RuntimeException("Remote file not found or not readable: {$remoteKey}");
        }

        $this->copyAtomically($src, $localPath);
    }

    public function delete(string $remoteKey): void
    {
        $file = $this->getFullPath($remoteKey);
        if (\is_file($file)) {
            if (!@\unlink($file)) {
                throw new \RuntimeException("Failed to delete file: {$remoteKey}");
            }
        }

        $metaFile = "{$file}.meta";
        if (\is_file($metaFile)) {
            @\unlink($metaFile);
        }
    }

    public function list(string $prefix = ''): array
    {
        if (!\is_dir($this->root)) {
            return [];
        }

        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );

        $normalizedPrefix = \ltrim(\str_replace('\\', '/', $prefix), '/');

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (\str_ends_with($path, '.meta')) {
                continue;
            }

            $relative = \substr($path, \strlen($this->root) + 1);
            $relative = \str_replace('\\', '/', $relative);

            if ($normalizedPrefix === '' || \str_starts_with($relative, $normalizedPrefix)) {
                $results[] = [
                    'key' => $relative,
                    'size' => $fileInfo->getSize(),
                    'modified_at' => \date('c', $fileInfo->getMTime()),
                ];
            }
        }

        \usort($results, fn($a, $b) => $a['key'] <=> $b['key']);

        return $results;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getFullPath(string $remoteKey): string
    {
        // Safe key to prevent directory traversal
        $safeKey = \str_replace(['..', "\0"], '', $remoteKey);
        $safeKey = \ltrim(\str_replace('\\', '/', $safeKey), '/');
        return "{$this->root}/{$safeKey}";
    }

    private function copyAtomically(string $src, string $dest): void
    {
        $dir = \dirname($dest);
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $uniq = \uniqid('', true);
        $tmpFile = "{$dest}.{$uniq}.tmp";

        if (!@\copy($src, $tmpFile)) {
            throw new \RuntimeException("Failed to copy file to temp destination: {$tmpFile}");
        }

        if (!@\rename($tmpFile, $dest)) {
            @\unlink($tmpFile);
            throw new \RuntimeException("Failed to atomically rename {$tmpFile} to {$dest}");
        }
    }
}
