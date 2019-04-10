<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use AppBundle\Command\PolicyUpdatePaymentCommand;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PolicyUpdatePaymentCommandTest extends BaseControllerTest
{
    protected static $container;
    protected static $redis;
    protected static $kernel;
    protected static $client;
    protected static $mailer;

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

        self::$mailer = self::$container->get('app.mailer');
    }

    public function callCommand($expectedOutput)
    {

        $application = new Application(self::$kernel);
        $application->add(new OpsReportCommand(self::$mailer, self::$redis));
        $command = $application->find('sosure:policy:update:payment');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
        ));
        $output = $commandTester->getDisplay();
        $this->assertContains($expectedOutput, $output);
    }

    /**
     * This should only be a one off command so there isn't any addition success messages
     * If the update worked then there will be no output at all.
     */
    public function testUpdatePaymentDetailsWhereTheyDontExist()
    {
        $this->callCommand('', '');
    }
}
