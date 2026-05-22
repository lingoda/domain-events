<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Query;

use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;

class SkipLockedSqlWalker extends SqlWalker
{
    public function walkSelectStatement(AST\SelectStatement $AST): string
    {
        return self::appendSkipLockedHint(parent::walkSelectStatement($AST));
    }

    public static function appendSkipLockedHint(string $sql): string
    {
        if (str_contains($sql, 'FOR UPDATE')) {
            $sql .= ' SKIP LOCKED';
        }

        return $sql;
    }
}
