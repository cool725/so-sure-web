<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Phone;
use Symfony\Component\Finder\Finder;

class PhoneService
{

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getAdditionalPhones($rootdir)
    {
        $finder = new Finder();
        $finder->directories()->in($rootdir.'/../src/AppBundle/DataFixtures/MongoDB/b/Phone');
        $finder->name('Additions*');
        $finder->notName('Additions20160101');
        $finder->sort(function ($a, $b) {
            return strcmp($b->getRealpath(), $a->getRealpath());
        });

        $additionalPhones = array();
        foreach ($finder as $directory) {
            $additionalPhones[$directory->getRelativePathname()] = $directory->getRelativePathname();
        }

        return $additionalPhones;
    }

    public function getAdditionalPhonesInstance($filename)
    {
        $classname = sprintf("\\AppBundle\\DataFixtures\\MongoDB\\b\\Phone\\%s\\%s", $filename, $filename);
        return new $classname();
    }
}
