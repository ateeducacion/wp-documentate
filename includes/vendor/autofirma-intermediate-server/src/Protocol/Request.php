<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Protocol;

final class Request
{
    private string $method;

    /** @var array<string, mixed> */
    private array $parameters;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(string $method, array $parameters)
    {
        $this->method = strtoupper($method);
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromRawHttp(string $method, array $query = [], string $body = ''): self
    {
        /** @var array<string, mixed> $form */
        $form = [];

        if ($body !== '') {
            parse_str($body, $form);
        }

        $parameters = $query;

        foreach ($form as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $parameters[$name] = $value;
        }

        return new self($method, $parameters);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function value(string $name): ?string
    {
        if (!array_key_exists($name, $this->parameters)) {
            return null;
        }

        $value = $this->parameters[$name];

        if (!is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
