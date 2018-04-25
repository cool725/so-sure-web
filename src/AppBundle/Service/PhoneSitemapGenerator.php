<?php
namespace AppBundle\Service;

use AppBundle\Repository\PhoneRepository;
use Dpn\XmlSitemapBundle\Sitemap\Entry;
use Dpn\XmlSitemapBundle\Sitemap\GeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Phone;
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

    public function generate()
    {
        $entries = array();

        /** @var PhoneRepository $repo */
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        $makeModelUrls = [];
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $url = $this->router->generate('quote_make_model', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical()
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            if (!in_array($url, $makeModelUrls)) {
                $makeModelUrls[] = $url;
                $entries[] = new Entry($url, null, 'weekly', '0.7');
            }

            $url = $this->router->generate('quote_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory()
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $entries[] = new Entry($url, null, 'weekly', '0.7');
        }

        $makes = [];
        $phones = $repo->findBy(
            ['active' => true, 'highlight' => true]
        );
        foreach ($phones as $phone) {
            if (!in_array($phone->getMake(), $makes)) {
                $makes[] = $phone->getMake();
            }
        }

        foreach ($makes as $make) {
            $url = $this->router->generate('quote_make', [
                'make' => $make,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $entries[] = new Entry($url, null, 'weekly', '0.7');
        }

        return $entries;
    }
}
