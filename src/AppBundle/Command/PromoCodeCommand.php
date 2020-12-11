<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\Connection;
use AppBundle\Document\LogEntry;
use AppBundle\Document\Reward;
use AppBundle\Repository\PolicyRepository;

use AppBundle\Repository\RewardRepository;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;
use Symfony\Component\Routing\RouterInterface;

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

    /**
     * @var RouterInterface
     */
    protected $router;

    protected $adminPolicyRoute;

    public function __construct(
        DocumentManager $dm,
        MailerService $mailerService,
        Client $redis,
        RouterInterface $router
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->redis = $redis;
        $this->mailerService = $mailerService;
        $this->router = $router;
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
                'reward-valid-date',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter reward open by specified date',
                false
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
        try {
            $routeRaw = $this->router->getRouteCollection()->get('admin_policy');
            $this->adminPolicyRoute = dirname($routeRaw->getPath());
        } catch (\Exception $exception) {
            $output->writeln($exception->getMessage());
        }

//        /**
//         * This will grab all policy users with created times from date set
//         * Get passed date argument or use beginning of the month
//         */
//        $rewardValidDate = $input->getOption('reward-valid-date') ?
//            new \DateTime($input->getOption('reward-valid-date')) :
//            $this->startOfYear();

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
            /** @var Reward $reward */
            foreach ($activeScodeRewards as $idx => $reward) {
                $rewardConnections = $reward->getConnections();
                if ($rewardConnections) {
                    /** @var Connection $connection */
                    foreach ($rewardConnections as $connection) {
                        if (null !== $connection->getSourcePolicy()) {
                            $policies[$idx]['policy'] = $connection->getSourcePolicy();
                            $policies[$idx]['reward'] = $reward;
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
                if ($scode->isActive()) {
                    if (null !== $promoCodesArr) {
                        if (in_array($scode->getCode(), $promoCodesArr)) {
                            $activeRewardsData[] = $reward;
                        }
                    } else {
                        $activeRewardsData[] = $reward;
                    }
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
        foreach ($resultSet as $type => $collection) {
            if ($type === 'policy') {
                $policy = $collection;
            }

            if ($type === 'reward') {
                $reward = $collection;
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

        /** @var SCode $scode */
        return [
            'First name'                      => $policy->getUser()->getName(),
            'Last name'                       => $policy->getUser()->getLastName(),
            'Email'                           => $policy->getUser()->getEmail(),
            'Attribution'                     => $policy->getUser()->getAttribution() ?
                                                 $policy->getUser()->getAttribution()->getCampaignName() : 'N/A',
            'Latest attributions'             => $policy->getUser()->getLatestAttribution() ?
                                                 $policy->getUser()->getLatestAttribution()->getReferer() : 'N/A',
            'Number of claims'                => $policy->getUser()->getTotalClaims(),
            'Policy Status'                   => $policy->getStatus(),
            'Promo Code'                      => $scode ? $scode->getCode() : '',
            'Reward code redeemed date'       => $policy->getCreated()->format('Y-m-d H:i:s'),
            'Link to policy on Admin'         => $this->adminPolicyRoute . '/' . $policy->getId()
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
            self::PROMO_EMAIL_ADDRESS,
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
