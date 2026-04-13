<?php

declare(strict_types = 1);

namespace Lingoda\DomainEventsBundle\Infra\Doctrine\Query;

use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;

class SkipLockedSqlWalker extends SqlWalker
{
    /**
     * @return string
     */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
        $sql = parent::walkSelectStatement($AST);

        if (str_contains($sql, 'FOR UPDATE')) {
            $sql .= ' SKIP LOCKED';
        }

        return $sql;
    }
}
