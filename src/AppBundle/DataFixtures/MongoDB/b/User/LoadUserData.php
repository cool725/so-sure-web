<?php

namespace AppBundle\DataFixtures\MongoDB\b\User;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\User;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadUserData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->newUser('patrick@so-sure.com', 'w3ares0sure!', 'Patrick', 'McAndrew', ['ROLE_ADMIN']);
        $this->newUser('dylan@so-sure.com', 'w3ares0sure!', 'Dylan', 'Bourguignon', ['ROLE_ADMIN']);
        $this->newUser('jamie@so-sure.com', 'w3ares0sure!', 'Jamie', 'Gunson', ['ROLE_ADMIN']);
        $this->newUser('ted@so-sure.com', 'w3ares0sure!', 'Ted', 'Eriksson', ['ROLE_ADMIN']);
        $this->newUser('julien@so-sure.com', 'w3ares0sure!', 'Julien', 'Champagne', ['ROLE_ADMIN']);
        $this->newUser('nick@so-sure.com', 'w3ares0sure!', 'Nick', 'Waller', ['ROLE_ADMIN']);
        $this->newUser('claims@so-sure.com', 'w3ares0sure!', 'so-sure', 'Claims', ['ROLE_CLAIMS']);
        $this->newUser('employee@so-sure.com', 'w3ares0sure!', 'so-sure', 'Employee', ['ROLE_EMPLOYEE']);
        $manager->flush();
        // $this->valdiateGedmoLogging();
    }

    private function valdiateGedmoLogging()
    {
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['email' => 'patrick@so-sure.com']);
        $user->setFirstName('patrick');
        $manager->flush();
    }

    private function newUser($email, $password, $firstName, $lastName, $roles)
    {
        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPlainPassword($password);
        $user->setEnabled(true);
        $user->setRoles($roles);
        $userManager->updateUser($user, true);
    }
}
