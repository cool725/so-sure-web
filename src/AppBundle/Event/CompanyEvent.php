<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\BaseCompany;

class CompanyEvent extends Event
{
    const EVENT_CREATED = 'event.company.created';

    /** @var BaseCompany */
    protected $company;

    public function __construct(BaseCompany $company)
    {
        $this->company = $company;
    }

    public function getCompany()
    {
        return $this->company;
    }
}
