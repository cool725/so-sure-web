<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\OpsReportCommand;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\Sanctions;
use AppBundle\Listener\SanctionsListener;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Component\Console\Tester\CommandTester;
use AppBundle\Document\User;

/**
 * @group functional-net
 */
class SanctionsReportCommandTest extends BaseControllerTest
{
    protected static $sanctions;

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
        self::$sanctions = self::$container->get('app.sanctions');
    }


    public function callCommand($expectedOutput)
    {

        $application = new Application(self::$kernel);
        $application->add(new OpsReportCommand(self::$container->get('app.mailer'), self::$redis, self::$container->get('aws.s3')));
        $command = $application->find('sosure:sanctions:report');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
            '--debug'  => '1',
        ));
        $output = $commandTester->getDisplay();
        foreach ($expectedOutput as $item) {
            $this->assertContains($item, $output);
        }
    }

    public function testSanctionsReportNoData()
    {
        $this->callCommand(['Happy days','0 records']);
    }

    public function testSanctionsReportFoundData()
    {
        $sanctions = new Sanctions();
        $sanctions->setSource(Sanctions::SOURCE_UK_TREASURY);
        $sanctions->setType(Sanctions::TYPE_USER);
        $sanctions->setFirstName('Nashwan');
        $sanctions->setLastName('ABD AL-BAQI');
        self::$dm->persist($sanctions);

        $sanctionsCompany = new Sanctions();
        $sanctionsCompany->setSource(Sanctions::SOURCE_UK_TREASURY);
        $sanctionsCompany->setType(Sanctions::TYPE_COMPANY);
        $sanctionsCompany->setCompany('Aboo oka');
        self::$dm->persist($sanctionsCompany);

        self::$dm->flush();

        $user = new User();
        $user->setId(1);
        $user->setFirstName('Nashwan');
        $user->setLastName('ABD-AL');
        $user1 = $user->getId();

        $matches = self::$sanctions->checkUser($user);
        $this->assertTrue(count($matches) > 0);

        self::$redis->rpush(
            SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY,
            serialize(['user' => ['id'=>$user->getId(), 'name' => $user->getName()], 'matches' => $matches])
        );
        $user = new User();
        $user->setId(2);
        $user->setFirstName('Nash');
        $user->setLastName('ABD-AL');
        $user2 = $user->getId();

        $matches = self::$sanctions->checkUser($user);
        $this->assertTrue(count($matches) > 0);
        self::$redis->rpush(
            SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY,
            serialize(['user' => ['id'=>$user->getId(), 'name' => $user->getName()], 'matches' => $matches])
        );
        $company = new CustomerCompany();
        $company->setId(3);
        $company->setName('Aboo');

        $company1 = $company->getId();


        $matches = self::$sanctions->checkCompany($company);
        $this->assertTrue(count($matches) > 0);

        self::$redis->rpush(
            SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY,
            serialize(['company' => ['id'=>$company->getId(), 'name' => $company->getName()], 'matches' => $matches])
        );
        $this->callCommand(
            [
                '3 records',
                'admin/user/'.$user1,
                'admin/user/'.$user2,
                'admin/user/'.$company1,
                'Nash ABD-AL',
            'Nashwan ABD-AL',
            'Aboo'
            ]
        );
    }
}
