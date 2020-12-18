<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Influencer;
use AppBundle\Document\Reward;

use AppBundle\Repository\RewardRepository;
use AppBundle\Service\MailerService;
use AppBundle\Service\RouterService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;

class PromoCodeCommand extends ContainerAwareCommand
{

    const COMMAND_REPORT_NAME = 'Promo Code Report';
    const PROMO_EMAIL_ADDRESS = 'tech+ops@so-sure.com';

    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var MailerService */
    protected $mailerService;

    /** @var Client  */
    protected $redis;

    protected $setPromoCodes;

    /** @var RouterService */
    protected $route;

    protected $adminPolicyRoute;

    /** @var string */
    protected $environment;

    protected $emailAccounts;

    public function __construct(
        DocumentManager $dm,
        MailerService $mailerService,
        Client $redis,
        $environment,
        RouterService $route
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->redis = $redis;
        $this->mailerService = $mailerService;
        $this->environment = $environment;
        $this->route = $route;
    }


    protected function configure()
    {
        $this
            ->setName('sosure:promocode')
            ->setDescription('Show promo code affiliates')
            ->addOption(
                'promo-codes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Searching for a specific promo code(s)',
                false
            )
            ->addOption(
                'email-accounts',
                null,
                InputOption::VALUE_OPTIONAL,
                'What email address(s) to send to',
                self::PROMO_EMAIL_ADDRESS
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * Compile passed promo codes from argument to search through
         * Default will search all policies with active promo code
         */
        $promoCodes = $input->getOption('promo-codes');
        $promoCodesArr = null;
        if ($promoCodes) {
            $promoCodesArr = explode(",", $promoCodes);
            $this->setPromoCodes = implode('","', $promoCodesArr);
        }

        $this->emailAccounts = $input->getOption('email-accounts');
        if ($this->emailAccounts !== self::PROMO_EMAIL_ADDRESS) {
            $emailArr = explode(",", $this->emailAccounts);
            $emailF = [];
            foreach ($emailArr as $emailAccount) {
                if (filter_var($emailAccount, FILTER_VALIDATE_EMAIL)) {
                    $emailF[] = $emailAccount;
                }
            }
            if (!empty($emailF)) {
                $this->emailAccounts = $emailF;
            } else {
                $this->emailAccounts = self::PROMO_EMAIL_ADDRESS;
            }
        }

        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);

        /** @var array $rewards */
        $rewards = $rewardRepo->getRewards();
        if (empty($rewards)) {
            return;
        }
        $policies = [];
        $filteredData = [];

        $output->writeln('Filtering active rewards...');
        $activeScodeRewards = $this->filterActiveRewards($rewards, $promoCodesArr);
        $output->writeln('Found ' . count($activeScodeRewards) . ' active rewards.');
        try {
            if (empty($activeScodeRewards)) {
                $output->writeln('Nothing to process. Ending');
                return;
            }
            $influencerRepo = $this->dm->getRepository(Influencer::class);
            $organisation = null;
            /** @var Reward $reward */
            foreach ($activeScodeRewards as $idx => $reward) {
                $rewardConnections = $reward->getConnections();

                /** @var Influencer $influencer */
                $influencer = $influencerRepo->find($reward->getId());
                if (null !== $influencer) {
                    $organisation = $influencer->getOrganisation();
                }

                if ($rewardConnections) {
                    /** @var Connection $connection */
                    foreach ($rewardConnections as $idx2 => $connection) {
                        if (null !== $connection->getSourcePolicy()) {
                            $policies[$idx2]['policy'] = $connection->getSourcePolicy();
                            $policies[$idx2]['reward'] = $reward;
                            $policies[$idx2]['organisation'] = $organisation;
                        }
                    }
                }
            }

            if (!empty($policies)) {
                foreach ($policies as $pIdx => $resultSet) {
                    $filteredData[] = $this->formatPayload($policies[$pIdx]);
                }
            }

            if (!empty($filteredData)) {
                $output->writeln('Formatted payload...creating CSV');
                $this->sendCsv($filteredData, $output);
                $output->writeln('Inserted ' . count($filteredData) . ' records');
            }
        } catch (\Exception $exc) {
            $output->writeln($exc->getMessage());
        }
        $output->writeln('All done!');


    }

    private function filterActiveRewards($rewards, $promoCodesArr = null) : array
    {
        $activeRewardsData = [];
        /** @var Reward $reward */
        foreach ($rewards as $reward) {
            /** @var SCode $scode */
            $scode = $reward->getSCode();
            if ($scode) {
                if (null !== $promoCodesArr) {
                    if (in_array($scode->getCode(), $promoCodesArr)) {
                        $activeRewardsData[] = $reward;
                    }
                } else {
                    $activeRewardsData[] = $reward;
                }
            }
        }
        return $activeRewardsData;
    }

    private function formatPayload(array $resultSet) : array
    {
        $policy = null;
        $reward = null;
        $scode = null;
        $organisation = null;
        foreach ($resultSet as $type => $collection) {
            if ($type === 'policy') {
                $policy = $collection;
            }

            if ($type === 'reward') {
                $reward = $collection;
            }

            if ($type === 'organisation') {
                $organisation = $collection;
            }
        }
        /** @var Policy $policy */
        if (null === $reward || null === $policy) {
            return [];
        }
        /** @var Reward $reward */
        if ($reward) {
            $scode = $reward->getSCode();
        }

        /** @var Policy $policy */
        return [
            'First name'                      => $policy->getUser()->getFirstName(),
            'Last name'                       => $policy->getUser()->getLastName(),
            'Email'                           => $policy->getUser()->getEmail(),
            'Attribution'                     => $policy->getUser()->getAttribution() ?
                $policy->getUser()->getAttribution()->getCampaignName() : 'N/A',
            'Latest attributions'             => $policy->getUser()->getLatestAttribution() ?
                $policy->getUser()->getLatestAttribution()->getCampaignName() : 'N/A',
            'Number of claims'                => $policy->getUser()->getTotalClaims(),
            'Policy Status'                   => $policy->getStatus(),
            'Promo Code'                      => $scode ? $scode->getCode() : '',
            'Organisation'                    => $organisation,
            'Reward code redeemed date'       => $policy->getCreated()->format('Y-m-d H:i:s'),
            'Link to policy on Admin'         => $this->route->generateUrl('admin_policy', ['id' => $policy->getId()])
        ];
    }


    private function sendCsv(array $filteredItems, OutputInterface $output)
    {

        /** create the csv tmp file */
        $fileName = "promocode-".time().".csv";
        $file = "/tmp/" . $fileName;
        $cspReport = fopen($file, "w");
        if (isset($filteredItems['0'])) {
            fputcsv($cspReport, array_keys($filteredItems['0']));
            foreach ($filteredItems as $values) {
                fputcsv($cspReport, $values);
            }
        }
        fclose($cspReport);
        $output->writeln('Completed CSV..sending mail.');

        $this->mailerService->send(
            self::COMMAND_REPORT_NAME,
            $this->emailAccounts,
            "<h4>".self::COMMAND_REPORT_NAME . ": </h4><br /><br />"
            . "Number of Policies found: " . count($filteredItems) . "<br />"
            . "File: " . $fileName . "<br />"
            . "Promo Codes set: " . $this->setPromoCodes . "<br /><br />",
            null,
            [$file]
        );
        unset($file);
        $output->writeln('Mail sent!');
    }
}
