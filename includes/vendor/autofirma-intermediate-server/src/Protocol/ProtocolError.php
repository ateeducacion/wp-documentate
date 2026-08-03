<?php

declare(strict_types=1);

namespace Erseco\AutoFirma\IntermediateServer\Protocol;

final class ProtocolError
{
    private const MESSAGES = [
        'ERR-00' => 'No se ha indicado código de operación',
        'ERR-01' => 'Código de operación no soportado',
        'ERR-02' => 'No se han proporcionado los datos de la operación',
        'ERR-05' => 'No se ha proporcionado un identificador para los datos',
        'ERR-06' => 'El identificador para los datos es inválido',
        'ERR-07' => 'Los datos solicitados o enviados son inválidos',
        'ERR-18' => 'No se ha podido enviar la firma generada a la Web de origen',
        'ERR-20' => 'No se ha indicado la versión de la sintaxis de la operación',
    ];

    private function __construct()
    {
    }

    public static function missingOperation(): string
    {
        return self::format('ERR-00');
    }

    public static function unsupportedOperation(): string
    {
        return self::format('ERR-01');
    }

    public static function missingData(): string
    {
        return self::format('ERR-02');
    }

    public static function missingIdentifier(): string
    {
        return self::format('ERR-05');
    }

    public static function invalidIdentifier(): string
    {
        return self::format('ERR-06');
    }

    public static function invalidData(): string
    {
        return self::format('ERR-07');
    }

    public static function storageFailure(): string
    {
        return self::format('ERR-18');
    }

    public static function missingSyntaxVersion(): string
    {
        return self::format('ERR-20');
    }

    private static function format(string $code): string
    {
        return $code . ':=' . self::MESSAGES[$code];
    }
}
