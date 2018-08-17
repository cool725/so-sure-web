<?php

namespace App\Admin\Reports;

use DateTime;

interface KpiInterface
{
    public function collectWeekRanges(DateTime $now, int $numWeeks): array;
    public function reportForPeriod(array $dateRange): array;
    public function getReportsByWeekRanges(array $weekRanges, bool $clearCache = false): array;
}
