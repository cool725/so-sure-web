<?php

namespace AppBundle\Command;

use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;

class TestCommand extends BaseCommand
{
    use DateTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:test')
            ->setDescription('Test')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->testBirthday();
        $output->writeln('Finished');
    }

    private function testBirthday()
    {
        $repo = $this->getManager()->getRepository(User::class);
        foreach ($repo->findAll() as $user) {
            /** @var User $user */
            if ($user->getBirthday()) {
                if (($user->getBirthday()->format('H') == 0 && $user->getBirthday()->format('P') == "+01:00") ||
                    $user->getBirthday()->format('H') == 23 && $user->getBirthday()->format('P') == "+00:00") {
                    if (count($user->getValidPolicies(true)) > 0) {
                        print sprintf(
                            "%s %s 1%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    } else {
                        print sprintf(
                            "%s %s 0%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    }
                }
            }
        }
    }
}
