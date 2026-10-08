<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use RuntimeException;

final class TooManyRowsException extends RuntimeException
{
    public function __construct(int $limit = SensorReportService::MAX_ROWS)
    {
        parent::__construct("Too many sensors (more than $limit), narrow the filters.");
    }
}
