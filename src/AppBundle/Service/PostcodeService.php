<?php


namespace AppBundle\Service;

use AppBundle\Document\Postcode;
use AppBundle\Repository\PostcodeRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;

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

    public function isValidPostcode($postcode)
    {
        // Validate postcode with postcodes.io
        $url = 'https://api.postcodes.io/postcodes/' . $postcode. '/validate';
        $client = new Client();
        $res = $client->request('GET', $url);
        if ($res->getStatusCode()== '200') {
            return boolval(json_decode($res->getBody())->result);
        } else {
            return false;
        }
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
