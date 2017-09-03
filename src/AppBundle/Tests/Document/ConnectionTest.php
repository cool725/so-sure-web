<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;

/**
 * @group unit
 */
class ConnectionTest extends \PHPUnit_Framework_TestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testConnection()
    {
        $connection = new StandardConnection();
        $this->assertEquals(0, $connection->getValue());
        $this->assertEquals(0, $connection->getTotalValue());

        $connection->setValue(10);
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(10, $connection->getTotalValue());

        $connection->setPromoValue(5);
        $this->assertEquals(5, $connection->getPromoValue());
        $this->assertEquals(15, $connection->getTotalValue());
    }

    public function testConnectionProrate()
    {
        $connection = new StandardConnection();
        $connection->setDate(new \DateTime('2016-01-01'));

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 5 months
        $connection->prorateValue(new \DateTime('2016-06-01 00:00:01'));
        $this->assertEquals(0, $connection->getValue());
        $this->assertEquals(0, $connection->getTotalValue());

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 6 months
        $connection->prorateValue(new \DateTime('2016-07-01 00:00:01'));
        $this->assertEquals(5, $connection->getValue());
        $this->assertEquals(7.5, $connection->getTotalValue());

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 7 months
        $connection->prorateValue(new \DateTime('2016-08-01 00:00:01'));
        $this->assertEquals(5.83, $connection->getValue());
        $this->assertEquals(8.75, $connection->getTotalValue());

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 11 months
        $connection->prorateValue(new \DateTime('2016-12-01 00:00:01'));
        $this->assertEquals(9.17, $connection->getValue());
        $this->assertEquals(13.75, $connection->getTotalValue());
    }

    public function testConnectionApi()
    {
        $connection = new StandardConnection();
        $api = $connection->toApiArray([]);
        $this->assertNull($api['name']);
        $this->assertNull($api['image_url']);
        $this->assertEquals($connection->getDate()->format(\DateTime::ATOM), $api['date']);
        $this->assertNull($api['id']);
        $this->assertEquals(0, count($api['claim_dates']));

        $connection->setId('34243242');
        $api = $connection->toApiArray([]);
        $this->assertEquals('34243242', $api['id']);
    }

    public function testConnectionApiImageUrl()
    {
        $user = new User();
        $user->setEmail('foo@bar.com');
        $user->setFirstName('Foo');
        $user->setLastName('Bar');
        $connection = new StandardConnection();
        $connection->setLinkedUser($user);
        $api = $connection->toApiArray([]);
        $this->assertEquals('Foo Bar', $api['name']);
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=100',
            $api['image_url']
        );
    }
}
