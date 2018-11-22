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
        $application->add(new OpsReportCommand(self::$container->get('app.mailer'), self::$redis));
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
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(self::generateEmail('testCashbackMissingReminder', $this));
        $policy->setStatus(Policy::STATUS_EXPIRED);

        $cashback = self::createCashback($policy, new \DateTime(), Cashback::STATUS_MISSING);

        self::$dm->persist($cashback);
        self::$dm->persist($policy->getUser());
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
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(self::generateEmail('testCashbackPendingReminder', $this));
        $policy->setStatus(Policy::STATUS_EXPIRED);

        $cashback = self::createCashback($policy, new \DateTime(), Cashback::STATUS_PENDING_PAYMENT);

        self::$dm->persist($cashback);
        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        $expected = [
            'Found',
            'matching policies, email sent'
        ];

        $this->callCommand($expected, 'pending-payment');
    }
}
