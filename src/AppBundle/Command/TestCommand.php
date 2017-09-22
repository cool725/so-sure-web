<?php

namespace AppBundle\Command;

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
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'id'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        $policyRepo = $this->getManager()->getRepository(Policy::class);

        if ($id) {
            $policy = $policyRepo->find($id);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            /*
            print_r($policy->getStart());
            print_r($policy->getEnd());
            $start = clone $policy->getStart();
            $start = $this->startOfDay($start);
            $endDate = clone $policy->getEnd();
            $endDate = $this->endOfDay($endDate);
            $diff = $start->diff($endDate);
            print_r($diff);

            $start = clone $policy->getStart();
            $start->setTimezone(new \DateTimeZone('Europe/London'));
            $start = $this->startOfDay($start);
            $endDate = clone $policy->getEnd();
            $endDate->setTimezone(new \DateTimeZone('Europe/London'));
            $endDate = $this->endOfDay($endDate);
            $diff = $start->diff($endDate);
            print_r($diff);
            */
            $policy->getSalvaProrataMultiplier(0);
            $policy->getSalvaProrataMultiplier(1);
            $policy->getSalvaProrataMultiplier(2);
            $policy->getSalvaProrataMultiplier(3);
        }

        $output->writeln('Finished');
    }
}
