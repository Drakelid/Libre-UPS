<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * A battery is suspect when runtime is short even though the UPS is lightly loaded and fully charged:
 * runtime below $maxRuntime minutes, load at most $maxLoad percent and charge at least $minCharge percent.
 */
final readonly class SuspectRule
{
    public const DEFAULT_MAX_RUNTIME = 10.0;

    public const DEFAULT_MAX_LOAD = 30.0;

    public const DEFAULT_MIN_CHARGE = 95.0;

    public function __construct(
        public float $maxRuntime = self::DEFAULT_MAX_RUNTIME,
        public float $maxLoad = self::DEFAULT_MAX_LOAD,
        public float $minCharge = self::DEFAULT_MIN_CHARGE,
    ) {}
}
