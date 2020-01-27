<?php


namespace AppBundle\Service;

use AppBundle\Document\Postcode;
use AppBundle\Repository\PostcodeRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class PostcodeService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

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

    public function getOutCodes()
    {
        /** @var PostcodeRepository $postcodeRepository */
        $postcodeRepository = $this->dm->getRepository(Postcode::class);
        return $postcodeRepository->findBy(["type" => Postcode::OUTCODE]);
    }

    public function getPostcodes()
    {
        /** @var PostcodeRepository $postcodeRepository */
        $postcodeRepository = $this->dm->getRepository(Postcode::class);
        return $postcodeRepository->findBy(["type" => Postcode::POSTCODE]);
    }

    public function getIsAnnualOnlyPostCode($postcode)
    {
        /** @var PostcodeRepository $postcodeRepository */
        $postcodeRepository = $this->dm->getRepository(Postcode::class);
        return $postcodeRepository->getPostcodeIsAnnualOnly($postcode);
    }

    public function getIsBannedPostcode($postcode)
    {
        /** @var PostcodeRepository $postcodeRepository */
        $postcodeRepository = $this->dm->getRepository(Postcode::class);
        return $postcodeRepository->getPostcodeIsBanned($postcode);
    }
}
