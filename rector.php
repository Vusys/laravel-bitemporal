<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        SetList::PRIVATIZATION,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    // These factory / framework-override methods carry phpdoc generics
    // (BitemporalBuilder<static>, BitemporalMany<TRelated, $this>, …). A native
    // return type would erase the generic for Larastan, so leave them untyped.
    ->withSkip([
        ReturnTypeFromReturnNewRector::class => [
            __DIR__.'/src/Bitemporal.php',
            __DIR__.'/src/Concerns/HasBitemporalRelations.php',
        ],
    ]);
