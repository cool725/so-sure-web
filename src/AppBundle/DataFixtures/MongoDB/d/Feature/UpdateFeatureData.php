<?php

namespace AppBundle\DataFixtures\MongoDB\d\Feature;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Feature;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class UpdateFeatureData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface|null
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $repo = $manager->getRepository(Feature::class);
        foreach (Feature::$features as $feature) {
            $this->upsertFeature($manager, $feature);
        }
        
        $manager->flush();
    }

    private function upsertFeature($manager, $feature)
    {
        $manager->createQueryBuilder(Feature::class)
        ->findAndUpdate()
        ->upsert(true)
        ->field('name')->equals($feature)

        ->field('name')->set($feature)
        ->getQuery()
        ->execute();
    }
}
