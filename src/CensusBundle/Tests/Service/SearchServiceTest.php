<?php

namespace CensusBundle\Tests\Service;

use AppBundle\Document\PostcodeTrait;
use CensusBundle\Document\Postcode;
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
        $this->assertTrue(self::$searchService->validatePostcode('BX11LT'));
        $this->assertFalse(self::$searchService->validatePostcode('ZZ993CZ'));
        $this->assertFalse(self::$searchService->validatePostcode('B'));
        $this->assertFalse(self::$searchService->validatePostcode('SE15 2sz'));

        $postcode = new Postcode();
        $postcode->setPostcode($this->normalizePostcode('SE15 2sz'));
        self::$dm->persist($postcode);
        self::$dm->flush();

        $this->assertTrue(self::$searchService->validatePostcode('SE15 2sz'));
    }
}
