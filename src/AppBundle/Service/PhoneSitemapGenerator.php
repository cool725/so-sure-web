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
        $phones = $repo->findBy(
            ['active' => true, 'highlight' => true]
        );
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $phoneMake = $phone->getMake();
            $makes[$phoneMake] = $phoneMake;
        }
        
        $phones = $repo->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            if ($phone->getCanonicalPath() && mb_strlen($phone->getCanonicalPath()) > 0) {
                $url = $this->router->generateUrlFromPath($phone->getCanonicalPath());
            } else {
                $url = $this->router->generateUrl('quote_make_model', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical()
                ]);
            }

            $item = new Entry($url, null, 'weekly', 0.7);
            $item->setDescription($phone->getMakeWithAlternative() . ' ' . $phone->getModel() . ' insurance');
            $entries[$item->getDescription()] = $item;
        }

        return $entries;
    }
}
