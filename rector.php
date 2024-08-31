<?php

declare(strict_types = 1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Symfony\CodeQuality\Rector\ClassMethod\RemoveUnusedRequestParamRector;
use Rector\Symfony\Set\SymfonySetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);
    $rectorConfig->rule(ReadOnlyClassRector::class);
    $rectorConfig->rule(AddTypeToConstRector::class);
    $rectorConfig->sets([
        PHPUnitSetList::PHPUNIT_100,
        DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES,
        SymfonySetList::SYMFONY_64,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
    $rectorConfig->skip([
        RemoveUnusedRequestParamRector::class
    ]);
};
