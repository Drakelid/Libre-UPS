<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

enum Severity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public static function fromLimits(?float $value, ?float $low, ?float $lowWarn, ?float $warn, ?float $high): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        if (($low !== null && $value < $low) || ($high !== null && $value > $high)) {
            return self::Critical;
        }

        if (($lowWarn !== null && $value < $lowWarn) || ($warn !== null && $value > $warn)) {
            return self::Warning;
        }

        return self::Ok;
    }

    /** Maps LibreNMS state_generic_value (0 ok, 1 warning, 2 critical) to a severity. */
    public static function fromStateGeneric(?int $generic): self
    {
        return match ($generic) {
            0 => self::Ok,
            1 => self::Warning,
            2 => self::Critical,
            default => self::Unknown,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Unknown => 1,
            self::Ok => 0,
        };
    }
}
