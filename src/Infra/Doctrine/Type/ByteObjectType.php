<?php

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ObjectType;

/**
 * Workaround for https://github.com/doctrine/orm/issues/4029
 */
class ByteObjectType extends ObjectType
{
    public const TYPE = 'byte_object';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        $value = parent::convertToDatabaseValue($value, $platform);

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

        return parent::convertToPHPValue($value, $platform);
    }

    public function getBindingType(): int
    {
        return ParameterType::LARGE_OBJECT;
    }

    public function getName(): string
    {
        return self::TYPE;
    }
}
