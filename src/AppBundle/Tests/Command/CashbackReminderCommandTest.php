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
            '--dry-run' => true,
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

        $cashback = self::createCashback($policy, new \DateTime(), Cashback::STATUS_PENDING_PAYMENT);

        self::$dm->persist($cashback);
        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        $this->callCommand(
            [
                'Policy',
                'found with pending status'
            ]
        );
    }
}
