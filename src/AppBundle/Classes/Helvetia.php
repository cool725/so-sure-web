<?php

namespace AppBundle\Classes;

use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Policy;

/**
 * Contains parameters of our agreement with underwriter Helvetia.
 */
class Helvetia
{
    const YEARLY_BROKER_COMMISSION = 0.72;
    const MONTHLY_BROKER_COMMISSION = 0.06;
    const TIMEZONE = "Europe/Zurich";
}
