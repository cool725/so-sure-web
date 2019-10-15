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
        $startDate = $start ? \DateTime::createFromFormat("Y-m-d", $start) : $this->startOfYear();
        $endDate = $end ? \DateTime::createFromFormat("Y-m-d", $end) : new \DateTime();
        if (!($startDate && $endDate)) {
            $output->writeln("<error>Dates are not valid</error>");
            return 1;
        }
        // Create sheets.
        $filename = $this->tempFile("adwordsPullout", $startDate, $endDate);
        $this->writeSheet($startDate, $endDate, $filename);
        // Mail sheets.
        foreach ($input->getOption("mail") as $target) {
            $this->mailer->send(
                "Database Pullout",
                $target,
                "<p>Database Pullout from wuhifheuwf ifurufto iuhregh</p>",
                "Database Pullout from uihhu to hiuerhg",
                [$filename]
            );
        }
    }

    /**
     * Creates a pullout for katte and co.
     * @param \DateTime $start    is the date from which to start reporting information.
     * @param \DateTime $end      is the date at which to stop reporting information.
     * @param string    $filename is the name of the file that the csv should be written to.
     */
    private function writeSheet($start, $end, $filename)
    {
        // Create an array representing the final data.
        /** @var UserRepository */
        $userRepo = $this->dm->getRepository(User::class);
        $users = $userRepo->findPulloutUsers($start, $end);
        $rows = [];
        foreach ($users as $user) {
            if (count($user->getPolicies()) == 0) {
                continue;
            }
            $purchased = 0;
            $net = 0;
            foreach ($user->getPolicies() as $policy) {
                if ($policy->getStart()) {
                    $purchased = 1;
                    $net = 0;
                    if (!$policy->isCooloffCancelled()) {
                        $net = 1;
                    }
                }
            }
            $birth = $user->getCreated()->format("Y-m-d");
            $campaign = $user->getAttribution()->getCampaignName();
            $channel = $user->getAttribution()->getCampaignSource();
            $device = $user->getAttribution()->getDeviceCategory();
            $key = sprintf("%s:%s:%s:%s", $birth, $campaign, $channel, $device);
            // if this line already exists we increment it, and if it does not then we create it.
            if (array_key_exists($key, $rows)) {
                $rows[$key]["createAccount"]++;
                $rows[$key]["purchased"] += $purchased;
                $rows[$key]["net"] += $net;
            } else {
                $rows[$key] = [
                    "date" => $birth,
                    "campaign" => $campaign,
                    "channel" => $channel,
                    "device" => $device,
                    "createAccount" => 1,
                    "purchased" => $purchased,
                    "net" => $net
                ];
            }
        }
        // now write the array.
        $file = fopen($filename, "w");
        fwrite($file, "Date,Campaign,Channel,Device,Create Account,Purchased,Net\n");
        foreach ($rows as $row) {
            fwrite($file, implode(",", $row)."\n");
        }
        fclose($file);
    }

    /**
     * Creates the right temporary filename for the given csv file and report date and time.
     * @param string $report is the type of csv that this is meant to be.
     * @param \DateTime $start is the start date of the data in the report.
     * @param \DateTime $end if the end date of the data in the report.
     * @return string containing the appropriate temporary file name.
     */
    private function tempFile($report, $start, $end)
    {
        return sprintf(
            "%s/%s(%s-to-%s).csv",
            sys_get_temp_dir(),
            $report,
            $start->format("Y-m-d"),
            $end->format("Y-m-d")
        );
    }
}
