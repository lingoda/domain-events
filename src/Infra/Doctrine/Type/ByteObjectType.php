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

    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        $value = parent::convertToDatabaseValue($value, $platform);

        if ($platform::class === PostgreSQLPlatform::class) {
            $value = pg_escape_bytea($value);
        }

        return $value;
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
