<?php

namespace AppBundle\Listener;

use AppBundle\Service\PolicyService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Policy;

class PolicyListener
{
    /** @var PolicyService */
    protected $policyService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param PolicyService   $policyService
     * @param LoggerInterface $logger
     */
    public function __construct(
        PolicyService $policyService,
        LoggerInterface $logger
    ) {
        $this->policyService = $policyService;
        $this->logger = $logger;
    }

    /**
     * @param ConnectionEvent $event
     */
    public function onConnectionReducedEvent(ConnectionEvent $event)
    {
        $connection = $event->getConnection();
        // There are cases where a connection value might be reduced or eliminated:
        // 1) Policy cancellation
        // 2) Policy is not renewed
        // 3) Policy is renewed, but connection is not renewed and so value is dropped/removed

        // Claims should not affect the connection value itself, but rather impact on the pot value

        // The linked policy should be the policy that was actually cancelled/not renewed/etc
        // so inversely, if the source policy is active/unpaid, its the connection we are not interested
        // in notifying about
        if (!in_array($connection->getSourcePolicy()->getStatus(), [
            Policy::STATUS_ACTIVE,
            Policy::STATUS_UNPAID,
        ])) {
            return;
        }

        $this->policyService->connectionReduced($connection);
    }
}
