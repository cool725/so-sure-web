<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;

/**
 * @group unit
 */
class ConnectionTest extends \PHPUnit\Framework\TestCase
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

    /**
     * @expectedException \Exception
     */
    public function testConnectionSelf()
    {
        $userA = new User();
        $policyA = new HelvetiaPhonePolicy();
        $policyA->setId(1);
        $policyA->setUser($userA);

        $connectionAA = new StandardConnection();
        $connectionAA->setId(12);
        $connectionAA->setSourcePolicy($policyA);
        $connectionAA->setLinkedPolicy($policyA);
    }

    /**
     * @expectedException \Exception
     */
    public function testConnectionPrevious()
    {
        $userA = new User();
        $policyA = new HelvetiaPhonePolicy();
        $policyA->setId(1);
        $policyA->setUser($userA);

        $policyB = new HelvetiaPhonePolicy();
        $policyB->setId(2);
        $policyB->setUser($userA);
        $policyB->link($policyA);

        $connectionAB = new StandardConnection();
        $connectionAB->setId(12);
        $connectionAB->setSourcePolicy($policyA);
        $connectionAB->setLinkedPolicy($policyB);
    }

    /**
     * @expectedException \Exception
     */
    public function testConnectionNext()
    {
        $userA = new User();
        $policyA = new HelvetiaPhonePolicy();
        $policyA->setId(1);
        $policyA->setUser($userA);

        $policyB = new HelvetiaPhonePolicy();
        $policyB->setId(2);
        $policyB->setUser($userA);

        $policyA->link($policyB);

        $connectionAB = new StandardConnection();
        $connectionAB->setId(12);
        $connectionAB->setSourcePolicy($policyA);
        $connectionAB->setLinkedPolicy($policyB);
    }

    public function testInversedConnection()
    {
        $userA = new User();
        $policyA = new HelvetiaPhonePolicy();
        $policyA->setId(1);
        $policyA->setUser($userA);

        $userB = new User();
        $policyB = new HelvetiaPhonePolicy();
        $policyB->setId(2);
        $policyB->setUser($userB);

        $userC = new User();
        $policyC = new HelvetiaPhonePolicy();
        $policyC->setId(3);
        $policyC->setUser($userC);

        $connectionAB = new StandardConnection();
        $connectionAB->setId(12);
        $connectionAB->setLinkedPolicy($policyB);
        $this->assertNull($connectionAB->findInversedConnection());

        $policyA->addConnection($connectionAB);
        $this->assertNull($connectionAB->findInversedConnection());

        $connectionAC = new StandardConnection();
        $connectionAC->setId(13);
        $connectionAC->setLinkedPolicy($policyC);
        $this->assertNull($connectionAC->findInversedConnection());

        $policyA->addConnection($connectionAC);
        $this->assertNull($connectionAB->findInversedConnection());

        $connectionBA = new StandardConnection();
        $connectionBA->setId(21);
        $connectionBA->setLinkedPolicy($policyA);
        $policyB->addConnection($connectionBA);

        $connectionBC = new StandardConnection();
        $connectionBC->setId(23);
        $connectionBC->setLinkedPolicy($policyC);
        $policyB->addConnection($connectionBC);

        $connectionCA = new StandardConnection();
        $connectionCA->setId(31);
        $connectionCA->setLinkedPolicy($policyA);
        $policyC->addConnection($connectionCA);

        $connectionCB = new StandardConnection();
        $connectionCB->setId(32);
        $connectionCB->setLinkedPolicy($policyB);
        $policyC->addConnection($connectionCB);

        $this->assertEquals(21, $connectionAB->findInversedConnection()->getId());
        $this->assertEquals(12, $connectionBA->findInversedConnection()->getId());

        $this->assertEquals(31, $connectionAC->findInversedConnection()->getId());
        $this->assertEquals(13, $connectionCA->findInversedConnection()->getId());

        $this->assertEquals(32, $connectionBC->findInversedConnection()->getId());
        $this->assertEquals(23, $connectionCB->findInversedConnection()->getId());
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

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 11 months + 16 days (>15 days to expirey)
        $connection->prorateValue(new \DateTime('2016-12-15 23:59:00'));
        $this->assertEquals(9.17, $connection->getValue());
        $this->assertEquals(13.75, $connection->getTotalValue());

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        // 11 months + 17 days (15 days to expirey)
        $connection->prorateValue(new \DateTime('2016-12-16 01:00:01'));
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(15, $connection->getTotalValue());
    }

    public function testConnectionProrateSourcePolicy()
    {
        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $connection = new StandardConnection();
        $connection->setSourcePolicy($policy);
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

        $policy->setStatus(Policy::STATUS_UNPAID);

        // 5 months
        $connection->prorateValue(new \DateTime('2016-06-01 00:00:01'));
        $this->assertEquals(0, $connection->getValue());
        $this->assertEquals(0, $connection->getTotalValue());

        // reset
        $connection->setValue(10);
        $connection->setPromoValue(5);

        $policy->setStatus(Policy::STATUS_CANCELLED);

        // 5 months
        $connection->prorateValue(new \DateTime('2016-06-01 00:00:01'));
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(15, $connection->getTotalValue());

        $policy->setStatus(Policy::STATUS_EXPIRED);

        // 5 months
        $connection->prorateValue(new \DateTime('2016-06-01 00:00:01'));
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(15, $connection->getTotalValue());
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
