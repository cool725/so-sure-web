<?php
namespace AppBundle\Service;

use Dpn\XmlSitemapBundle\Sitemap\Entry;
use Dpn\XmlSitemapBundle\Sitemap\GeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Phone;

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
            if ($phone->getMemory()) {
                $url = $this->router->generate('quote_make_model_memory', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getModel(),
                    'memory' => $phone->getMemory(),
                ], true);
            } else {
                $url = $this->router->generate('quote_make_model', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getModel(),
                ], true);
            }
            $entries[] = new Entry($url, null, 'weekly', '0.7');
        }

        return $entries;
    }
}