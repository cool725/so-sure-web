<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-net
 */
class DigitsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $digits;
    protected static $dm;
    protected static $rootDir;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$digits = self::$container->get('app.digits');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }

    /**
     * @expectedException \Exception
     */
    public function testBadConsumerKey()
    {
        try {
            static::$digits->validateUser('', '');
        } catch (\Exception $e) {
            $this->assertContains('consumer key', $e->getMessage());

            throw $e;
        }
    }

    /**
     * @expectedException \Exception
     */
    public function testBadHost()
    {
        $consumerKey = static::$container->getParameter('digits_consumer_key');
        try {
            static::$digits->validateUser('https://localhost', sprintf('oauth_consumer_key="%s"', $consumerKey));
        } catch (\Exception $e) {
            $this->assertContains('api host', $e->getMessage());

            throw $e;
        }
    }

    /* now occurs after network check, so unable to verify
    public function testBadCognitoId()
    {
        $consumerKey = static::$container->getParameter('digits_consumer_key');
        try {
            // @codingStandardsIgnoreStart
            static::$digits->validateUser(
                'https://api.digits.com/1.1/sdk/account.json?identity_id=eu-west-1%3A4652472c-586f-4878-9128-ac78906b1e87',
                sprintf('oauth_consumer_key="%s"', $consumerKey),
                'eu-west-1'
            );
            // @codingStandardsIgnoreEnd
        } catch (\Exception $e) {
            $this->assertContains('does not match session', $e->getMessage());

            throw $e;
        }
    }
    */

    /**
     * @expectedException \Exception
     */
    public function testGoodCognitoId()
    {
        $consumerKey = static::$container->getParameter('digits_consumer_key');
        try {
            // @codingStandardsIgnoreStart
            static::$digits->validateUser(
                'https://api.digits.com/1.1/sdk/account.json?identity_id=eu-west-1%3A4652472c-586f-4878-9128-ac78906b1e87',
                sprintf('oauth_consumer_key="%s"', $consumerKey),
                'eu-west-1:4652472c-586f-4878-9128-ac78906b1e87'
            );
            // @codingStandardsIgnoreEnd
        } catch (\Exception $e) {
            $this->assertContains('Bad Authentication data.', $e->getMessage());

            throw $e;
        }
    }
}
