<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Storage;

use Erseco\AutoFirma\IntermediateServer\Clock\ClockInterface;
use InvalidArgumentException;

final class MemoryStore implements StoreInterface
{
    private ClockInterface $clock;

    /** @var array<string, array{expiresAt: int, payload: string}> */
    private array $entries = [];

    public function __construct(ClockInterface $clock)
    {
        $this->clock = $clock;
    }

    public function put(string $identifier, string $payload, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('TTL must be greater than zero.');
        }

        $this->entries[$identifier] = [
            'expiresAt' => $this->clock->now() + $ttlSeconds,
            'payload' => $payload,
        ];
    }

    public function consume(string $identifier): ?string
    {
        if (!isset($this->entries[$identifier])) {
            return null;
        }

        $entry = $this->entries[$identifier];
        unset($this->entries[$identifier]);

        if ($entry['expiresAt'] <= $this->clock->now()) {
            return null;
        }

        return $entry['payload'];
    }

    public function purgeExpired(): int
    {
        $purged = 0;

        foreach ($this->entries as $identifier => $entry) {
            if ($entry['expiresAt'] <= $this->clock->now()) {
                unset($this->entries[$identifier]);
                ++$purged;
            }
        }

        return $purged;
    }
}
