<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer;

use Erseco\AutoFirma\IntermediateServer\Exception\StorageException;
use Erseco\AutoFirma\IntermediateServer\Protocol\ProtocolError;
use Erseco\AutoFirma\IntermediateServer\Protocol\Request;
use Erseco\AutoFirma\IntermediateServer\Protocol\Response;
use Erseco\AutoFirma\IntermediateServer\Storage\StoreInterface;
use InvalidArgumentException;

final class IntermediateServer
{
    public const SYNTAX_VERSION = '1_0';

    private int $maxPayloadBytes;

    private StoreInterface $store;

    private int $ttlSeconds;

    public function __construct(
        StoreInterface $store,
        int $maxPayloadBytes = 20971520,
        int $ttlSeconds = 120
    ) {
        if ($maxPayloadBytes < 1) {
            throw new InvalidArgumentException('Maximum payload size must be greater than zero.');
        }

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('TTL must be greater than zero.');
        }

        $this->store = $store;
        $this->maxPayloadBytes = $maxPayloadBytes;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function handle(Request $request): Response
    {
        $operation = $request->value('op');

        if ($operation === null || $operation === '') {
            return new Response(ProtocolError::missingOperation());
        }

        if (strtolower($operation) === 'check') {
            return new Response('OK');
        }

        if ($request->value('v') === null) {
            return new Response(ProtocolError::missingSyntaxVersion());
        }

        if ($request->value('v') !== self::SYNTAX_VERSION) {
            return new Response(ProtocolError::unsupportedOperation());
        }

        if (strtolower($operation) === 'put') {
            return $this->put($request);
        }

        if (strtolower($operation) === 'get') {
            return $this->get($request);
        }

        return new Response(ProtocolError::unsupportedOperation());
    }

    private function put(Request $request): Response
    {
        $identifier = $request->value('id');

        if ($identifier === null || $identifier === '') {
            return new Response(ProtocolError::missingIdentifier());
        }

        if (!$this->isValidIdentifier($identifier)) {
            return new Response(ProtocolError::invalidIdentifier());
        }

        $payload = $request->value('dat');

        if ($payload === null || $payload === '') {
            $payload = ProtocolError::missingData();
        } elseif (strlen($payload) > $this->maxPayloadBytes) {
            $payload = ProtocolError::invalidData();
        }

        try {
            $this->store->put($identifier, $payload, $this->ttlSeconds);
        } catch (StorageException $exception) {
            return new Response(ProtocolError::storageFailure());
        }

        return new Response('OK');
    }

    private function get(Request $request): Response
    {
        $identifier = $request->value('id');

        if ($identifier === null || $identifier === '') {
            return new Response(ProtocolError::missingIdentifier());
        }

        if (!$this->isValidIdentifier($identifier)) {
            return new Response(ProtocolError::invalidIdentifier());
        }

        try {
            $payload = $this->store->consume($identifier);
        } catch (StorageException $exception) {
            return new Response(ProtocolError::invalidData());
        }

        return new Response($payload ?? ProtocolError::invalidIdentifier());
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $identifier) === 1;
    }
}
