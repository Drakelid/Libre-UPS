<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class CsvFormatter
{
    private const BOM = "\xEF\xBB\xBF";

    private const SEPARATOR = ';';

    private const EOL = "\r\n";

    /** @return string[] */
    public function header(): array
    {
        return ['hostname', 'display_name', 'location', 'os', 'sensor', 'value', 'unit', 'severity', 'last_updated'];
    }

    /** @return string[] Unquoted cell values (formula injection already neutralised). */
    public function line(ReportRow $row): array
    {
        return [
            $this->guard($row->hostname),
            $this->guard($row->displayName),
            $this->guard($row->location ?? ''),
            $this->guard($row->os),
            $this->guard($row->sensorDescr),
            $row->value === null ? '' : $this->formatNumber($row->value),
            $this->guard($row->unit),
            $row->severity->value,
            $row->lastUpdate ?? '',
        ];
    }

    /** @param  ReportRow[]  $rows */
    public function toCsv(array $rows): string
    {
        $out = self::BOM.$this->encode($this->header());

        foreach ($rows as $row) {
            $out .= $this->encode($this->line($row));
        }

        return $out;
    }

    /**
     * @param  string[]  $classes
     * @return string[]
     */
    public function matrixHeader(array $classes): array
    {
        $header = ['hostname', 'display_name', 'location', 'os'];
        foreach ($classes as $class) {
            $header[] = $class;
            $header[] = $class.'_severity';
        }

        return $header;
    }

    /**
     * @param  string[]  $classes
     * @return string[]
     */
    public function matrixLine(MatrixRow $row, array $classes): array
    {
        $line = [
            $this->guard($row->hostname),
            $this->guard($row->displayName),
            $this->guard($row->location ?? ''),
            $this->guard($row->os),
        ];

        foreach ($classes as $class) {
            $cell = $row->cells[$class] ?? null;
            $line[] = $cell === null || $cell->value === null ? '' : $this->formatNumber($cell->value);
            $line[] = $cell === null ? '' : $cell->severity->value;
        }

        return $line;
    }

    /**
     * @param  MatrixRow[]  $rows
     * @param  string[]  $classes
     */
    public function matrixToCsv(array $rows, array $classes): string
    {
        $out = self::BOM.$this->encode($this->matrixHeader($classes));

        foreach ($rows as $row) {
            $out .= $this->encode($this->matrixLine($row, $classes));
        }

        return $out;
    }

    /** @param  string[]  $cells */
    private function encode(array $cells): string
    {
        return implode(self::SEPARATOR, array_map($this->quote(...), $cells)).self::EOL;
    }

    private function quote(string $cell): string
    {
        if (strpbrk($cell, ";\"\r\n") === false) {
            return $cell;
        }

        return '"'.str_replace('"', '""', $cell).'"';
    }

    /** Prefix a quote to text that spreadsheets would otherwise evaluate as a formula. */
    private function guard(string $cell): string
    {
        if ($cell === '' || strpbrk($cell[0], '=+-@') === false) {
            return $cell;
        }

        if (preg_match('/^[-+]?\d+([.,]\d+)?$/', $cell) === 1) {
            return $cell;
        }

        return "'".$cell;
    }

    /** Decimal comma, no thousands separator, no trailing zeros. */
    private function formatNumber(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        if ($text === '' || $text === '-') {
            $text = '0';
        }

        return str_replace('.', ',', $text);
    }
}
