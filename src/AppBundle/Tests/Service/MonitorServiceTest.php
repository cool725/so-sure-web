<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
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
use Symfony\Component\Validator\Constraints\Date;

/**
 * @group functional-nonet
 *
 * \\AppBundle\\Tests\\Service\\MonitorServiceTest
 */
class MonitorServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;

    /** @var MonitorService */
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
        $monitor = self::$container->get('app.monitor');
        self::$monitor = $monitor;
    }

    public function tearDown()
    {
        parent::tearDown();

        $qb = static::$dm->createQueryBuilder(Policy::class);

        $qb->remove()
            ->getQuery()
            ->execute();
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
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber($this->getRandomPolicyNumber('Mob'));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->salvaPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testInvalidPolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber($this->getRandomPolicyNumber('INVALID'));
        $policy->addSalvaPolicyResults('0', SalvaPhonePolicy::RESULT_TYPE_CREATE, []);

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->invalidPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testSalvaStatus()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber($this->getRandomPolicyNumber('Mob'));
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
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber($this->getRandomPolicyNumber('Mob'));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->policyFiles();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testPolicyPending()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber($this->getRandomPolicyNumber('Mob'));

        $user = new User();
        $user->setEmail(self::generateEmail('pending', $this));

        $policy->setUser($user);
        $policy->setStatus(Policy::STATUS_PENDING);

        self::$dm->persist($policy);
        self::$dm->persist($policy->getUser());

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

        $user = new User();
        $user->setEmail('foo@bar.com');

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        $inviteOne->setEmail($user->getEmail());
        $inviteOne->setPolicy($policy);

        $inviteTwo->setEmail($user->getEmail());
        $inviteTwo->setPolicy($policy);

        self::$dm->persist($policy);
        self::$dm->persist($user);
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

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testCheckExpiration()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setEnd((new \DateTime())->sub(new \DateInterval('P1D')));

        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->checkExpiration();
    }

    private function getRandomPolicyNumber($prefix)
    {
        return sprintf($prefix . '/2018/55' . str_pad(random_int(0, 99999), 5, '0'));
    }
}
