<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group functional-net
 */
class OpsReportCommandTest extends KernelTestCase
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
        $application->add(new OpsReportCommand(self::$mailer, self::$redis, self::$container->get('aws.s3')));
        $command = $application->find('sosure:ops:report');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
        ));
        $output = $commandTester->getDisplay();
        $this->assertContains($expectedOutput, $output);

    }

    public function testOpsReportCommandEmptyValues()
    {
        $data = [
            'url' => '/testurl',
            'errors' => [
                ['name' => 'firstName', 'value' => '', 'message' => 'Missing First Name'],
                ['name' => 'Address', 'value' => '', 'message' => 'Missing First Name'],
                ['name' => 'lastName', 'value' => '', 'message' => 'Missing LastName']
            ],
            'browser' => 'Internal'
        ];
        $now = \DateTime::createFromFormat('U', time());
        self::$redis->hset('client-validation', json_encode($data), $now->format('U'));
        $this->callCommand('no validation');
    }

    public function testOpsReportCommandFoundErrors()
    {
        $data = [
            'url' => '/testurl2',
            'errors' => [
                ['name' => 'firstName', 'value' => '2', 'message' => 'Missing First Name'],
                ['name' => 'lastName', 'value' => '', 'message' => 'Missing LastName']
            ],
            'browser' => 'Internal'
        ];
        $now = \DateTime::createFromFormat('U', time());
        self::$redis->hset('client-validation', json_encode($data), $now->format('U'));
        $this->callCommand('found validation');
    }

    public function testOpsReportCommandEmptyName()
    {
        $data = [
            'url' => '/testurl2',
            'errors' => [
                ['value' => '', 'message' => 'This field is required.'],
            ],
            'browser' => 'Internal'
        ];
        $now = \DateTime::createFromFormat('U', time());
        self::$redis->hset('client-validation', json_encode($data), $now->format('U'));
        $this->callCommand('no validation');
    }

    public function testOpsReportCommandNoData()
    {
        $data = [
            'url' => '/testurl3',
            'browser' => 'Internal'
        ];
        $now = \DateTime::createFromFormat('U', time());
        self::$redis->hset('client-validation', json_encode($data), $now->format('U'));
        $this->callCommand('no validation');
    }

    public function testOpsReportCsp()
    {
        $data = [
            'csp-report' => [
                'blocked-uri' => 'http://www.mytesturi.com',
                'user-data' => 'Symfony Browser'
            ]];
        self::$redis->rpush('csp', json_encode($data));
        self::$redis->rpush('csp', json_encode($data));
        self::$redis->rpush('csp', json_encode($data));
        $this->callCommand('3 CSP');
    }
}
