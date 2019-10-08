<?php
namespace AppBundle\Service;

use App\Sitemap\DecoratedEntry as Entry;
use AppBundle\Document\Phone;
use AppBundle\Repository\PhoneRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Dpn\XmlSitemapBundle\Sitemap\GeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class PhoneSitemapGenerator implements GeneratorInterface
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RouterService  */
    protected $router;

    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        RouterService $router
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->router = $router;
    }

    /**
     * A tad slow, and memory hungry when looping the DB, but we could cache the final $entries if it gets too annoying
     */
    public function generate()
    {
        $entries = [];

        /** @var PhoneRepository $repo */
        $repo = $this->dm->getRepository(Phone::class);

        $makes = [];
        $phones = $repo->findBy(['active' => true]);
        foreach ($phones as $phone) {
            $phoneMake = $phone->getMake();
            if ($phoneMake == "ALL") {
                continue;
            }
            if (array_key_exists($phoneMake, $makes)) {
                $makes[$phoneMake][] = $phone;
            } else {
                $makes[$phoneMake] = [$phone];
            }
        }

        foreach ($makes as $make => $phones) {
            // We need to ignore
            // /phone-insurance/all
            // We also need Broken phone, cracked screen etc in this list under one heading
            $url = $this->router->generateUrl('phone_insurance_make', ['make' => $make]);
            $topItem = new Entry($url, null, 'weekly', 0.7);
            $topItem->setDescription($make);
            $entries[$topItem->getDescription()] = $topItem;
            foreach ($phones as $phone) {
                $itemUrl = $this->router->generateUrl('phone_insurance_make_model', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical()
                ]);
                $item = new Entry($itemUrl, null, 'weekly', 0.7);
                $item->setDescription($phone->getMakeWithAlternative() . ' ' . $phone->getModel() . ' Insurance');
                $entries[$item->getDescription()] = $item;
            }
        }

        return $entries;
    }
}
