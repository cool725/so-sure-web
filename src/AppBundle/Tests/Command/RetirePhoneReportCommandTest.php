<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\DocumentRepository;
use League\Flysystem\Exception;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group functional-net
 */
class RetirePhoneReportCommandTest extends KernelTestCase
{

    protected static $container;
    protected static $redis;
    protected static $kernel;
    protected static $client;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        self::$kernel = static::createKernel();
        self::$kernel->boot();

        self::$client = self::$kernel->getContainer()->get('test.client');

        //get the DI container
        self::$container = self::$kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function callCommand($expectedOutput)
    {

        $application = new Application(self::$kernel);
        $application->add(new OpsReportCommand(
            self::$container->get('app.mailer'),
            self::$redis,
            self::$container->get('aws.s3')
        ));
        $command = $application->find('sosure:phone:report');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
            '--debug'  => '1',
        ));
        $output = $commandTester->getDisplay();
        $this->assertContains($expectedOutput, $output);

    }

    public function testPhoneShouldBeRetiredReportCommand()
    {
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        /** @var DocumentRepository $policyTermsRepo */
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $phone = new Phone();
        $phone->init(
            'ShouldBeRetiredMakeMake',
            'ShouldBeRetiredTestModel',
            5,
            $latestTerms,
            64,
            ['shoudberetireddevice'],
            300,
            200,
            '',
            new \DateTime('2013-01-01')
        );
        $phone->setDetails(
            Phone::OS_ANDROID,
            '6.0.1',
            '6.0.2',
            1.6,
            8,
            64,
            true,
            500,
            320,
            300,
            12,
            true,
            new \DateTime('2012-11-11')
        );
        $phone->setActive(true);
        $dm->persist($phone);
        $dm->flush();
        $search = sprintf('%s %s', $phone->getMake(), $phone->getModel());
        $this->callCommand($search);
    }
}
