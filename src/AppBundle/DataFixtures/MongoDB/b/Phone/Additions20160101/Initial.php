<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20160101;

use AppBundle\Document\PolicyTerms;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

// @codingStandardsIgnoreFile
class Initial extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }

        /** @var DocumentManager $dm */
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $this->loadPreLaunchMSRP($manager, $latestTerms);
        $this->loadCsv($manager, 'devices.csv');

        // For non-prod, we want the sii available as its a test device
        $repo = $manager->getRepository(Phone::class);
        /** @var Phone $sii */
        $sii = $repo->findOneBy(['devices' => 'GT-I9100', 'memory' => 16]);
        $sii->setActive(true);
        $manager->flush();
    }

    protected function loadPreLaunchMSRP(ObjectManager $manager, $latestTerms)
    {
        $this->newPhone($manager, 'ALL', 'MSRP 150 or less', 4.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 151 to 250', 5.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 251 to 400', 5.79, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 401 to 500', 6.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 501 to 600', 7.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 601 to 750', 8.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 751 to 1000', 9.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 1001 to 1500', 10.29, $latestTerms);
        $this->newPhone($manager, 'ALL', 'MSRP 1501 to 2500', 15.29, $latestTerms);
    }
}
