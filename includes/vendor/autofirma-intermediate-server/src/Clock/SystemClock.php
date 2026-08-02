<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Clock;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
