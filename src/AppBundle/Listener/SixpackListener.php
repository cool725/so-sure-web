<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\SixpackService;
use Psr\Log\LoggerInterface;

class SixpackListener
{
    /** @var SixpackService */
    protected $sixpack;

    /**
     * @param SixpackService $sixpack
     */
    public function __construct(SixpackService $sixpack)
    {
        $this->sixpack = $sixpack;
    }

    /**
     * @param PolicyEvent $event
     * @throws \Exception
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        \AppBundle\Classes\NoOp::ignore([$event]);

        foreach (SixpackService::$purchaseConversionSimple as $experiment) {
            $this->sixpack->convert($experiment);
        }
        foreach (SixpackService::$purchaseConversionKpi as $experiment) {
            $this->sixpack->convert($experiment, SixpackService::KPI_POLICY_PURCHASE);
        }
    }
}
