<?php

namespace AppBundle\Tests\Repository;

use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class UserRepositoryTest extends WebTestCase
{
    use UserClassTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }

    /**
     * Tests that the find all users grouped function works correctly even if the group is larger than the total number
     * of users.
     */
    public function testFindAllUsersGrouped()
    {
        /** @var UserRepository */
        $userRepo = self::$dm->getRepository(User::class);
        $count = $userRepo->countAll();
        $groups = $userRepo->findAllUsersGrouped(555);
        $i = 0;
        foreach ($groups as $group) {
            $i++;
        }
        $this->assertEquals(ceil($count / 555), $i);
    }

    /**
     * Tests that the find all users batched function works correctly.
     */
    public function testFindAllUsersBatched()
    {
        /** @var UserRepository */
        $userRepo = self::$dm->getRepository(User::class);
        $count = $userRepo->countAll();
        $users = $userRepo->findAllUsersBatched();
        $i = 0;
        foreach ($users as $user) {
            $i++;
        }
        $this->assertEquals($count, $i);

    }

    /**
     * Tests that the remove hubspot ids function does actually remove te hubspot ids from all users.
     */
    public function testRemoveHubspotIds()
    {
        self::$dm->createQueryBuilder()->updateMany()
            ->field("hubspotId")->set("bingBingWahoo")
            ->getQuery()->execute();
        $users = self::$dm->createQueryBuilder(User::class)->find()
            ->field("hubspotId")->exists(true)
            ->getQuery()->execute();
        $this->assertNotEmpty($users);
        /** @var UserRepository */
        $userRepo = self::$dm->getRepository(User::class);
        $userRepo->removeHubspotIds();
        $users = self::$dm->createQueryBuilder(User::class)->find()
            ->field("hubspotId")->exists(false)
            ->getQuery()->execute();
        $this->assertNotEmpty($users);
    }
}
