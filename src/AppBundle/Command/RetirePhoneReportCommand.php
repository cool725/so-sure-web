<?php

namespace AppBundle\Command;

use AppBundle\Document\Phone;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
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
    /** @var DocumentManager  */
    protected $dm;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(DocumentManager $dm, MailerService $mailerService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->mailerService = $mailerService;
    }

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
        /** @var PhoneRepository $repoPhone */
        $repoPhone = $this->dm->getRepository(Phone::class);
        $phones = $repoPhone->findActive()->getQuery()->execute();
        foreach ($phones as $phone) {
            /** @var Phone $phone */
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
        $this->mailerService->send(
            'Phones that should be retired report',
            'tech+ops@so-sure.com',
            $message,
            null,
            null,
            'marketing@so-sure.com'
        );
        if ($debug) {
            $output->writeln($message);
        }
        $output->writeln(sprintf('Found %s phones that should be retired.', count($retire)));
    }
}
