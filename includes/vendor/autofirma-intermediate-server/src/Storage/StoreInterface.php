<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Storage;

interface StoreInterface
{
    public function put(string $identifier, string $payload, int $ttlSeconds): void;

    public function consume(string $identifier): ?string;

    public function purgeExpired(): int;
}
