<?php
namespace AppBundle\DataFixtures\MongoDB\d\Oauth2;

use App\Oauth2Scopes;
use AppBundle\Document\Oauth\Client;
use AppBundle\Document\User;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadOauth2Data implements FixtureInterface, ContainerAwareInterface
{
    use \Tests\Traits\Oauth;

    // These can be used for testing
    const KNOWN_CLIENT_ID = '5b51ec6b636239778924b671_36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';
    const KNOWN_CLIENT_SECRET = '1c9u3i9i0nogkc40os8480g88kokc480kkw0coc0c0wggw8k80';

    // split the client_id for the fields (must match KNOWN_CLIENT_ID!!)
    const KNOWN_CLIENT_ID_KEY = '5b51ec6b636239778924b671';
    const KNOWN_CLIENT_ID_RANDOM = '36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844';

    /** @var ContainerInterface|null */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        if (!$this->container) {
            throw new \RuntimeException('no container available');
        }

        // create DEV/Staging client credentials for Starling-Bank integration
        $this->newOauth2Client(
            $manager,
            '5b8659c8a6af70a6e8f92a54',         // starlingbank client-ID
            '7ffe8826f451770c28eb8c2fdf5d6e74', // client's Random -- complete ID is 'clientid_random', joined with '_'
            '24a92e3610dfff608978cec8f76599f2', // client secret
            [Oauth2Scopes::USER_STARLING_SUMMARY, Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY],  // 'user.starling.summary' + adds 'authorization_code'
            [
                'https://demo-developer.possiblefs.com/oauth-redirect/so-sure', // preferred, To be confirmed
                //'https://demo-developer.possiblefs.com/oauth-redirect/sosure',
                //#'https://developer.starlingbank.com/oauth-redirect/so-sure',       PROD/LIVE
                //#'https://developer.starlingbank.com/oauth-redirect/sosure',        PROD
            ]
        );


        /** @var Client $client */
        $client = $this->newOauth2Client(
            $manager,
            self::KNOWN_CLIENT_ID_KEY,
            self::KNOWN_CLIENT_ID_RANDOM,
            self::KNOWN_CLIENT_SECRET,
            [Oauth2Scopes::USER_STARLING_SUMMARY, Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY],
            [
                'http://dev.so-sure.net:40080/ops/pages',
                'https://testing.wearesosure.com/ops/pages',
                'https://waterloo.testing.wearesosure.com/ops/pages',
                'https://monument.testing.wearesosure.com/ops/pages',
                'https://staging.wearesosure.com/ops/pages',
                'http://dev.so-sure.net:40080/oauth/v2/auth',
                'http://dev.so-sure.net:40080/',
                'https://oauth-sandbox.starlingbank.com/',
            ]
        );

        /** @var UserManagerInterface $userManager */
        $userManager = $this->container->get('fos_user.user_manager');
        /** @var User $user */
        $user = $userManager->findUserByEmail('employee@so-sure.com');
        if (!$user) {
            throw new \RuntimeException('expected to have a valid user to attach to OauthAccessToken');
        }
        $this->newOauth2AccessToken($manager, $client, $user, 'test-with-api-employee');

        /** @var User $user */
        $user = $userManager->findUserByEmail('julien+apple@so-sure.com');
        if ($user) {
            $this->newOauth2AccessToken($manager, $client, $user, 'test-with-api-julien');
        }

        $manager->flush();
    }
}
