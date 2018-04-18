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

        // $policy = $event->getPolicy();
        //$this->sixpack->convert(SixpackService::EXPERIMENT_LANDING_HOME);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_CPC_QUOTE_MANUFACTURER);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_HOMEPAGE_PHONE_IMAGE);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_QUOTE_SLIDER);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_PYG_HOME);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_QUOTE_SIMPLE_COMPLEX_SPLIT);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_QUOTE_SIMPLE_SPLIT);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_CPC_MANUFACTURER_WITH_HOME);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_HOMEPAGE_V1_V2);
        //$this->sixpack->convert(SixpackService::EXPERIMENT_HOMEPAGE_V1_V2OLD_V2NEW);
        // $this->sixpack->convert(SixpackService::EXPERIMENT_FUNNEL_V1_V2);
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_QUOTE_INTERCOM_PURCHASE,
            SixpackService::KPI_POLICY_PURCHASE
        );
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_HOMEPAGE_AA_V2,
            SixpackService::KPI_POLICY_PURCHASE
        );
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_MOBILE_SEARCH_DROPDOWN,
            SixpackService::KPI_POLICY_PURCHASE
        );
        /*
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_MONEY_UNBOUNCE
        );
        */
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_CPC_QUOTE_HOMEPAGE
        );
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_STEP_3
        );
        $this->sixpack->convert(
            SixpackService::EXPERIMENT_DEFACTO,
            SixpackService::KPI_POLICY_PURCHASE
        );
    }
}
