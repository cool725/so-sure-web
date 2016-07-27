<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
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
        $connection = new Connection();
        $this->assertEquals(0, $connection->getValue());
        $this->assertEquals(0, $connection->getTotalValue());

        $connection->setValue(10);
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(10, $connection->getTotalValue());

        $connection->setPromoValue(5);
        $this->assertEquals(5, $connection->getPromoValue());
        $this->assertEquals(15, $connection->getTotalValue());
    }

    public function testConnectionApi()
    {
        $connection = new Connection();
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
        $connection = new Connection();
        $connection->setLinkedUser($user);
        $api = $connection->toApiArray([]);
        $this->assertEquals('Foo Bar', $api['name']);
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=100',
            $api['image_url']
        );
    }
}
