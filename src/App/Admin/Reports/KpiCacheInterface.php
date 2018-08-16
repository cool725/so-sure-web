<?php

namespace App\Admin\Reports;

use DateTime;

interface KpiCacheInterface
{
    public function clearCacheForPeriod(DateTime $startOfDay, DateTime $endOfDay);
}
