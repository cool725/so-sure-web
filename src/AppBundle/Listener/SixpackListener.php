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

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param SixpackService  $sixpack
     * @param LoggerInterface $logger
     */
    public function __construct(SixpackService $sixpack, LoggerInterface $logger)
    {
        $this->sixpack = $sixpack;
        $this->logger = $logger;
    }

    /**
     * @param PolicyEvent $event
     * @throws \Exception
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        \AppBundle\Classes\NoOp::ignore([$event]);

        foreach (SixpackService::$purchaseConversionSimple as $experiment) {
            try {
                $this->sixpack->convert($experiment);
            } catch (\Exception $e) {
                $this->logger->warning(sprintf('Failed to convert %s', $experiment), ['exception' => $e]);
            }
        }

        foreach (SixpackService::$purchaseConversionKpi as $experiment) {
            try {
                $this->sixpack->convert($experiment, SixpackService::KPI_POLICY_PURCHASE);
            } catch (\Exception $e) {
                $this->logger->warning(
                    sprintf('Failed to convert kpi-purchase %s', $experiment),
                    ['exception' => $e]
                );
            }
        }
    }
}
