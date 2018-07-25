<?php

namespace CensusBundle\Tests\Service;

use AppBundle\Document\PostcodeTrait;
use CensusBundle\Document\Coordinates;
use CensusBundle\Document\Postcode;
use CensusBundle\Repository\PostCodeRepository;
use CensusBundle\Service\SearchService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\StatsService;

/**
 * @group functional-nonet
 */
class SearchServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use PostcodeTrait;

    protected static $container;
    /** @var SearchService */
    protected static $searchService;

    protected static $dm;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();
        /** @var SearchService $searchService */
        $searchService = self::$container->get('census.search');
        self::$searchService = $searchService;

        self::$dm = self::$container->get('doctrine_mongodb.odm.census_document_manager');
    }

    public function tearDown()
    {
    }

    public function testValidatePostcode()
    {
        /** @var PostCodeRepository $postcodeRepo */
        $postcodeRepo = self::$dm->getRepository(PostCode::class);
        $postcode = $postcodeRepo->findOneBy(['Postcode' => 'SE15 2SZ']);
        if ($postcode) {
            self::$dm->remove($postcode);
            self::$dm->flush();
        }

        $this->assertTrue(self::$searchService->validatePostcode('BX11LT'));
        $this->assertFalse(self::$searchService->validatePostcode('ZZ993CZ'));
        $this->assertFalse(self::$searchService->validatePostcode('B'));
        $this->assertFalse(self::$searchService->validatePostcode('SE15 2sz'));

        $postcode = new Postcode();
        // hardcode format for Postcode (do not use normalizePostcodeForDb)
        $postcode->setPostcode('SE15 2SZ');
        self::$dm->persist($postcode);
        self::$dm->flush();

        $this->assertTrue(self::$searchService->validatePostcode('SE15 2sz'));
    }

    public function testGetPostcode()
    {
        /** @var PostCodeRepository $postcodeRepo */
        $postcodeRepo = self::$dm->getRepository(PostCode::class);
        $postcode = $postcodeRepo->findOneBy(['Postcode' => 'SE15 2SY']);
        if ($postcode) {
            self::$dm->remove($postcode);
            self::$dm->flush();
        }

        $postcode = new Postcode();
        // hardcode format for Postcode (do not use normalizePostcodeForDb)
        $postcode->setPostcode('SE15 2SY');
        self::$dm->persist($postcode);
        self::$dm->flush();

        $this->assertNull(self::$searchService->getPostcode('SE15 2SY'));

        $coordinates = new Coordinates();
        $coordinates->setCoordinates(-1, 2);
        $postcode->setLocation($coordinates);
        self::$dm->flush();
        $this->assertNotNull(self::$searchService->getPostcode('SE15 2SY'));
    }
}
