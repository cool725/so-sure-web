<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone;

use AppBundle\Document\PolicyTerms;
use AppBundle\Service\MailerService;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Repository\PolicyTermsRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouterInterface;

// @codingStandardsIgnoreFile
abstract class LoadPhoneData implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface|null
     */
    protected $container;

    protected $expectedImportException;

    public function setExpectedImportException()
    {
        $this->expectedImportException = true;
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function loadCsv(
        ObjectManager $manager,
        $filename,
        $date = null,
        $relativePath = 'src/AppBundle/DataFixtures/PhoneData'
    ) {
        if (!$this->container) {
            throw new \Exception('missing container');
        }

        /** @var DocumentManager $dm */
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        /** @var PolicyTermsRepository $policyTermsRepo */
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findLatestTerms();

        if (!$date) {
            $date = new \DateTime('2016-01-01');
        }
        $file = sprintf(
            "%s/../%s/%s",
            $this->container->getParameter('kernel.root_dir'),
            $relativePath,
            $filename
        );
        $row = 0;
        $newPhones = [];
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    if ($phone = $this->newPhoneFromRow($manager, $data, $date, $latestTerms)) {
                        $newPhones[$phone->getModelMemory()] = $phone;
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

        $row = 0;
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $this->setSuggestedReplacement($manager, $data);
                }
                $row = $row + 1;
            }
            fclose($handle);
        }

        $manager->flush();

        $this->notifyNewPhones($newPhones);
    }

    private function notifyNewPhones($newPhones)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }

        /** @var MailerService $mailer */
        $mailer = $this->container->get('app.mailer');

        $env = $this->container->getParameter('kernel.environment');
        if ($env != 'prod') {
            return;
        }

        $lines = [];
        $linesWithPrice = [];
        $linesWithDevice = [];
        foreach ($newPhones as $device => $phone) {
            /** @var Phone $phone */
            $lines[] = sprintf('"%s"', $phone);
            $linesWithPrice[] = sprintf(
                '"%s for £%0.2f / month',
                $phone,
                $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMonthlyPremiumPrice()
            );
            $linesWithDevice[] = sprintf('"%s %s" as "%s"', $phone->getMake(), $phone->getModel(), $device);
        }

        $this->emailRecipero($mailer, $linesWithDevice);
        $this->emailComparisonCreator($mailer, $lines);
        $this->emailSoSure($mailer, $linesWithPrice);
    }

    private function emailRecipero(MailerService $mailer, $lines)
    {
        $body = sprintf(
            'Please add the following modelreferences to the make/model checks<br><br>%s',
            implode('<br>', $lines)
        );
        $mailer->send(
            'New Model References',
            'support@recipero.com',
            $body,
            null,
            null,
            'tech+ops@so-sure.com',
            'tech@so-sure.com'
        );
    }

    private function emailSoSure(MailerService $mailer, $lines)
    {
        // @codingStandardsIgnoreStart
        $body = sprintf(
            'The following phones have been added to the so-sure site. <ul><li>New models should be considered if the high in-demand flag is required</li><li>New advertising can be added.</li></ul><br><br>%s',
            implode('<br>', $lines)
        );
        // @codingStandardsIgnoreEnd
        $mailer->send(
            'New Phones have been added',
            ['marketing@so-sure.com', 'tech@so-sure.com'],
            $body,
            null,
            null,
            'tech+ops@so-sure.com',
            'tech@so-sure.com'
        );
    }

    private function emailComparisonCreator(MailerService $mailer, $lines)
    {
        // @codingStandardsIgnoreStart
        $body = sprintf(
            'The following phones have been added to the so-sure site. Can you please provide your Gadget ID\'s if available, or let us know once they have been added to your system? <br><br>%s',
            implode('<br>', $lines)
        );
        // @codingStandardsIgnoreEnd
        $mailer->send(
            'Request for Gadget List ID',
            ['matt@comparisoncreator.com','ellie@ComparisonCreator.com','marketing@so-sure.com'],
            $body,
         null,
         null,
        'tech+ops@so-sure.com',
        'support@wearesosure.com'
        );
    }

    private function setSuggestedReplacement($manager, $data)
    {
        if (!$data[24]) {
            return;
        }

        $phoneRepo = $manager->getRepository(Phone::class);
        $replacementQuery = ['make' => trim($data[24]), 'model' => trim($data[25]), 'memory' => (int)trim($data[26])];
        /** @var Phone $replacement */
        $replacement = $phoneRepo->findOneBy($replacementQuery);
        if ($replacement) {
            $phone = $phoneRepo->findOneBy(['make' => $data[0], 'model' => $data[1], 'memory' => (float)$data[3]]);
            if (!$phone) {
                throw new \Exception(sprintf(
                    'Unable to find %s %s %s',
                    $data[0],
                    $data[1],
                    $data[3]
                ));
            }
            $phone->setSuggestedReplacement($replacement);
        }
    }

    protected function newPhoneFromRow($manager, $data, $date, $latestTerms)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }

        try {
            if (!$data[0] || !$data[1]) {
                return;
            }
            // price
            $premium = 0;
            if ($data[5]) {
                $premium = $data[5] + 1.5;
            }

            $devices = str_getcsv($data[4], ",", "'");
            foreach ($devices as $device) {
                if (mb_stripos($device, "‘") !== false || mb_stripos($device, "’") !== false) {
                    throw new \Exception(sprintf('Invalid apple quote for device %s', $device));
                } elseif (mb_strlen(trim($device)) == 0) {
                    throw new \Exception(sprintf('Invalid device for %s %s', $data[0], $data[1]));
                }
            }
            if (count($devices) == 0) {
                throw new \Exception(sprintf('Invalid device for %s %s', $data[0], $data[1]));
            }

            // check if the phone is already in the database
            $repo = $manager->getRepository(Phone::class);

            foreach ($repo->findMatchingMakes($data[0]) as $make) {
                if ($make !== $data[0]) {
                    throw new \Exception(sprintf(
                        "The device make (%s) is in the database but with using different letter casing! (%s)",
                        $make,
                        $data[0]
                    ));
                }
            }

            foreach ($repo->findMatchingModels($data[0], $data[1]) as $model) {
                if ($model !== $data[1]) {
                    throw new \Exception(sprintf(
                            "The model (%s) is in the database but with using different letter casing! (%s)",
                        $model,
                        $data[1]
                    ));
                }
            }

            if ($repo->alreadyExists($data[0], $data[1], $data[3])) {
                throw new \Exception(sprintf('The device %s / %s / %sGB is already in the database', $data[0], $data[1], $data[3]));
            }

            $phone = new Phone();
            $phone->init(
                $data[0], // $make
                $data[1], // $model
                $premium, // $premium
                $latestTerms, // $latestTerms
                $data[3], // $memory
                $devices, // $devices
                str_replace('£', '', trim($data[7])), // $initialPrice
                str_replace('£', '', trim($data[6])), // $replacementPrice
                $data[8], // $initialPriceUrl
                $date
            );
            if ($premium > 0) {
                $damagePrice = new PhonePrice();
                $damagePrice->setMonthlyPremiumPrice($premium * 0.8, $date);
                $damagePrice->setValidFrom($date);
                $damagePrice->setExcess($latestTerms->getDefaultExcess());
                $damagePrice->setPicSureExcess($latestTerms->getDefaultPicSureExcess());
                $damagePrice->setStream(PhonePrice::STREAM_ALL);
                $damagePrice->setSubvariant('damage');
                $essentialsPrice = new PhonePrice();
                $essentialsPrice->setMonthlyPremiumPrice($premium * 0.9, $date);
                $essentialsPrice->setValidFrom($date);
                $essentialsPrice->setExcess($latestTerms->getDefaultExcess());
                $essentialsPrice->setPicSureExcess($latestTerms->getDefaultPicSureExcess());
                $essentialsPrice->setStream(PhonePrice::STREAM_ALL);
                $essentialsPrice->setSubvariant('essentials');
                $phone->addPhonePrice($damagePrice);
                $phone->addPhonePrice($essentialsPrice);
            }

            if ($phone->getMake() == 'LG' && in_array($phone->getModel(), ['Nexus 5', 'Nexus 5X'])) {
                $phone->setAlternativeMake('Google');
            }
            if ($phone->getMake() == 'Motorola' && in_array($phone->getModel(), ['Nexus 6'])) {
                $phone->setAlternativeMake('Google');
            }
            if ($phone->getMake() == 'Huawei' && in_array($phone->getModel(), ['Nexus 6P'])) {
                $phone->setAlternativeMake('Google');
            }
            if ($phone->getMake() == 'Nokia' && in_array($phone->getModel(), ['Lumia 930'])) {
                $phone->setAlternativeMake('Microsoft');
            }

            $resolution = explode('x', str_replace(' ', '', $data[17]));
            $releaseDate = null;
            $releaseDateText = str_replace(' ', '', $data[21]);
            if (mb_strlen($releaseDateText) > 0) {
                $releaseDate = \DateTime::createFromFormat('m/y', $releaseDateText);
                if (!$releaseDate) {
                    throw new \Exception(sprintf('Unknown date format %s', $releaseDateText));
                }
                $releaseDate->setTime(0, 0);
                // otherwise is current day
                $releaseDate->modify('first day of this month');
            }
            $phone->setDetails(
                $data[9], // $os,
                $data[10], // $initialOsVersion,
                $data[11], // $upgradeOsVersion,
                $data[12], // $processorSpeed,
                $data[13], // $processorCores,
                $data[14], // $ram,
                $data[15] == 'Y' ? true : false, // $ssd,
                round($data[16]), // $screenPhysical,
                $resolution[0], // $screenResolutionWidth,
                $resolution[1], // $screenResolutionHeight,
                round($data[18]), // $camera
                $data[19] == 'Y' ? true : false, // $lte
                $releaseDate // $releaseDate
            );

            if ($phone->shouldBeRetired() || $premium == 0) {
                $isTestPhone = in_array('A0001', $phone->getDevices()) ||
                    in_array('iPhone 6', $phone->getDevices());
                $env = $this->container->getParameter('kernel.environment');
                if ($env == 'test' && $isTestPhone) {
                    $phone->setActive(true);
                } else {
                    $phone->setActive(false);
                }
            }

            $manager->persist($phone);
            $manager->flush();
            if (!$phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY) && $premium > 0) {
                throw new \Exception('Failed to init phone');
            }

            return $phone;
        } catch (\Exception $e) {
            if (!$this->expectedImportException) {
                print sprintf('Ex: %s. Failed to import %s', $e->getMessage(), json_encode($data));
            }
            throw $e;
        }

        return null;
    }

    protected function newPhone($manager, $make, $model, $policyPrice, $latestTerms, $memory = null, $devices = null)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var RouterInterface $router */
        $router = $this->container->get('router');
        // Validate that the regex for quote make model is working for all the data
        if ($make != "ALL") {
            if ($memory) {
                $router->generate('phone_insurance_make_model_memory', [
                    'make' => mb_strtolower($make),
                    'model' => mb_strtolower($model),
                    'memory' => $memory,
                ]);
            } else {
                $router->generate('phone_insurance_make_model', [
                    'make' => mb_strtolower($make),
                    'model' => mb_strtolower($model),
                ]);
            }
        }

        $phone = new Phone();
        $phone->init($make, $model, $policyPrice + 1.5, $latestTerms, $memory, $devices);
        $manager->persist($phone);

        if (!$phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)) {
            throw new \Exception('Failed to init phone');
        }
        /*
        \Doctrine\Common\Util\Debug::dump($phone->getCurrentPhonePrice());

        $repo = $manager->getRepository(Phone::class);
        $compare = $repo->find($phone->getId());
        \Doctrine\Common\Util\Debug::dump($compare->getCurrentPhonePrice());
        */
    }
}
