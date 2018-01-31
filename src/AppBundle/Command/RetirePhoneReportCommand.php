<?php

namespace AppBundle\Command;

use AppBundle\Document\Phone;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;
use Symfony\Component\HttpFoundation\Request;

class RetirePhoneReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:phone:report')
            ->setDescription('Send a mail with list of phones that should be retired.')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'generates debug email output for testing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $retire = [];
        $debug = $input->getOption('debug');
        $mailer = $this->getContainer()->get('app.mailer');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repoPhone = $dm->getRepository(Phone::class);
        $phones = $repoPhone->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            if ($phone->shouldBeRetired()) {
                $retire[] = sprintf(
                    '%s %s (%s MB) Released: %s (%s months ago)',
                    $phone->getMake(),
                    $phone->getModel(),
                    $phone->getMemory(),
                    $phone->getReleaseDate()->format('m/Y'),
                    $phone->getMonthAge()
                );
            }
        }
        $join = (count($retire) > 0) ? join("<br/>\n", $retire) : 'No phones should be retired.<br/>';
        $message = sprintf("Phones that should be retired:<br/><br/>\n\n%s", $join);
        $mailer->send('Phones that should be retired report', 'tech+ops@so-sure.com', $message);
        if ($debug) {
            $output->writeln($message);
        }
        $output->writeln(sprintf('Found %s phones that should be retired.', count($retire)));
    }
}
