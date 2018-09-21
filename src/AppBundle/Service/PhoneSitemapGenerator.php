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

    /** @var RouterInterface  */
    protected $router;

    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        RouterInterface $router
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
        $phones = $repo->findBy(
            ['active' => true, 'highlight' => true]
        );
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $phoneMake = $phone->getMake();
            $makes[$phoneMake] = $phoneMake;
        }

        foreach ($makes as $make) {
            $url = $this->router->generate('quote_make', [
                'make' => $make,
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $item = new Entry($url, null, 'weekly', 0.7);
            $item->setDescription($make);
            $entries[$make] = $item;
        }

        $phones = $repo->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $url = $this->router->generate('quote_make_model', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical()
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $item = new Entry($url, null, 'weekly', 0.7);
            $item->setDescription($phone->getMakeWithAlternative() . ' ' . $phone->getModel() . ' insurance');
            $entries[$item->getDescription()] = $item;
        }

        return $entries;
    }
}
