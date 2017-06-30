<?php
namespace AppBundle\Service;

use Dpn\XmlSitemapBundle\Sitemap\Entry;
use Dpn\XmlSitemapBundle\Sitemap\GeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Phone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PhoneSitemapGenerator implements GeneratorInterface
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $router;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $router
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

        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            $url = $this->router->generate('quote_make_model', [
                'make' => $phone->getMake(),
                'model' => $phone->getEncodedModel(),
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
