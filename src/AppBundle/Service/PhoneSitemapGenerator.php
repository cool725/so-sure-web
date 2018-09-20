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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param RouterInterface $router
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $router
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
        $entries = array();

        /** @var PhoneRepository $repo */
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $url = $this->router->generate('quote_make_model', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical()
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $item = new Entry($url, null, 'weekly', 0.7);
            $item->setDescription($phone->getMakeWithAlternative() . ' ' . $phone->getModel());
            $entries[$item->getDescription()] = $item;

            $url = $this->router->generate('quote_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory()
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $item = new Entry($url, null, 'weekly', 0.7);
            $item->setDescription((string) $phone);
            $entries[$item->getDescription()] = $item;
        }

        $makes = [];
        $phones = $repo->findBy(
            ['active' => true, 'highlight' => true]
        );
        foreach ($phones as $phone) {
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

        return $entries;
    }
}
