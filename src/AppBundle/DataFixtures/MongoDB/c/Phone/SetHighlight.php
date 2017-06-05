<?php

namespace AppBundle\DataFixtures\MongoDB\b\PlayDevice;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class SetHighlight implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $repo = $manager->getRepository(Phone::class);
        $data = [
            'Samsung' => [
                'Galaxy S8', 'Galaxy S8+', 'Galaxy S7', 'Galaxy S7 Edge', 'Galaxy S6', 'Galaxy S6 Edge'
            ]
        ];
        foreach ($data as $make => $models) {
            foreach ($data[$make] as $model) {
                $phones = $repo->findBy(['make' => $make, 'model' => $model]);
                foreach ($phones as $phone) {
                    $phone->setHighlight(true);
                }
            }
        }
        $manager->flush();
    }
}
