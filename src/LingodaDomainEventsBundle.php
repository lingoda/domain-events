<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle;

use Doctrine\DBAL\Types\Type;
use Lingoda\DomainEventsBundle\Infra\Doctrine\Type\ByteObjectType;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class LingodaDomainEventsBundle extends Bundle
{
    public function __construct()
    {
        if (!Type::hasType(ByteObjectType::TYPE)) {
            Type::addType(ByteObjectType::TYPE, ByteObjectType::class);
        }
    }
}
