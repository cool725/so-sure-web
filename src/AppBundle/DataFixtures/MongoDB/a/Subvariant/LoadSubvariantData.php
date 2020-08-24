<?php

namespace AppBundle\DataFixtures\MongoDB\a\Subvariant;

use AppBundle\Classes\NoOp;
use AppBundle\Document\Subvariant;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates data for testing tiered policies.
 */
class LoadSubvariantData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @inheritDoc
     */
    public function setContainer(ContainerInterface $container = null)
    {
        NoOp::ignore($container);
    }

    /**
     * @inheritDoc
     */
    public function load(ObjectManager $manager)
    {
        $damage = new Subvariant();
        $damage->setName('damage');
        $damage->setDamage(true);
        $damage->setTheft(false);
        $damage->setLoss(false);
        $damage->setWarranty(false);
        $damage->setExtendedWarranty(false);
        $damage->setNClaims(1);
        $essentials = new Subvariant();
        $essentials->setName('essentials');
        $essentials->setDamage(true);
        $essentials->setTheft(true);
        $essentials->setLoss(true);
        $essentials->setWarranty(true);
        $essentials->setExtendedWarranty(true);
        $essentials->setNClaims(2);
        $manager->persist($damage);
        $manager->persist($essentials);
        $manager->flush();
    }
}
