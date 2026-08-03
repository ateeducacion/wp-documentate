<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Protocol;

final class Response
{
    private string $body;

    private int $statusCode;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $body,
        int $statusCode = 200,
        array $headers = []
    ) {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = array_merge(
            [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $headers
        );
    }

    public function body(): string
    {
        return $this->body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
