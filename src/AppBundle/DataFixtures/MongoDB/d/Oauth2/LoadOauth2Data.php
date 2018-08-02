<?php
namespace AppBundle\DataFixtures\MongoDB\d\Oauth2;

use App\Oauth2Scopes;
use AppBundle\Document\Oauth\Client;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadOauth2Data implements FixtureInterface, ContainerAwareInterface
{
    use App\Tests\Traits\Oauth;

    // These can be used for testing
    const KNOWN_CLIENT_ID = '5b51ec6b636239778924b671_36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';
    const KNOWN_CLIENT_SECRET = '1c9u3i9i0nogkc40os8480g88kokc480kkw0coc0c0wggw8k80';

    // split the client_id for the fields (must match KNOWN_CLIENT_ID!!)
    const KNOWN_CLIENT_ID_KEY = '5b51ec6b636239778924b671';
    const KNOWN_CLIENT_ID_RANDOM = '36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';

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

        /** @var Client $client */
        $client = $this->newOauth2Client(
            $manager,
            [Oauth2Scopes::USER_STARLING_SUMMARY],
            [],
            self::KNOWN_CLIENT_ID_KEY,
            self::KNOWN_CLIENT_ID_RANDOM,
            self::KNOWN_CLIENT_SECRET
        );

        $this->newOauth2AccessToken($manager, $client, 'alister@so-sure.com', 'test-with-api-alister');

        $manager->flush();
        // $this->valdiateGedmoLogging($manager);
    }
}
