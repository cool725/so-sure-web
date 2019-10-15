<?php

namespace AppBundle\Command;

use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Lead;
use AppBundle\Document\PhonePolicy;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\MailerService;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Command for implementing katte and co pullout.
 */
class PulloutCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    /**
     * inserts the required dependencies into the command.
     * @param DocumentManager $dm     is the document manager for loading data.
     * @param LoggerInterface $logger is used for logging.
     * @param MailerService   $mailer is used to send the results.
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName("sosure:pullout")
            ->setDescription("Builds database pullout files and emails them")
            ->addOption(
                "start",
                null,
                InputOption::VALUE_REQUIRED,
                "Start date to collect data from"
            )
            ->addOption(
                "end",
                null,
                InputOption::VALUE_REQUIRED,
                "End date to collect data until"
            )
            ->addOption(
                "mail",
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                "Email addresses to receive the resultant data"
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // get commandline options.
        $start = $input->getOption("start");
        $end = $input->getOption("end");
        $startDate = $start ? new \DateTime($start, "YYYY-MM-DD") : $this->startOfYear();
        $endDate = $end ? new \DateTime($end, "YYYY-MM-DD") : new \DateTime();
        // Create sheets.
        $adwordsFile = tempnam("/tmp", "pull");
        $facebookFile = tempnam("/tmp", "pull");
        $adwords = $this->adwordsSheet($start, $end, $adwordsFile);
        $facebook = $this->facebookSheet($start, $end, $facebookFile);
        // Mail sheets.
        foreach ($input->getOption("mail") as $target) {
            $this->mailer->send(
                "Database Pullout",
                $target,
                "<p>I am the bingo bongo man</p>",
                "I am the bingo bongo man",
                [$adwordsFile, $facebookFile]
            );
        }
    }

    /**
     * Creates a pullout sheet on adwords campaigns.
     * @param \DateTime $start    is the date from which to start reporting information.
     * @param \DateTime $end      is the date at which to stop reporting information.
     * @param string    $filename is the name of the file that the csv should be written to.
     */
    private function adwordsSheet($start, $end, $filename)
    {
        $userRepo = $this->dm->getRepository(User::class);
        $policyRepo = $this->dm->getRepository(Policy::class);
        $file = fopen($filename, "w");
        fwrite($file, "writing to temp file");
        fclose($file);
    }

    /**
     * Creates a pullout sheet on facebook / instagram campaigns.
     * @param \DateTime $start    is the date from which to start reporting information.
     * @param \DateTime $end      is the date at which to stop reporting information.
     * @param string    $filename is the name of the file that the csv should be written to.
     */
    private function facebookSheet($start, $end, $filename)
    {
        $userRepo = $this->dm->getRepository(User::class);
        $policyRepo = $this->dm->getRepository(Policy::class);
        $file = fopen($filename, "w");
        fwrite($file, "writing to temp file");
        fclose($file);
    }
}
