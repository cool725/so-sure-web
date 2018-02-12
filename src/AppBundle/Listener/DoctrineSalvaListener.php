<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use AppBundle\Document\CurrencyTrait;

class DoctrineSalvaListener
{
    use CurrencyTrait;

    /** @var EventDispatcher */
    protected $dispatcher;

    /** @var string */
    protected $environment;

    protected $logger;

    public function __construct($dispatcher, $environment, $logger)
    {
        $this->dispatcher = $dispatcher;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        try {
            $dispatched = [];
            $document = $eventArgs->getDocument();

            if ($document instanceof SalvaPhonePolicy) {
                $policy = $document;
                if (!$policy->isValidPolicy()) {
                    $this->logger->debug(sprintf('preUpdateDebug invalid policy'));
                    return;
                } elseif (!$policy->isBillablePolicy()) {
                    $this->logger->debug(sprintf('preUpdateDebug not billable policy'));
                    return;
                }

                $fields = [
                    'phone',
                    'imei',
                    'premium',
                    'premiumInstallments',
                ];
                foreach ($fields as $field) {
                    $changed = false;

                    if ($eventArgs->hasChangedField($field)) {
                        if ($field == 'premium') {
                            $oldPremium = $eventArgs->getOldValue('premium');
                            $newPremium = $eventArgs->getNewValue('premium');
                            if (!$oldPremium && !$newPremium) {
                                $changed = false;
                            } elseif ((!$oldPremium && $newPremium)
                                || !$this->areEqualToTwoDp($oldPremium->getGwp(), $newPremium->getGwp())
                                || !$this->areEqualToTwoDp($oldPremium->getIpt(), $newPremium->getIpt())
                                || !$this->areEqualToTwoDp($oldPremium->getIptRate(), $newPremium->getIptRate())) {
                                $changed = true;
                            }
                        } else {
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $this->logger->debug(sprintf('preUpdateDebug changedfield : %s', $field));
                        if (!in_array($policy->getId(), $dispatched)) {
                            $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                            $dispatched[] = $policy->getId();
                        }
                    }
                }
            }

            if ($document instanceof User) {
                if (!$document->hasActivePolicy() && !$document->hasUnpaidPolicy()) {
                    return;
                }
                $fields = [
                    'firstName',
                    'lastName',
                ];
                foreach ($fields as $field) {
                    if ($eventArgs->hasChangedField($field)) {
                        foreach ($document->getPolicies() as $policy) {
                            if ($policy instanceof SalvaPhonePolicy &&
                                $policy->isValidPolicy() && $policy->isBillablePolicy()) {
                                if (!in_array($policy->getId(), $dispatched)) {
                                    $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                                    $dispatched[] = $policy->getId();
                                }
                            }
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
