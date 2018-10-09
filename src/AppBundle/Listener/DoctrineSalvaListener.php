<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Event\PolicyEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineSalvaListener extends BaseDoctrineListener
{
    use CurrencyTrait;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var string */
    protected $environment;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct($dispatcher, $environment, LoggerInterface $logger)
    {
        $this->dispatcher = $dispatcher;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $dispatched = [];
        try {
            $document = $eventArgs->getDocument();

            if ($document instanceof SalvaPhonePolicy) {
                /** @var SalvaPhonePolicy $policy */
                $policy = $document;
                if (!$policy->isValidPolicy()) {
                    $this->logger->debug(sprintf('preUpdateDebug invalid policy'));
                    return;
                } elseif (!$policy->isBillablePolicy()) {
                    $this->logger->debug(sprintf('preUpdateDebug not billable policy'));
                    return;
                }
            } elseif ($document instanceof User) {
                if (!$document->hasActivePolicy() && !$document->hasUnpaidPolicy()) {
                    return;
                }
            }

            if ($this->hasDataChanged(
                $eventArgs,
                SalvaPhonePolicy::class,
                ['phone', 'imei', 'premiumInstallments']
            )) {
                /** @var SalvaPhonePolicy $policy */
                $policy = $document;
                if (!in_array($policy->getId(), $dispatched)) {
                    $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                    $dispatched[] = $policy->getId();
                }
            } elseif ($this->hasDataChanged(
                $eventArgs,
                SalvaPhonePolicy::class,
                ['premium'],
                self::COMPARE_PREMIUM
            )) {
                /** @var SalvaPhonePolicy $policy */
                $policy = $document;
                if (!in_array($policy->getId(), $dispatched)) {
                    $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                    $dispatched[] = $policy->getId();
                }
            }

            if ($this->hasDataChanged(
                $eventArgs,
                User::class,
                ['firstName', 'lastName']
            )) {
                /** @var User $user */
                $user = $document;
                foreach ($user->getPolicies() as $policy) {
                    if ($policy instanceof SalvaPhonePolicy &&
                        $policy->isValidPolicy() && $policy->isBillablePolicy()) {
                        if (!in_array($policy->getId(), $dispatched)) {
                            $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                            $dispatched[] = $policy->getId();
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in salva listener', ['exception' => $e]);
        }
    }

    private function triggerEvent(SalvaPhonePolicy $phonePolicy, $eventType)
    {
        $event = new PolicyEvent($phonePolicy);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
