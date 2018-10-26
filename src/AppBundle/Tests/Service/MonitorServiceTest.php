<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\User;
use AppBundle\Exception\MonitorException;
use AppBundle\Form\Type\UserRoleType;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Service\MonitorService;
use Doctrine\Tests\Common\DataFixtures\TestDocument\Role;
use Exception;
use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Claim;

/**
 * @group functional-nonet
 */
class MonitorServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    protected static $monitor;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        /** @var MonitorService $monitor */
        self::$monitor = self::$container->get('app.monitor');
    }

    public function tearDown()
    {
        // block tearDown from running, because setUpBeforeClass
    }

    public function setUp()
    {
        parent::setUp();

        $qb = static::$dm->createQueryBuilder(Claim::class);
        $qb->remove()
            ->getQuery()
            ->execute();
    }

    public function testClaimsSettledUnprocessedOk()
    {
        // should not be throwing an exception
        self::$monitor->claimsSettledUnprocessed();

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testMissingName()
    {
        $this->expectException(Exception::class);
        self::$monitor->run('foo');
    }

    public function testMissingPartialName()
    {
        $this->expectException(Exception::class);
        self::$monitor->run('claimsSettledUnprocessedFoo');
    }

    public function testClaimsSettledUnprocessedFalse()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setProcessed(false);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->expectException(MonitorException::class);

        self::$monitor->claimsSettledUnprocessed();
    }

    public function testClaimsSettledUnprocessedNull()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->expectException(MonitorException::class);

        self::$monitor->claimsSettledUnprocessed();
    }

    public function testExpectedFailOldSubmittedClaimsUnit()
    {
        $daysAgo = $this->subBusinessDays(new \DateTime(), 3);

        // add a record that will make the monitor fail
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setStatusLastUpdated($daysAgo);
        $claim->setType(Claim::TYPE_LOSS);

        $this->expectException(MonitorException::class);

        self::$monitor->outstandingSubmittedClaims([$claim]);
    }

    public function testNoOldSubmittedClaimsSucceedsUnit()
    {
        self::$monitor->outstandingSubmittedClaims([]);
        $this->assertTrue(true, 'monitoring old submitted claims with no results succeeds');
    }

    /**
     * @group functional-nonet
     */
    public function testExpectedFailOldSubmittedClaimsFunctional()
    {
        $daysAgo = $this->subBusinessDays(new \DateTime(), 3);

        // add a record that will make the monitor fail
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setStatusLastUpdated($daysAgo);
        $claim->setType(Claim::TYPE_LOSS);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->assertSame($claim->getStatusLastUpdated(), $daysAgo);

        $this->expectException(MonitorException::class);
        $this->expectExceptionMessage('At least one Claim (eg: ');
        $this->expectExceptionMessage(") is still marked as 'Submitted' after 2 business days");

        self::$monitor->outstandingSubmittedClaims();

        // try to clean up, and remove the record
        self::$dm->remove($claim);
        self::$dm->flush();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testSalvaPolicy()
    {
        $policy = self::createUserPolicy();
        $policy->create(rand(1, 999999), 'Mob', null, rand(1, 999999));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->salvaPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testInvalidPolicy()
    {
        $policy = self::createUserPolicy();
        $policy->create(rand(1, 999999), 'INVALID', null, rand(1, 999999));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->invalidPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testSalvaStatus()
    {
        $policy = self::createUserPolicy();
        $policy->create(rand(1, 999999), 'Mob', null, rand(1, 999999));
        $policy->setSalvaStatus('pending');

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->salvaStatus();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testPolicyFiles()
    {
        $policy = self::createUserPolicy();
        $policy->create(rand(1, 999999), 'Mob', null, rand(1, 999999));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->policyFiles();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testPolicyPending()
    {
        $policy = self::createUserPolicy();
        $policy->create(rand(1, 999999), 'Mob', null, rand(1, 999999));
        $policy->setStatus('pending');

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->policyPending();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testDuplicateInvites()
    {
        $inviteOne = new EmailInvitation();
        $inviteTwo = new EmailInvitation();
        $policy = self::createUserPolicy();

        $inviteOne->setEmail(self::generateEmail('foobar', $this));
        $inviteOne->setPolicy($policy);

        $inviteTwo->setEmail(self::generateEmail('foobar', $this));
        $inviteTwo->setPolicy($policy);

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->persist($inviteOne);
        self::$dm->persist($inviteTwo);
        self::$dm->flush();

        self::$monitor->duplicateInvites();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testCheckAllUserRolePriv()
    {
        $database = self::$dm->getConnection()->selectDatabase('so-sure');

        $database->command([
            'createRole' => 'so-sure-user',
            'privileges' => [
                ['resource' => ['db' => 'so-sure', 'collection' => 'Phone'], 'actions' => ['find' , 'update', 'remove']]
            ],
            'roles' => []
        ]);

        $database->command([
            'createUser' => 'SOSUREUSER',
            'pwd' => 'sosure',
            'roles' => ['so-sure-user']
        ]);

        self::$monitor->checkAllUserRolePriv();

        $database->command([
            'dropUser' => 'SOSUREUSER'
        ]);

        $database->command([
            'dropRole' => 'so-sure-user'
        ]);
    }
}
