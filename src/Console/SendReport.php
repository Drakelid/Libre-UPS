<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Console;

use Drakelid\UpsBattery\Report\MatrixFilters;
use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\ReportFilters;
use Drakelid\UpsBattery\Report\ReportSchedule;
use Drakelid\UpsBattery\Report\SensorReportService;
use Drakelid\UpsBattery\Report\UpsFilters;
use Drakelid\UpsBattery\Report\Urls;
use Drakelid\UpsBattery\Report\WeeklyReport;
use Drakelid\UpsBattery\UpsBatteryProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Util\Mail;
use Throwable;

/**
 * Emails the weekly report. LibreNMS' scheduler runs it on the day and time set in the plugin settings,
 * and it can be run by hand: php artisan ups-battery:report --dry-run
 */
class SendReport extends Command
{
    protected $signature = 'ups-battery:report
        {--to= : Comma separated recipients (default: the recipients in the plugin settings)}
        {--dry-run : Print the report instead of sending it}';

    protected $description = 'Email the weekly UPS battery report (shortest runtime, suspect batteries, battery swaps due)';

    public function handle(SensorReportService $service, PluginManagerInterface $plugins): int
    {
        $settings = PluginSettings::fromArray($plugins->getSettings(UpsBatteryProvider::PLUGIN));
        $dryRun = (bool) $this->option('dry-run');

        $to = $this->option('to');
        $recipients = is_string($to) && $to !== '' ? ReportSchedule::parseRecipients($to) : $settings->report->recipients;
        if ($recipients === [] && ! $dryRun) {
            $this->error('No valid recipients. Set them in the plugin settings or pass --to=name@example.com.');

            return self::FAILURE;
        }

        $t = trans('ups-battery::ups-battery.report_mail', [], $settings->language);
        if (! is_array($t)) {
            $this->error('The report texts are missing from the language files.');

            return self::FAILURE;
        }

        try {
            $defaults = $settings->filterDefaults();

            $runtime = $service->report(
                null,
                ReportFilters::fromArray(['class' => 'runtime', 'aggregate' => 'min', 'limit' => (string) $settings->report->top], $defaults),
                $settings->thresholds,
            );
            $suspect = $service->matrix(
                null,
                MatrixFilters::fromArray(['classes' => 'runtime,load,charge', 'suspect' => '1', 'limit' => '0'], $defaults),
                $settings->thresholds,
                $settings->suspectRule,
            );
            $ups = $service->ups(null, UpsFilters::fromArray(['sort' => 'swap', 'limit' => '0'], $defaults), $settings);
        } catch (InvalidArgumentException $e) {
            // Typically: the device type or metric in the plugin settings does not exist (any more).
            $this->error('Could not build the report: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            Log::error('UPS Battery weekly report failed: '.$e->getMessage());
            $this->error('Could not build the report: '.$e->getMessage());

            return self::FAILURE;
        }

        $counts = WeeklyReport::countRuntime($runtime['summaryRows']) + ['suspect' => count($suspect['rows'])];
        $origin = $this->origin();
        $html = WeeklyReport::html(
            $t,
            $origin,
            $origin.Urls::relative(route('ups-battery.report')),
            now()->format('Y-m-d H:i'),
            $counts,
            $runtime['rows'],
            $suspect['rows'],
            WeeklyReport::swapsDue($ups['rows'], $settings->swapWarnDays),
            $settings->swapWarnDays,
        );
        $subject = WeeklyReport::subject($t, $counts);

        if ($dryRun) {
            $this->line($subject);
            $this->line($html);

            return self::SUCCESS;
        }

        try {
            if (! Mail::send(implode(', ', $recipients), $subject, $html, true, false, false)) {
                $this->error('LibreNMS could not send the email. Check the email settings under Global Settings > Alerting.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            Log::error('UPS Battery weekly report could not be sent: '.$e->getMessage());
            $this->error('Could not send the email: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Report sent to '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }

    /** Scheme, host and port of this LibreNMS (no path), for the links in the email. */
    private function origin(): string
    {
        $parts = parse_url((string) url('/'));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        if (in_array($parts['host'], ['localhost', '127.0.0.1'], true)) {
            $this->warn('APP_URL is not set in LibreNMS .env, so links in the email point to '.$parts['host'].'.');
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
