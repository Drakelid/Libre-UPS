<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Builds the weekly email: a summary line, the UPSes with the shortest runtime and the suspect batteries.
 * Pure PHP: all texts come in through $t (the translated "report_mail" strings), so it is easy to test.
 */
final class WeeklyReport
{
    private const ROW_STYLE = [
        'critical' => ' style="background:#f2dede"',
        'warning' => ' style="background:#fcf8e3"',
    ];

    /**
     * @param  array<string, string>  $t
     * @param  array{devices: int, critical: int, warning: int, suspect: int}  $counts
     */
    public static function subject(array $t, array $counts): string
    {
        return strtr($t['subject'], [
            ':suspect' => (string) $counts['suspect'],
            ':critical' => (string) $counts['critical'],
        ]);
    }

    /**
     * Counts devices and severities for the summary line.
     *
     * @param  ReportRow[]  $runtimeRows  One row per device, all devices (not only the ones listed).
     * @return array{devices: int, critical: int, warning: int}
     */
    public static function countRuntime(array $runtimeRows): array
    {
        $counts = ['devices' => count($runtimeRows), 'critical' => 0, 'warning' => 0];
        foreach ($runtimeRows as $row) {
            if ($row->severity === Severity::Critical) {
                $counts['critical']++;
            } elseif ($row->severity === Severity::Warning) {
                $counts['warning']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $t
     * @param  string  $origin  Scheme and host (no trailing slash) put in front of the relative device links, or ''.
     * @param  array{devices: int, critical: int, warning: int, suspect: int}  $counts
     * @param  ReportRow[]  $shortest  Rows for the "shortest runtime" table, shortest first.
     * @param  MatrixRow[]  $suspect  Devices with a suspect battery; cells hold runtime, load and charge.
     */
    public static function html(array $t, string $origin, string $reportUrl, string $generated, array $counts, array $shortest, array $suspect): string
    {
        $summary = implode(' &middot; ', [
            self::e(strtr($t['summary_devices'], [':count' => (string) $counts['devices']])),
            self::e(strtr($t['summary_critical'], [':count' => (string) $counts['critical']])),
            self::e(strtr($t['summary_warning'], [':count' => (string) $counts['warning']])),
            self::e(strtr($t['summary_suspect'], [':count' => (string) $counts['suspect']])),
        ]);

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">';
        $html .= '<h2 style="margin:0 0 4px">'.self::e($t['title']).'</h2>';
        $html .= '<p style="margin:0 0 12px;color:#666">'.self::e(strtr($t['generated'], [':date' => $generated])).'</p>';
        $html .= '<p style="margin:0 0 16px">'.$summary.'</p>';

        $html .= '<h3 style="margin:16px 0 4px">'.self::e($t['suspect_title']).'</h3>';
        $html .= '<p style="margin:0 0 8px;color:#666">'.self::e($t['suspect_help']).'</p>';
        $html .= self::suspectTable($t, $origin, $suspect);

        $html .= '<h3 style="margin:16px 0 8px">'.self::e($t['shortest_title']).'</h3>';
        $html .= self::shortestTable($t, $origin, $shortest);

        if ($reportUrl !== '') {
            $html .= '<p style="margin:16px 0 0"><a href="'.self::e($reportUrl).'">'.self::e($t['open_report']).'</a></p>';
        }

        return $html.'</div>';
    }

    /**
     * @param  array<string, string>  $t
     * @param  ReportRow[]  $rows
     */
    private static function shortestTable(array $t, string $origin, array $rows): string
    {
        if ($rows === []) {
            return '<p>'.self::e($t['none']).'</p>';
        }

        $html = self::tableStart([$t['col_host'], $t['col_location'], $t['col_runtime'], $t['col_status']]);
        foreach ($rows as $row) {
            $html .= '<tr'.(self::ROW_STYLE[$row->severity->value] ?? '').'>'
                .self::cell(self::link($origin, $row->deviceUrl, $row->displayName, $row->hostname))
                .self::cell(self::e($row->location ?? ''))
                .self::cell(self::e($row->valueFormatted))
                .self::cell(self::e($t['severity_'.$row->severity->value] ?? $row->severity->value))
                .'</tr>';
        }

        return $html.'</table>';
    }

    /**
     * @param  array<string, string>  $t
     * @param  MatrixRow[]  $rows
     */
    private static function suspectTable(array $t, string $origin, array $rows): string
    {
        if ($rows === []) {
            return '<p>'.self::e($t['none']).'</p>';
        }

        $html = self::tableStart([$t['col_host'], $t['col_location'], $t['col_runtime'], $t['col_load'], $t['col_charge']]);
        foreach ($rows as $row) {
            $html .= '<tr style="background:#fcf8e3">'
                .self::cell(self::link($origin, $row->deviceUrl, $row->displayName, $row->hostname))
                .self::cell(self::e($row->location ?? ''))
                .self::cell(self::e(($row->cells['runtime'] ?? null)?->valueFormatted ?? ''))
                .self::cell(self::e(($row->cells['load'] ?? null)?->valueFormatted ?? ''))
                .self::cell(self::e(($row->cells['charge'] ?? null)?->valueFormatted ?? ''))
                .'</tr>';
        }

        return $html.'</table>';
    }

    /** @param  string[]  $headings */
    private static function tableStart(array $headings): string
    {
        $html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd"><tr style="background:#eee;text-align:left">';
        foreach ($headings as $heading) {
            $html .= '<th style="border:1px solid #ddd">'.self::e($heading).'</th>';
        }

        return $html.'</tr>';
    }

    private static function cell(string $html): string
    {
        return '<td style="border:1px solid #ddd">'.$html.'</td>';
    }

    private static function link(string $origin, string $path, string $text, string $title): string
    {
        $label = self::e($text);
        if ($path === '') {
            return $label;
        }

        $href = str_starts_with($path, '/') ? $origin.$path : $path;

        return '<a href="'.self::e($href).'" title="'.self::e($title).'">'.$label.'</a>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
