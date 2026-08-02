<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Storage;

use Erseco\AutoFirma\IntermediateServer\Clock\ClockInterface;
use Erseco\AutoFirma\IntermediateServer\Exception\StorageException;
use InvalidArgumentException;

final class FilesystemStore implements StoreInterface
{
    private ClockInterface $clock;

    private string $directory;

    public function __construct(string $directory, ClockInterface $clock)
    {
        if (is_link($directory)) {
            throw new StorageException('The storage directory cannot be a symbolic link.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create the storage directory.');
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false || !is_readable($realDirectory) || !is_writable($realDirectory)) {
            throw new StorageException('The storage directory must be readable and writable.');
        }

        $this->directory = $realDirectory;
        $this->clock = $clock;
    }

    public function put(string $identifier, string $payload, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('TTL must be greater than zero.');
        }

        $temporaryPath = tempnam($this->directory, '.write-');

        if ($temporaryPath === false) {
            throw new StorageException('Unable to create a temporary storage file.');
        }

        $record = sprintf('%020d', $this->clock->now() + $ttlSeconds) . "\n" . $payload;
        $written = file_put_contents($temporaryPath, $record, LOCK_EX);

        if ($written !== strlen($record)) {
            unlink($temporaryPath);
            throw new StorageException('Unable to write the temporary storage file.');
        }

        if (!chmod($temporaryPath, 0600)) {
            unlink($temporaryPath);
            throw new StorageException('Unable to secure the temporary storage file.');
        }

        if (!rename($temporaryPath, $this->path($identifier))) {
            unlink($temporaryPath);
            throw new StorageException('Unable to publish the temporary storage file.');
        }
    }

    public function consume(string $identifier): ?string
    {
        $path = $this->path($identifier);
        $claimedPath = $path . '.consume-' . bin2hex(random_bytes(8));

        if (!is_file($path) || !@rename($path, $claimedPath)) {
            return null;
        }

        if (is_link($claimedPath)) {
            unlink($claimedPath);
            return null;
        }

        try {
            $record = file_get_contents($claimedPath);

            if ($record === false) {
                throw new StorageException('Unable to read the claimed storage file.');
            }

            return $this->payloadFromRecord($record);
        } finally {
            if (is_file($claimedPath)) {
                unlink($claimedPath);
            }
        }
    }

    public function purgeExpired(): int
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.message*');

        if ($paths === false) {
            throw new StorageException('Unable to enumerate storage files.');
        }

        $purged = 0;

        foreach ($paths as $path) {
            if (is_link($path)) {
                if (unlink($path)) {
                    ++$purged;
                }

                continue;
            }

            if (!is_file($path)) {
                continue;
            }

            $record = file_get_contents($path);

            if ($record === false || $this->isExpiredOrMalformed($record)) {
                if (unlink($path)) {
                    ++$purged;
                }
            }
        }

        return $purged;
    }

    private function path(string $identifier): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $identifier)
            . '.message';
    }

    private function payloadFromRecord(string $record): ?string
    {
        if ($this->isExpiredOrMalformed($record)) {
            return null;
        }

        return substr($record, 21);
    }

    private function isExpiredOrMalformed(string $record): bool
    {
        if (strlen($record) < 21 || $record[20] !== "\n") {
            return true;
        }

        $expiration = substr($record, 0, 20);

        if (!ctype_digit($expiration)) {
            return true;
        }

        return (int) $expiration <= $this->clock->now();
    }
}
