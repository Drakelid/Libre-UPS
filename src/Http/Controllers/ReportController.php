<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Http\Controllers;

use App\Models\User;
use App\Models\UserPref;
use Drakelid\UpsBattery\Report\CsvFormatter;
use Drakelid\UpsBattery\Report\InputParser;
use Drakelid\UpsBattery\Report\MatrixFilters;
use Drakelid\UpsBattery\Report\MatrixRow;
use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\ReportFilters;
use Drakelid\UpsBattery\Report\SavedViews;
use Drakelid\UpsBattery\Report\SensorReportService;
use Drakelid\UpsBattery\Report\Summary;
use Drakelid\UpsBattery\Report\TooManyRowsException;
use Drakelid\UpsBattery\UpsBatteryProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const VIEWS_PREFERENCE = 'ups-battery.views';

    public function __construct(private readonly SensorReportService $service)
    {
    }

    public function page(Request $request): View
    {
        $settings = $this->settings();

        return view('ups-battery::report', [
            'defaults' => $settings->filterDefaults(),
            'initial' => $request->query(),
            'locale' => $settings->language,
            'refreshSeconds' => $settings->refreshSeconds,
            'staleMinutes' => $settings->staleMinutes,
            'matrixDefaults' => MatrixFilters::DEFAULT_CLASSES,
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $type = InputParser::nullableString($request->query('type'), 'type');
            InputParser::assertIdentifier($type, 'device type');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json($this->service->options($user, $type));
    }

    public function data(Request $request): JsonResponse|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $format = $request->query('format', 'json');
        if (! in_array($format, ['json', 'csv'], true)) {
            return $this->error('Invalid format.');
        }

        try {
            $settings = $this->settings();
            $filters = ReportFilters::fromArray($request->query(), $settings->filterDefaults());
            $this->validateAgainstOptions(
                $this->service->options($user, $filters->type),
                $filters->type,
                $filters->os,
                $filters->group,
                [$filters->class],
            );

            $processed = $this->service->report($user, $filters, $settings->thresholds);
        } catch (InvalidArgumentException|TooManyRowsException $e) {
            return $this->error($e->getMessage());
        }

        if ($format === 'csv') {
            $csv = (new CsvFormatter())->toCsv($processed['rows']);

            return $this->download($csv, sprintf('ups-battery-%s-%s.csv', $filters->class, date('Ymd-Hi')));
        }

        return response()->json([
            'filters' => $filters->toArray(),
            'summary' => Summary::from($processed['summaryRows'], $filters->class === 'state') + ['unit' => $processed['unit']],
            'total' => $processed['total'],
            'rows' => array_map(fn ($row): array => $row->toArray(), $processed['rows']),
        ]);
    }

    public function matrix(Request $request): JsonResponse|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $format = $request->query('format', 'json');
        if (! in_array($format, ['json', 'csv'], true)) {
            return $this->error('Invalid format.');
        }

        try {
            $settings = $this->settings();
            $filters = MatrixFilters::fromArray($request->query(), $settings->filterDefaults());
            $this->validateAgainstOptions(
                $this->service->options($user, $filters->type),
                $filters->type,
                $filters->os,
                $filters->group,
                $filters->classes,
            );

            $result = $this->service->matrix($user, $filters, $settings->thresholds);
        } catch (InvalidArgumentException|TooManyRowsException $e) {
            return $this->error($e->getMessage());
        }

        if ($format === 'csv') {
            $csv = (new CsvFormatter())->matrixToCsv($result['rows'], $filters->classes);

            return $this->download($csv, sprintf('ups-battery-compare-%s.csv', date('Ymd-Hi')));
        }

        return response()->json([
            'filters' => $filters->toArray(),
            'classes' => $result['classes'],
            'total' => $result['total'],
            'rows' => array_map(fn (MatrixRow $row): array => $row->toArray($filters->classes), $result['rows']),
        ]);
    }

    public function views(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['views' => $this->loadViews($user)]);
    }

    public function saveView(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $query = $request->input('query', []);
            if (! is_array($query)) {
                throw new InvalidArgumentException('Invalid view.');
            }

            $views = SavedViews::add($this->loadViews($user), (string) $request->input('name', ''), $query);
            $this->storeViews($user, $views);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['views' => $views]);
    }

    public function deleteView(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $views = SavedViews::remove($this->loadViews($user), (string) $request->input('name', ''));
        $this->storeViews($user, $views);

        return response()->json(['views' => $views]);
    }

    /**
     * Checks every filter value against what exists in the database (and what the user may see).
     *
     * @param  array{types: array<int, array<string, mixed>>, os: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, classes: array<int, array<string, mixed>>}  $options
     * @param  string[]  $classes
     *
     * @throws InvalidArgumentException
     */
    private function validateAgainstOptions(array $options, ?string $type, ?string $os, ?int $group, array $classes): void
    {
        if ($type !== null && ! $this->contains($options['types'], 'value', $type)) {
            throw new InvalidArgumentException("Unknown device type \"$type\".");
        }

        foreach ($classes as $class) {
            if (! $this->contains($options['classes'], 'value', $class)) {
                throw new InvalidArgumentException("Unknown sensor class \"$class\".");
            }
        }

        if ($os !== null && ! $this->contains($options['os'], 'value', $os)) {
            throw new InvalidArgumentException("Unknown OS \"$os\".");
        }

        if ($group !== null && ! $this->contains($options['groups'], 'id', $group)) {
            throw new InvalidArgumentException("Unknown device group \"$group\".");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function contains(array $items, string $key, string|int $needle): bool
    {
        foreach ($items as $item) {
            if ($item[$key] === $needle) {
                return true;
            }
        }

        return false;
    }

    private function settings(): PluginSettings
    {
        return PluginSettings::fromArray(
            app(PluginManagerInterface::class)->getSettings(UpsBatteryProvider::PLUGIN),
        );
    }

    /** @return array<int, array{name: string, query: array<string, string>}> */
    private function loadViews(User $user): array
    {
        return SavedViews::decode(UserPref::getPref($user, self::VIEWS_PREFERENCE));
    }

    /** @param  array<int, array{name: string, query: array<string, string>}>  $views */
    private function storeViews(User $user, array $views): void
    {
        UserPref::setPref($user, self::VIEWS_PREFERENCE, SavedViews::encode($views));
    }

    private function download(string $csv, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function error(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
