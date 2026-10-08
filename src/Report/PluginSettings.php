<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** Validated plugin settings. Invalid or missing values fall back to safe defaults instead of breaking the page. */
final readonly class PluginSettings
{
    public const DEFAULT_CLASS = 'runtime';

    public const DEFAULT_REFRESH_SECONDS = 300;

    public const DEFAULT_STALE_MINUTES = 30;

    public const LANGUAGES = ['en', 'nb'];

    public function __construct(
        public ?string $defaultType,
        public string $defaultClass,
        public int $defaultLimit,
        public Thresholds $thresholds,
        public int $refreshSeconds,
        public int $staleMinutes,
        public string $language,
        public SuspectRule $suspectRule,
        public ReportSchedule $report,
        public bool $topNav = true,
        public int $batteryLifetimeMonths = BatterySwap::DEFAULT_LIFETIME_MONTHS,
        public int $swapWarnDays = BatterySwap::DEFAULT_WARN_DAYS,
    ) {}

    /** @param  array<string, mixed>  $raw  The settings array stored by LibreNMS (may be empty or contain bad values). */
    public static function fromArray(array $raw): self
    {
        // No device type (null) means all device types. UPSs are not always typed "power" in LibreNMS
        // (a UPS behind a NUT server, for example), so the report does not narrow the type by default.
        $type = self::identifierOrNull($raw['default_type'] ?? null);

        $class = is_scalar($raw['default_class'] ?? null) ? strtolower(trim((string) $raw['default_class'])) : '';
        if (preg_match('/^[a-z0-9_]{1,64}$/', $class) !== 1) {
            $class = self::DEFAULT_CLASS;
        }

        $limit = filter_var($raw['default_limit'] ?? null, FILTER_VALIDATE_INT);
        if ($limit === false || ! in_array($limit, InputParser::LIMITS, true)) {
            $limit = InputParser::DEFAULT_LIMIT;
        }

        $thresholdText = $raw['thresholds'] ?? null;

        $language = is_scalar($raw['language'] ?? null) ? (string) $raw['language'] : 'en';
        if (! in_array($language, self::LANGUAGES, true)) {
            $language = 'en';
        }

        return new self(
            $type,
            $class,
            $limit,
            Thresholds::parse(is_string($thresholdText) ? $thresholdText : null),
            self::refreshSeconds($raw['refresh_seconds'] ?? null),
            self::boundedInt($raw['stale_minutes'] ?? null, 1, 1440, self::DEFAULT_STALE_MINUTES),
            $language,
            new SuspectRule(
                self::boundedFloat($raw['suspect_runtime'] ?? null, 1, 1000, SuspectRule::DEFAULT_MAX_RUNTIME),
                self::boundedFloat($raw['suspect_max_load'] ?? null, 0, 100, SuspectRule::DEFAULT_MAX_LOAD),
                self::boundedFloat($raw['suspect_min_charge'] ?? null, 0, 100, SuspectRule::DEFAULT_MIN_CHARGE),
            ),
            ReportSchedule::fromArray($raw),
            ! in_array($raw['top_nav'] ?? '1', ['0', 0, false], true),
            self::boundedInt($raw['battery_lifetime_months'] ?? null, 6, 240, BatterySwap::DEFAULT_LIFETIME_MONTHS),
            self::boundedInt($raw['swap_warn_days'] ?? null, 0, 730, BatterySwap::DEFAULT_WARN_DAYS),
        );
    }

    /** @return array{type: ?string, class: string, limit: int} Defaults for ReportFilters. */
    public function filterDefaults(): array
    {
        return ['type' => $this->defaultType, 'class' => $this->defaultClass, 'limit' => $this->defaultLimit];
    }

    private static function identifierOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value) === 1 ? $value : null;
    }

    /** 0 turns auto-refresh off; other values are kept between 30 seconds and one hour. */
    private static function refreshSeconds(mixed $raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            return self::DEFAULT_REFRESH_SECONDS;
        }

        if ($value <= 0) {
            return 0;
        }

        return max(30, min(3600, $value));
    }

    private static function boundedFloat(mixed $raw, float $min, float $max, float $default): float
    {
        if (! is_scalar($raw) || ! is_numeric(str_replace(',', '.', (string) $raw))) {
            return $default;
        }

        $value = (float) str_replace(',', '.', (string) $raw);

        return $value < $min || $value > $max ? $default : $value;
    }

    private static function boundedInt(mixed $raw, int $min, int $max, int $default): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
