<?php
namespace AppBundle\Tests\Repository;

use AppBundle\Document\Policy;
use AppBundle\Document\Note\Note;
use AppBundle\Document\Note\CallNote;
use AppBundle\Document\Note\StandardNote;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests that the policy repository methods work as they are intended.
 * @group functional-nonet
 */
class PolicyRepositoryTest extends KernelTestCase
{
    use UserClassTrait;
    use DateTrait;

    /** @var PolicyRepository */
    private $policyRepo;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $kernel = self::bootKernel();
        /** @var DocumentManager */
        $dm = $kernel->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var PolicyRepository */
        $policyRepo = self::$dm->getRepository(Policy::class);
        $this->policyRepo = $policyRepo;
    }

    /**
     * Tests to make sure that unpaid calls are found including when there are dates and things.
     */
    public function testFindUnpaidCalls()
    {
        $now = new \DateTime();
        $originals = [];
        // set up some datas.
        for ($i = 0; $i < 50; $i++) {
            $callDate = $this->addDays($now, $i - 25);
            $policy = self::createUserPolicy(
                true,
                $this->subDays($callDate, 60),
                false,
                uniqid()."@gmail.com",
                $this->generateRandomImei()
            );
            $policy->setStatus(Policy::STATUS_UNPAID);
            for ($u = 0; $u < rand(0, 3); $u++) {
                $note = new StandardNote();
                $note->setDate($this->addDays($callDate, rand(-50, 50)));
                $note->setNotes(uniqid());
                $policy->addNotesList($note);
            }
            $note = new CallNote();
            $note->setResult(rand() % 2 ? CallNote::RESULT_NO_ANSWER : CallNote::RESULT_WILL_PAY);
            $note->setDate($callDate);
            $policy->addNotesList($note);
            self::$dm->persist($policy);
            self::$dm->persist($policy->getUser());
            $originals[] = $policy->getId();
        }
        self::$dm->flush();
        // check to find the numbers we would expect.
        $callList = $this->policyRepo->findUnpaidCalls();
        $this->assertEquals(50, count($callList));
        foreach ($callList as $called) {
            $this->assertContains($called->getId(), $originals);
        }
        $this->assertEquals(25, count($this->policyRepo->findUnpaidCalls($now)));
        $this->assertEquals(25, count($this->policyRepo->findUnpaidCalls(null, $now)));
        $this->assertEquals(
            1,
            count($this->policyRepo->findUnpaidCalls($now, $this->addDays($now, 1)))
        );
    }
}
