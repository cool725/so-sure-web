<?php

namespace AppBundle\DataFixtures\MongoDB\b\PlayDevice;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PlayDevice;
use AppBundle\Validator\Constraints\TokenValidator;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

// @codingStandardsIgnoreFile
class LoadPlayDeviceData implements FixtureInterface, ContainerAwareInterface
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
        $row = 0;
        $file = sprintf(
            "%s/../src/AppBundle/DataFixtures/supported_devices.csv",
            $this->container->getParameter('kernel.root_dir')
        );
        $previousData = [];
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $retailBranding = $this->strip($data[0], 150);
                    $marketingName = $this->strip($data[1], 150);
                    $device = $this->strip($data[2], 50);
                    $model = $this->strip($data[3], 100);
                    $combined = sprintf("%s%s%s%s", $retailBranding, $marketingName, $device, $model);

                    if (!in_array($combined, $previousData)) {
                        $this->newPlayDevice($manager, $data[0], $data[1], $data[2], $data[3]);
                        $previousData[] = $combined;
                    } else {
                        /** @var LoggerInterface $logger */
                        $logger = $this->container->get('logger');
                        $logger->warning(sprintf("Duplicate entry for %s %s %s %s", $retailBranding, $marketingName, $device, $model));
                    }
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
        $playDevice = new PlayDevice();
        $playDevice->init(
            $this->strip($retailBranding, 150),
            $this->strip($marketingName, 150),
            $this->strip($device, 50),
            $this->strip($model, 100)
        );
        $manager->persist($playDevice);
    }

    private function strip($data, $length)
    {
        $data = preg_replace('/\\x[a-f0-9]{2,2}/', '', $data);
        $data = str_replace("'", "", $data);
        $data = str_replace("\\t", "", $data);
        $data = str_replace("\\", "", $data);

        $validator = new TokenValidator();

        return $validator->conform(mb_substr($data, 0, $length));
    }
}
