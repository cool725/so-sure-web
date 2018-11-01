<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use AppBundle\Document\Cashback;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use PhpParser\Node\Scalar\String_;
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
        $application->add(new OpsReportCommand(self::$container->get('app.mailer'), self::$redis));
        $command = $application->find('sosure:cashback:reminder');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            '--dry-run' => 1,
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

        $cashback = new Cashback();
        $cashback->setAccountName('foobar');
        $cashback->setAccountNumber('12345678');
        $cashback->setSortCode('123456');
        $cashback->setStatus(Cashback::STATUS_PENDING_PAYMENT);

        $policy->setCashback($cashback);

        self::$dm->persist($cashback);
        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        $this->callCommand(
            [
                'Policy',
                'found with status'
            ]
        );
    }
}
