<?php

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\BlobType;

/**
 * Workaround for https://github.com/doctrine/orm/issues/4029
 */
class ByteObjectType extends BlobType
{
    public const TYPE = 'byte_object';

    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        $value = serialize($value);

        if (is_a($platform, PostgreSQLPlatform::class)) {
            $value = str_replace(chr(0), '\0', $value);
        }

        return $value;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?object
    {
        if ($value === null) {
            return null;
        }

        $value = is_resource($value) ? stream_get_contents($value) : $value;

        if (is_a($platform, PostgreSQLPlatform::class)) {
            $value = str_replace('\0', chr(0), $value);
        }

        set_error_handler(static function (int $code, string $message): bool {
            throw new \UnexpectedValueException(sprintf('Could not unserialize %s value: %s', self::TYPE, $message));
        });

        try {
            $unserialized = unserialize($value);
        } finally {
            restore_error_handler();
        }

        if ($unserialized !== null && !is_object($unserialized)) {
            throw new \UnexpectedValueException(sprintf('Expected %s to deserialize to an object, got %s', self::TYPE, get_debug_type($unserialized)));
        }

        return $unserialized;
    }

    public function getName(): string
    {
        return self::TYPE;
    }
}
