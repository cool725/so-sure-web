<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

class AddressTest
{
    public $Line1;
    public $Line2;
    public $Line3;
    public $Line4;
    public $Line5;
    public $City;
    public $PostalCode;
}

class MockAddress
{
    private $address;

    public function __construct($address)
    {
        $this->address = $address;
    }
    
    public function attributes()
    {
        return $this->address;
    }
}

/**
 * @group functional
 */
class PCAServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $pca;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$pca = self::$container->get('app.address');
    }

    public function tearDown()
    {
    }

    public function testTransform3()
    {
        $testAddress = new AddressTest();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress(new MockAddress($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }

    public function testTransform4()
    {
        $testAddress = new AddressTest();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->Line4 = '4';
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress(new MockAddress($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3, 4', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }

    public function testTransform5()
    {
        $testAddress = new AddressTest();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->Line4 = '4';
        $testAddress->Line5 = '5';
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress(new MockAddress($testAddress));
        $this->assertEquals('1, 2', $address->getLine1());
        $this->assertEquals('3', $address->getLine2());
        $this->assertEquals('4, 5', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }
}
