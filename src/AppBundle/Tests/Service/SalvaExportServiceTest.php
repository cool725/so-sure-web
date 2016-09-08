<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
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
    protected static $policyRepo;

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
        self::$policyRepo = self::$dm->getRepository(Policy::class);
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
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy);

        $xml = static::$salva->createXml($policy);
        $this->assertTrue(static::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
    }

    public function testNonProdInvalidPolicyQueue()
    {
        $this->assertTrue(static::$salva->queue(new SalvaPhonePolicy(), SalvaExportService::QUEUE_CREATED));
    }

    public function testProdInvalidPolicyQueue()
    {
        static::$salva->setEnvironment('prod');
        $this->assertFalse(static::$salva->queue(new SalvaPhonePolicy(), SalvaExportService::QUEUE_CREATED));
        static::$salva->setEnvironment('test');
    }

    public function testProdValidPolicyQueue()
    {
        $user = static::createUser(
            static::$userManager,
            'notasosureemail@gmail.com',
            'bar'
        );
        static::$salva->setEnvironment('prod');
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isValidPolicy());
        $this->assertTrue(static::$salva->queue($updatedPolicy, SalvaExportService::QUEUE_CREATED));
    }
}
