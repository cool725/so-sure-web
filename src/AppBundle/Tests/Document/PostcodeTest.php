<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Postcode;
use AppBundle\Repository\PostcodeRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PostcodeTest extends WebTestCase
{
    /** @var Postcode */
    protected static $postcode;

    protected static $validPostcode;

    protected static $invalidPostcode;

    protected static $lowerCaseValidPostcode;

    protected static $validOutCode;

    public static function setUpBeforeClass()
    {
        self::$postcode = new Postcode();
        self::$validPostcode = "HP2 6NE";
        self::$invalidPostcode = "QQ9 9VV";
        self::$lowerCaseValidPostcode = "hp26ne";
        self::$validOutCode = "hp1";
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The postcode is not valid
     */
    public function testSetPostcodeInvalid()
    {
        self::$postcode->setPostcode(self::$invalidPostcode);

    }

    public function testSetPostcodeValid()
    {
        self::$postcode->setPostcode(self::$validPostcode);
        self::assertEquals(self::$validPostcode, self::$postcode->getPostcode());
    }

    public function testGetPostcodeCanonical()
    {
        $set = self::$postcode->setPostcode(self::$lowerCaseValidPostcode);
        self::assertEquals(self::$validPostcode, self::$postcode->getPostcodeCanonical());
    }

    public function testSetAdded()
    {
        $now =  new \DateTime();
        self::$postcode->setAdded($now);
        self::assertEquals($now, self::$postcode->getAdded());
    }

    public function testGetTypePostCode()
    {
        $actual = self::$postcode->getType();
        $expected = self::$postcode::PostCode;
        self::assertEquals($actual, $expected);
    }

    public function testSetPostCodeOutCodeValid()
    {
        self::$postcode->setPostcode(self::$validOutCode);
        self::assertEquals(self::$validOutCode, self::$postcode->getPostcode());
    }

    public function testGetTypeOutCode()
    {
        $actual = self::$postcode->getType();
        $expected = self::$postcode::OutCode;
        self::assertEquals($actual, $expected);
    }

    public function testCanonicalisePostCode()
    {
        $actual = self::$postcode->canonicalisePostCode(self::$lowerCaseValidPostcode);
        self::assertEquals($actual, self::$validPostcode);
    }

    public function testAdd()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        $container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');
        /** @var PostcodeRepository $postcodeRepo */
        $postcodeRepo = $dm->getRepository(Postcode::class);
        $postcode = new Postcode();
        $postcode->setPostcode(self::$validPostcode);
        $postcode->setAdded(new \DateTime());
        $postcode->setNotes("This is a test.");
        $dm->persist($postcode);
        $dm->flush();
    }
}
