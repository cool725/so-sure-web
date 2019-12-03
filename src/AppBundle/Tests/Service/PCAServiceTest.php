<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Service\PCAService;

/**
 * @group functional-nonet
 * @group fixed
 * AppBundle\\Tests\\Service\\PCAServiceTest
 */
class PCAServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    /** @var PCAService */
    protected static $pca;
    protected static $redis;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var PCAService $pca */
        $pca = self::$container->get('app.address');
        self::$pca = $pca;
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testGetAddressCaching()
    {
        self::$redis->flushdb();
        $this->assertFalse(self::$redis->hexists(PCAService::REDIS_POSTCODE_KEY, 'BX11LT') == 1);
        $address = self::$pca->getAddress('BX11LT', '1');
        $this->assertNotNull($address);
        $this->assertEquals('BX1 1LT', $address ? $address->getPostcode() : null);
        $this->assertTrue(self::$redis->hexists(PCAService::REDIS_POSTCODE_KEY, 'BX11LT') == 1);
    }

    public function testGetBankAccountCaching()
    {
        self::$redis->flushdb();
        $this->assertFalse(self::$redis->exists(sprintf(
            PCAService::REDIS_BANK_KEY_FORMAT,
            '000099',
            '87654321'
        )) == 1);
        $address = self::$pca->getBankAccount('000099', '87654321');
        $this->assertTrue(self::$redis->exists(sprintf(
            PCAService::REDIS_BANK_KEY_FORMAT,
            '000099',
            '87654321'
        )) == 1);
    }

    public function testNormalize()
    {
        $this->assertEquals('SE152SZ', self::$pca->normalizePostcodeForDb('se15 2sz '));
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
        $testAddress->PostalCode = 'se152sz';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3', $address->getLine3());
        $this->assertEquals('SE15 2SZ', $address->getPostcode());
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
        $testAddress->PostalCode = 'se152sz';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1', $address->getLine1());
        $this->assertEquals('2', $address->getLine2());
        $this->assertEquals('3, 4', $address->getLine3());
        $this->assertEquals('SE15 2SZ', $address->getPostcode());
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
        $testAddress->PostalCode = 'se152sz';

        $address = self::$pca->transformAddress($this->getTransformMock($testAddress));
        $this->assertEquals('1, 2', $address->getLine1());
        $this->assertEquals('3', $address->getLine2());
        $this->assertEquals('4, 5', $address->getLine3());
        $this->assertEquals('SE15 2SZ', $address->getPostcode());
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
