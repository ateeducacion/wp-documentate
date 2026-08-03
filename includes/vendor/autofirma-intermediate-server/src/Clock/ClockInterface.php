<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Clock;

interface ClockInterface
{
    public function now(): int;
}
