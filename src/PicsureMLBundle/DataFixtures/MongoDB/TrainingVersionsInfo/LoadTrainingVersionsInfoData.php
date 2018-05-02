<?php

namespace PicsureMLBundle\DataFixtures\MongoDB\TrainingVersionsInfo;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use PicsureMLBundle\Document\TrainingVersionsInfo;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;

// @codingStandardsIgnoreFile
class LoadTrainingVersionsInfoData implements FixtureInterface, ContainerAwareInterface
{
     /**
     * @var ContainerInterface|null
     */
    private $container;

    private $faker;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->newTrainingVersionsInfo($manager);
        $manager->flush();
    }

    private function newTrainingVersionsInfo($manager)
    {
        $versionInfo = new TrainingVersionsInfo();
        $versionInfo->addVersion(1);
        $versionInfo->setLatestVersion(1);
        $manager->persist($versionInfo);
    }
}
