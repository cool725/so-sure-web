<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Company;

class CompanyEvent extends Event
{
    const EVENT_CREATED = 'event.company.created';

    /** @var Company */
    protected $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function getCompany()
    {
        return $this->company;
    }
}
