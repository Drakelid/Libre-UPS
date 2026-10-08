<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Severity;

it('is unknown without a value', function (): void {
    expect(Severity::fromLimits(null, 5.0, 10.0, 80.0, 95.0))->toBe(Severity::Unknown);
});

it('is critical below the low limit', function (): void {
    expect(Severity::fromLimits(4.0, 5.0, 10.0, null, null))->toBe(Severity::Critical);
});

it('is critical above the high limit', function (): void {
    expect(Severity::fromLimits(96.0, null, null, 80.0, 95.0))->toBe(Severity::Critical);
});

it('is a warning between the low limit and the low warning limit', function (): void {
    expect(Severity::fromLimits(7.0, 5.0, 10.0, null, null))->toBe(Severity::Warning);
});

it('is a warning between the high warning limit and the high limit', function (): void {
    expect(Severity::fromLimits(90.0, null, null, 80.0, 95.0))->toBe(Severity::Warning);
});

it('is ok inside all limits', function (): void {
    expect(Severity::fromLimits(20.0, 5.0, 10.0, 80.0, 95.0))->toBe(Severity::Ok);
});

it('is ok when no limits are set', function (): void {
    expect(Severity::fromLimits(20.0, null, null, null, null))->toBe(Severity::Ok);
});

it('treats values exactly on a limit as inside it', function (): void {
    expect(Severity::fromLimits(5.0, 5.0, null, null, null))->toBe(Severity::Ok)
        ->and(Severity::fromLimits(95.0, null, null, null, 95.0))->toBe(Severity::Ok);
});

it('maps generic state values', function (): void {
    expect(Severity::fromStateGeneric(0))->toBe(Severity::Ok)
        ->and(Severity::fromStateGeneric(1))->toBe(Severity::Warning)
        ->and(Severity::fromStateGeneric(2))->toBe(Severity::Critical)
        ->and(Severity::fromStateGeneric(3))->toBe(Severity::Unknown)
        ->and(Severity::fromStateGeneric(-1))->toBe(Severity::Unknown)
        ->and(Severity::fromStateGeneric(null))->toBe(Severity::Unknown);
});

it('ranks severities for sorting', function (): void {
    expect(Severity::Critical->rank())->toBe(3)
        ->and(Severity::Warning->rank())->toBe(2)
        ->and(Severity::Unknown->rank())->toBe(1)
        ->and(Severity::Ok->rank())->toBe(0);
});
