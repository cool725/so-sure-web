<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-nonet
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
        $testAddress = new \stdClass();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->Line4 = null;
        $testAddress->Line5 = null;
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }

    public function testTransform4()
    {
        $testAddress = new \stdClass();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->Line4 = '4';
        $testAddress->Line5 = null;
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3, 4', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }

    public function testTransform5()
    {
        $testAddress = new \stdClass();
        $testAddress->Line1 = '1';
        $testAddress->Line2 = '2';
        $testAddress->Line3 = '3';
        $testAddress->Line4 = '4';
        $testAddress->Line5 = '5';
        $testAddress->City = 'city';
        $testAddress->PostalCode = 'se15';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1, 2', $address->getLine1());
        $this->assertEquals('3', $address->getLine2());
        $this->assertEquals('4, 5', $address->getLine3());
        $this->assertEquals('se15', $address->getPostcode());
        $this->assertEquals('city', $address->getCity());
    }

    protected function getTransformMock($address)
    {
        $stub = $this->getMockBuilder('nonexistant')
            ->setMockClassName('foo')
            ->setMethods(array('attributes'))
            ->getMock();
        $stub->method('attributes')->willReturn($address);

        return $stub;
    }
}
