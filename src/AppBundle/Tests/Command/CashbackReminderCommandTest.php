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

        /** @var PolicyService $policyService */
        $policyService = self::$container->get('app.policy');
        self::$policyService = $policyService;
    }

    public function callCommand($expectedOutput)
    {
        $application = new Application(self::$kernel);
        $application->add(new OpsReportCommand());
        $command = $application->find('sosure:cashback:reminder');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            '--dry-run',
        ));
        $output = $commandTester->getDisplay();
        foreach ($expectedOutput as $item) {
            $this->assertContains($item, $output);
        }
    }

    public function testCashbackReminder()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(self::generateEmail('foobar', $this));
        $policy->setStatus(Policy::STATUS_EXPIRED);

        /** @var Cashback $cashback */
        $cashback = new Cashback();
        $cashback->setAccountName('foobar');
        $cashback->setAccountNumber('12345678');
        $cashback->setSortCode('123456');
        $cashback->setStatus(Cashback::STATUS_PENDING_PAYMENT);

        self::$policyService->cashback($policy, $cashback);

        self::$dm->persist($cashback);
        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        $this->callCommand(
            [
                'Found',
                'cashback pending policies. Mail sent'
            ]
        );
    }
}
