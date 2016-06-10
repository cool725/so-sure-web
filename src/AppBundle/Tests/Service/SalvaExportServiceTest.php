<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\SalvaExportService;
use AppBundle\Classes\Salva;

/**
 * @group functional-net
 */
class SalvaExportServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $salva;
    protected static $dm;
    protected static $policyService;
    protected static $userManager;
    protected static $xmlFile;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$salva = self::$container->get('app.salva');
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        self::$xmlFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/salva-example-boat.xml",
            self::$container->getParameter('kernel.root_dir')
        );
    }

    public function tearDown()
    {
    }

    public function testValidate()
    {
        $xml = file_get_contents(self::$xmlFile);
        $this->assertTrue(self::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
    }

    public function testCreateXml()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-xml', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy);

        $xml = static::$salva->createXml($policy);
        $this->assertTrue(static::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
    }
}
