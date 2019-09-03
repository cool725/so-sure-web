<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Note\Note;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\Reward;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Lead;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\RewardRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Faker\Provider\cs_CZ\DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\SoSure;

/**
 * Command for exporting CSV reports on various collections of company data.
 */
class SCodeReportCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var SearchService */
    protected $searchService;

    /**
     * inserts the required dependencies into the command.
     * @param S3Client        $s3            is the amazon s3 client for uploading generated reports.
     * @param DocumentManager $dm            is the document manager for loading data.
     * @param string          $environment   is the environment name used to upload to the right location in amazon s3.
     * @param LoggerInterface $logger        is used for logging.
     * @param SearchService   $searchService provides geographical information about users.
     */
    public function __construct(
        S3Client $s3,
        DocumentManager $dm,
        $environment,
        LoggerInterface $logger,
        SearchService $searchService
    ){
        parent::__construct();
        $this->s3 = $s3;
        $this->dm = $dm;
        $this->environment = $environment;
        $this->logger = $logger;
        $this->searchService = $searchService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:scode:report')
            ->setDescription('Run a scode report export')
            ->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                'show debug output'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $debug = $input->getOption('debug');
        $lines = [];
        $headLine = $this->makeLine(
            "Policy Number",
            "Policy Start Date",
            "Policy End Date",
            "Policy Status",
            "# of Previous Policy",
            "Lead Sorce",
            "SCode Type",
            "SCode Name",
            "Latest Campaign Source",
            "Latest Campaign Name",
            "Referrer",
            "First Campaign Source",
            "First Campaign Name",
            "Reward Pot Value",
            "Invite Actions Made",
            "Number of Connections"
        );
        if ($debug) {
            $output->writeln($headLine);
        }
        $lines[] = $headLine;
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findCurrentPolicies();
        /** @var PhonePolicy $policy */
        foreach ($policies as $policy) {
            $policyNumber = $policy->getPolicyNumber();
            $policyStartDate = $policy->getStart()->format('Y-m-d H:i:s');
            $policyEndDate = $policy->getStaticEnd()->format('Y-m-d H:i:s');
            $policyStatus = $policy->getStatus();
            $policyPreviousPolicyCount = $this->getPreviousPolicyCount($policy);
            $policyLeadSource = $policy->getLeadSource();
            $policySCodeType = $this->getFirstSCodeUsedType($policy);
            $policySCodes = $this->getSCodesUsed($policy);
            $userLatestCampaignSource = '';
            $userLatestCampaignName = '';
            if ($policy->getUser()->getLatestAttribution()) {
                $userLatestCampaignSource = $policy->getUser()->getLatestAttribution()->getCampaignSource();
                $userLatestCampaignName = $policy->getUser()->getLatestAttribution()->getCampaignName();
            }
            $userReferrer = $policy->getUser()->getReferer();
            $userFirstCampaignSource = '';
            $userFirstCampaignName = '';
            if ($policy->getUser()->getAttribution()) {
                $userFirstCampaignSource = $policy->getUser()->getAttribution()->getCampaignSource();
                $userFirstCampaignName = $policy->getUser()->getAttribution()->getCampaignName();
            }
            $policyRewardPotValue = $policy->getPotValue();
            $policyInvitationsCount = count($policy->getInvitationsAsArray());
            $policyConnectionsCount = count($policy->getConnections());
            $line = $this->makeLine(
                $policyNumber,
                $policyStartDate,
                $policyEndDate,
                $policyStatus,
                $policyPreviousPolicyCount,
                $policyLeadSource,
                $policySCodeType,
                $policySCodes,
                $userLatestCampaignSource,
                $userLatestCampaignName,
                $userReferrer,
                $userFirstCampaignSource,
                $userFirstCampaignName,
                $policyRewardPotValue,
                $policyInvitationsCount,
                $policyConnectionsCount
            );
            if ($debug) {
                $output->writeln($line);
            }
            $lines[] = $line;
        }
        if ($debug) {
            $output->write(json_encode($lines, JSON_PRETTY_PRINT));
        } else {
            $this->uploadS3(implode(PHP_EOL, $lines), 'score_report.csv');
        }
    }

    private function getPreviousPolicyCount(Policy $policy, $counter = 0)
    {
        if ($policy->hasPreviousPolicy()) {
            $counter++;
            $this->getPreviousPolicyCount($policy->getPreviousPolicy(), $counter);
        }
        return $counter;
    }

    private function getFirstSCodeUsedType(Policy $policy)
    {
        $connections = $policy->getConnections()->toArray();

        /** @var Connection $connection */
        $oldest = new \DateTime();
        $firstConnection = new \stdClass();
        foreach ($connections as $connection) {
            if ($connection->getDate() < $oldest) {
                $oldest = $connection->getDate();
                $firstConnection = $connection;
            }
        }
        $retVal = "";
        if ($firstConnection instanceof RewardConnection) {
            $retVal = "reward";
        } elseif ($firstConnection instanceof StandardConnection) {
            $retVal = "standard";
        }
        return $retVal;
    }

    private function getSCodesUsed(Policy $policy)
    {
        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        $connections = $policy->getConnections();
        $retVal = "";
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            if ($connection instanceof RewardConnection) {
                /** @var Reward $reward */
                $rewards = $rewardRepo->findBy(['user.id' => $connection->getLinkedUser()->getId()]);
                foreach ($rewards as $reward) {
                    if ($reward->getSCode()) {
                        $retVal .= $reward->getSCode()->getCode() . ';';
                    } else {
                        $retVal .= "signupbonus;";
                    }
                }
            } else {
                if ($connection->getLinkedPolicy() instanceof Policy) {
                    $retVal .= $connection->getLinkedPolicy()->getStandardSCode()->getCode() . ';';
                }
            }
        }
        return $retVal;
    }

    /**
     * Makes a line of the csv with quotes around the items and commas between them.
     * @param mixed ...$item are all of the string items to concatenate of variable number.
     * @return string
     */
    private function makeLine(...$item)
    {
        return '"' . implode('","', func_get_args()) . '"';
    }
}
