<?php
namespace AppBundle\DataFixtures\MongoDB\d\Oauth2;

use AppBundle\Document\Oauth\Client;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadOauth2Data implements FixtureInterface, ContainerAwareInterface
{
    // These can be used for testing
    const KNOWN_CLIENT_ID = '5b51ec6b636239778924b671_36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';
    const KNOWN_CLIENT_SECRET = '1c9u3i9i0nogkc40os8480g88kokc480kkw0coc0c0wggw8k80';

    // split the client_id for the fields (must match KNOWN_CLIENT_ID!!)
    const KNOWN_CLIENT_ID_KEY = '5b51ec6b636239778924b671';
    const KNOWN_CLIENT_ID_RANDOM = '36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';
    const KNOWN_CLIENT_CALLBACK_URL = '/';
    #const KNOWN_CLIENT_CALLBACK_URL = 'http://dev.so-sure.net:40080/';

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
        if (!$this->container) {
            throw new \Exception('missing container');
        }

        $this->newOauth2Client($manager, ['read', 'summary'], []);

        $manager->flush();
        // $this->valdiateGedmoLogging($manager);
    }

    private function newOauth2Client(
        ObjectManager $manager,
        array $grantTypes = [],
        array $redirectUrls = []
    ) {
        $grantTypes = array_merge($grantTypes, []);
        $redirectUrls = array_merge($redirectUrls, [self::KNOWN_CLIENT_CALLBACK_URL]);

        /** @var DocumentManager $dm */
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        //$clientRepo = $dm->getRepository(Client::class);

        $client = new Client();

        // The 'CLIENT_ID' is (*_KEY . *_RANDOM)
        $this->setClientId($client, self::KNOWN_CLIENT_ID_KEY);
        $client->setRandomId(self::KNOWN_CLIENT_ID_RANDOM);

        $client->setSecret(self::KNOWN_CLIENT_SECRET);
        $client->setAllowedGrantTypes(array_merge($client->getAllowedGrantTypes(), $grantTypes));
        $client->setRedirectUris($redirectUrls);

        /** @var UserManagerInterface $userManager */
        $manager->persist($client);
    }

    /**
     * Force-access to the protected $id to set.
     */
    private function setClientId($client, $_id)
    {
        $class = new \ReflectionClass(Client::class);
        $property = $class->getProperty('id');
        $property->setAccessible(true);

        $property->setValue($client, $_id);
    }
}
