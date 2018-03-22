<?php

namespace AppBundle\Command;

use AppBundle\Document\Phone;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Brightstar;

class PhoneUpdateCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:phone:update')
            ->setDescription('Update all phone data casing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findAll();
        foreach ($phones as $phone) {
            if ($phone->getMake() == "ALL") {
                continue;
            }
            /** @var Phone $phone */
            $phone->setMakeCanonical($phone->getMake());
            $phone->setModelCanonical($phone->getModel());
            if ($phone->getAlternativeMake()) {
                $phone->setAlternativeMakeCanonical($phone->getAlternativeMake());
            }
        }
        $dm->flush();

        $output->writeln('Finished');
    }
}
