<?php

namespace AppBundle\DataFixtures\MongoDB\c\PlayDevice;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PlayDevice;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class UpdatePlayDeviceData implements FixtureInterface, ContainerAwareInterface
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
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        $repo = $manager->getRepository(PlayDevice::class);
        $row = 0;
        $file = sprintf(
            "%s/../src/AppBundle/DataFixtures/supported_devices.csv",
            $this->container->getParameter('kernel.root_dir')
        );
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $this->newPlayDevice($manager, $data[0], $data[1], $data[2], $data[3]);
                }
                if ($row % 1000 == 0) {
                    $manager->flush();
                }
                $row = $row + 1;
            }
            fclose($handle);
        }

        $manager->flush();
    }

    private function newPlayDevice($manager, $retailBranding, $marketingName, $device, $model)
    {
        $manager->createQueryBuilder(PlayDevice::class)
        ->findAndUpdate()
        ->upsert(true)
        ->field('retailBranding')->equals($this->strip($retailBranding))
        ->field('marketingName')->equals($this->strip($marketingName))
        ->field('device')->equals($this->strip($device))
        ->field('model')->equals($this->strip($model))

        ->field('retailBranding')->set($this->strip($retailBranding))
        ->field('marketingName')->set($this->strip($marketingName))
        ->field('device')->set($this->strip($device))
        ->field('model')->set($this->strip($model))
        ->getQuery()
        ->execute();
    }

    private function strip($data)
    {
        $data = preg_replace('/\\x[a-f0-9]{2,2}/', '', $data);
        $data = str_replace("'", "", $data);
        $data = str_replace("\\t", "", $data);
        $data = str_replace("\\", "", $data);

        return $data;
    }
}
