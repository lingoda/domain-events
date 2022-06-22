<?php

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ObjectType;

class ByteObjectType extends ObjectType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function getBindingType(): int
    {
        return ParameterType::LARGE_OBJECT;
    }

    public function getName(): string
    {
        return 'byte_object';
    }
}
