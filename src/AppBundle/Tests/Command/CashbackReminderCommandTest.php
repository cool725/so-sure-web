<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use AppBundle\Document\Cashback;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group functional-net
 */
class CashbackReminderCommandTest extends BaseControllerTest
{
    public function setUp()
    {
        parent::setUp();

        /** @var DocumentManager $dm */
        $dm = $this->getDocumentManager();
        self::$dm = $dm;
    }

    public function callCommand($expectedOutput, String $status)
    {
        $application = new Application(self::$kernel);
        $application->add(new OpsReportCommand(self::$container->get('app.mailer'), self::$redis, self::$container->get('aws.s3')));
        $command = $application->find('sosure:cashback:reminder');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            'status' => $status,
            '--dry-run' => true,
            '--force' => true
        ));
        $output = $commandTester->getDisplay();
        foreach ($expectedOutput as $item) {
            $this->assertContains($item, $output);
        }
    }

    public function testCashbackMissingReminder()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCashbackMissingReminder', $this),
            'foo',
            null,
            static::$dm
        );

        $policy = static::initPolicy(
            $user,
            static::$dm,
            null,
            null
        );

        $policy->setStatus(Policy::STATUS_EXPIRED);
        $cashback = self::createCashback($policy, \DateTime::createFromFormat('U', time()), Cashback::STATUS_MISSING);

        self::$dm->persist($cashback);
        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        $expected = [
            'Found',
            'matching policies, email sent'
        ];

        $this->callCommand($expected, 'missing');
    }

    public function testCashbackPendingReminder()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCashbackPendingReminder', $this),
            'foo',
            null,
            static::$dm
        );

        $policy = static::initPolicy(
            $user,
            static::$dm,
            null,
            null
        );

        $policy->setStatus(Policy::STATUS_EXPIRED);
        $cashback = self::createCashback(
            $policy,
            \DateTime::createFromFormat('U', time()),
            Cashback::STATUS_PENDING_PAYMENT
        );

        self::$dm->persist($cashback);
        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        $expected = [
            'Found',
            'matching policies, email sent'
        ];

        $this->callCommand($expected, 'pending-payment');
    }

    public function testCashbackReminderHelp()
    {
        $expected = [
            Cashback::STATUS_MISSING,
            Cashback::STATUS_PENDING_PAYMENT
        ];

        $this->callCommand($expected, 'testCashbackReminderHelp');
    }
}
