<?php

namespace App\Admin\Reports;

use DateTime;

interface KpiInterface
{
    public function reportForPeriod(DateTime $startOfDay, DateTime $endOfDay): array;
}
