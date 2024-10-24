<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withParallel()
    ->withPaths([__DIR__ . '/src'])
    ->withCache(__DIR__ . '/.ecs-cache')
    ->withPreparedSets(
        psr12: true,
        common: true,
        symplify: true,
    );
