<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Feature;

class FeatureService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function isEnabled($featureName)
    {
        try {
            $repo = $this->dm->getRepository(Feature::class);
            $feature = $repo->findOneBy(['name' => $featureName]);
            if (!$feature) {
                return false;
            }

            return $feature->isEnabled();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error w/feature %s', $featureName), ['exception' => $e]);
        }
    }
}
