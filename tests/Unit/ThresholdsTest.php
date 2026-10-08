<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\Thresholds;

it('applies critical and warning rules for a class where low is bad', function (): void {
    $t = Thresholds::parse('runtime <10 <20');

    expect($t->severity('runtime', 5.0))->toBe(Severity::Critical)
        ->and($t->severity('runtime', 15.0))->toBe(Severity::Warning)
        ->and($t->severity('runtime', 25.0))->toBe(Severity::Ok);
});

it('treats the boundary value as not matching a strict comparison', function (): void {
    $t = Thresholds::parse('runtime <10 <20');

    expect($t->severity('runtime', 10.0))->toBe(Severity::Warning)
        ->and($t->severity('runtime', 20.0))->toBe(Severity::Ok);
});

it('applies rules for a class where high is bad', function (): void {
    $t = Thresholds::parse('load >90 >75');

    expect($t->severity('load', 95.0))->toBe(Severity::Critical)
        ->and($t->severity('load', 80.0))->toBe(Severity::Warning)
        ->and($t->severity('load', 90.0))->toBe(Severity::Warning)
        ->and($t->severity('load', 70.0))->toBe(Severity::Ok);
});

it('allows a critical rule without a warning rule', function (): void {
    $t = Thresholds::parse('charge <20');

    expect($t->severity('charge', 10.0))->toBe(Severity::Critical)
        ->and($t->severity('charge', 30.0))->toBe(Severity::Ok);
});

it('accepts decimal commas and negative numbers', function (): void {
    $t = Thresholds::parse("runtime <10,5\ntemperature >-5.5");

    expect($t->severity('runtime', 10.2))->toBe(Severity::Critical)
        ->and($t->severity('runtime', 10.6))->toBe(Severity::Ok)
        ->and($t->severity('temperature', -5.0))->toBe(Severity::Critical);
});

it('returns null for classes without a rule so LibreNMS limits can be used', function (): void {
    $t = Thresholds::parse('runtime <10');

    expect($t->severity('load', 99.0))->toBeNull()
        ->and($t->has('runtime'))->toBeTrue()
        ->and($t->has('load'))->toBeFalse();
});

it('is unknown for a null value when a rule exists', function (): void {
    expect(Thresholds::parse('runtime <10')->severity('runtime', null))->toBe(Severity::Unknown);
});

it('ignores comments and blank lines', function (): void {
    $t = Thresholds::parse("# battery rules\n\nruntime <10 <20   # minutes\n   \n");

    expect($t->errors())->toBe([])
        ->and($t->has('runtime'))->toBeTrue();
});

it('has no rules for empty input', function (): void {
    expect(Thresholds::parse(null)->has('runtime'))->toBeFalse()
        ->and(Thresholds::parse('')->errors())->toBe([])
        ->and(Thresholds::empty()->severity('runtime', 1.0))->toBeNull();
});

it('reports invalid lines with their line number and keeps the valid ones', function (): void {
    $t = Thresholds::parse("runtime <10 <20\nthis is not valid\nload >abc\nRuntime <5\ncharge <1 <2 <3\nlast >1");

    expect($t->has('runtime'))->toBeTrue()
        ->and($t->has('last'))->toBeTrue()
        ->and($t->has('load'))->toBeFalse()
        ->and($t->has('charge'))->toBeFalse()
        ->and($t->errors())->toHaveCount(4)
        ->and($t->errors()[0])->toContain('Line 2')
        ->and($t->errors()[1])->toContain('Line 3');
});

it('lets a later line replace an earlier line for the same class', function (): void {
    $t = Thresholds::parse("runtime <10\nruntime <30");

    expect($t->severity('runtime', 20.0))->toBe(Severity::Critical);
});
