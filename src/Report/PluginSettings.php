<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** Validated plugin settings. Invalid or missing values fall back to safe defaults instead of breaking the page. */
final readonly class PluginSettings
{
    public const DEFAULT_TYPE = 'power';

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
    ) {
    }

    /** @param  array<string, mixed>  $raw  The settings array stored by LibreNMS (may be empty or contain bad values). */
    public static function fromArray(array $raw): self
    {
        // An empty form field is stored as null, which means "all device types".
        // Only fall back to the default type when the setting was never saved.
        $type = array_key_exists('default_type', $raw)
            ? self::identifierOrDefault($raw['default_type'])
            : self::DEFAULT_TYPE;

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
        );
    }

    /** @return array{type: ?string, class: string, limit: int} Defaults for ReportFilters. */
    public function filterDefaults(): array
    {
        return ['type' => $this->defaultType, 'class' => $this->defaultClass, 'limit' => $this->defaultLimit];
    }

    private static function identifierOrDefault(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return self::DEFAULT_TYPE;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value) === 1 ? $value : self::DEFAULT_TYPE;
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

    private static function boundedInt(mixed $raw, int $min, int $max, int $default): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
