<?php

namespace AppBundle\DataFixtures\MongoDB\b\User;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\User;
use FOS\UserBundle\Model\UserManagerInterface;
use Stubs\DocumentManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadUserData implements FixtureInterface, ContainerAwareInterface
{
    const DEFAULT_PASSWORD = 'w3ares0sure!';

    /**
     * @var ContainerInterface|null
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->newUser('patrick@so-sure.com', self::DEFAULT_PASSWORD, 'Patrick', 'McAndrew', [User::ROLE_ADMIN]);
        $this->newUser('dylan@so-sure.com', self::DEFAULT_PASSWORD, 'Dylan', 'Bourguignon', [User::ROLE_ADMIN]);
        $this->newUser('julien@so-sure.com', self::DEFAULT_PASSWORD, 'Julien', 'Champagne', [User::ROLE_ADMIN]);
        $this->newUser('nick@so-sure.com', self::DEFAULT_PASSWORD, 'Nick', 'Waller', [User::ROLE_ADMIN]);
        $this->newUser('marta@so-sure.com', self::DEFAULT_PASSWORD, 'Marta', 'Datkiewicz', [User::ROLE_ADMIN]);
        $this->newUser('kitti@so-sure.com', self::DEFAULT_PASSWORD, 'Kitti', 'Varga', [User::ROLE_CUSTOMER_SERVICES]);
        $this->newUser('claims@so-sure.com', self::DEFAULT_PASSWORD, 'so-sure', 'Claims', [User::ROLE_CLAIMS]);
        $this->newUser('employee@so-sure.com', self::DEFAULT_PASSWORD, 'so-sure', 'Employee', [User::ROLE_EMPLOYEE]);
        $this->newUser('customer-services@so-sure.com', self::DEFAULT_PASSWORD, 'so-sure', 'CustomerServices', [User::ROLE_CUSTOMER_SERVICES]);
        $this->newUser('alister@so-sure.com', self::DEFAULT_PASSWORD, 'so-sure', 'CustomerServices', [User::ROLE_ADMIN]);
        $manager->flush();
        // $this->valdiateGedmoLogging($manager);
    }

    private function valdiateGedmoLogging($manager)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var \Doctrine\ODM\MongoDB\DocumentManager $dm */
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['email' => 'patrick@so-sure.com']);
        $user->setFirstName('patrick');
        $manager->flush();
    }

    private function newUser($email, $password, $firstName, $lastName, $roles)
    {
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var UserManagerInterface $userManager */
        $userManager = $this->container->get('fos_user.user_manager');
        /** @var User $user */
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPlainPassword($password);
        $user->setEnabled(true);
        $user->setRoles($roles);
        $userManager->updateUser($user);
    }
}
