<?php

namespace AppBundle\Command;

use AppBundle\Document\DateTrait;
use AppBundle\Document\Company;
use AppBundle\Helpers\CsvHelper;
use AppBundle\Classes\SoSure;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\CompanyRepository;
use AppBundle\Service\RouterService;
use AppBundle\Service\ReportingService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Maknz\Slack\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Symfony\Component\Routing\RouterInterface;

class SlackCommand extends ContainerAwareCommand
{
    use DateTrait;
    const CORPORATE_CHECK_DAYS = 9;

    /** @var DocumentManager  */
    protected $dm;

    /** @var RouterService */
    protected $routerService;

    /** @var ReportingService */
    protected $reportingService;

    /** @var Client */
    protected $slackClient;

    public function __construct(
        DocumentManager $dm,
        RouterService $routerService,
        ReportingService $reportingService,
        Client $slackClient
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->routerService = $routerService;
        $this->reportingService = $reportingService;
        $this->slackClient = $slackClient;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:slack')
            ->setDescription('Send message to slack')
            ->addOption(
                'policy-channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Channel to post to',
                '#general'
            )
            ->addOption(
                'customer-channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Channel to post to',
                '#customer-contact'
            )
            ->addOption(
                'corporate-end-channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Channel to post about ending corporate policies',
                '#dev'
            )
            ->addOption(
                'weeks',
                null,
                InputOption::VALUE_REQUIRED,
                'Run for # of weeks instead of current date',
                null
            )
            ->addOption(
                'message',
                null,
                InputOption::VALUE_REQUIRED,
                'A message to append to the start of the output',
                null
            )
            ->addOption(
                'skip-slack',
                null,
                InputOption::VALUE_NONE,
                'Do not post to slack'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyChannel = $input->getOption('policy-channel');
        $customerChannel = $input->getOption('customer-channel');
        $corporateEndChannel = $input->getOption('corporate-end-channel');
        $weeks = $input->getOption('weeks');
        $message = $input->getOption('message');
        $skipSlack = $input->getOption('skip-slack');

        $text = $this->policies($policyChannel, $weeks, $skipSlack, $message);
        $output->writeln('KPI');
        $output->writeln('----');
        $output->writeln($text);
        $output->writeln('');

        $output->writeln('Unpaid');
        $output->writeln('----');
        $lines = $this->unpaid($customerChannel, $skipSlack, $message);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $output->writeln('Renewals');
        $output->writeln('----');
        $lines = $this->renewals($customerChannel, $skipSlack, $message);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $output->writeln('Corporate Endings');
        $output->writeln('----');
        $lines = $this->corporateEnding($corporateEndChannel, $skipSlack, $message);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $output->writeln('Cancelled w/Payment Owed');
        $output->writeln('----');
        $lines = $this->cancelledAndPaymentOwed($customerChannel, $skipSlack, $message);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');
    }

    /**
     * Sends info about corporate policies that are ending soon to slack.
     * @param string  $channel   is the name of the slack channel to write to.
     * @param boolean $skipSlack is whether to skip actually doing the writing to slack part.
     * @param string  $message   is some text to append to the start of what is written to slack or something.
     * @return array containing each message sent which is in this case just the one.
     */
    private function corporateEnding($channel, $skipSlack, $message)
    {
        /** @var CompanyRepository $companyRepo */
        $companyRepo = $this->dm->getRepository(Company::class);
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $list = [];
        $firstItem = true;
        $companies = $companyRepo->findAll();
        /* @var Company $company */
        foreach ($companies as $company) {
            $firstForCompany = true;
            $policies = $policyRepo->findEndingPoliciesForCompany($company, self::CORPORATE_CHECK_DAYS);
            /** @var Policy $policy */
            foreach ($policies as $policy) {
                if ($firstItem) {
                    $list[] = sprintf('*Corporate Policies Ending in next %d days*', self::CORPORATE_CHECK_DAYS);
                    $firstItem = false;
                }
                if ($firstForCompany) {
                    $list[] = sprintf('*%s*', $company->getName());
                    $firstForCompany = false;
                }
                $list[] = sprintf(
                    '- <%s|%s> ends %s',
                    $this->routerService->generateUrl('admin_policy', ['id' => $policy->getId()]),
                    $policy->getPolicyNumber(),
                    $policy->getEnd()->format('Y-m-d')
                );
            }
        }
        $text = implode(PHP_EOL, $list);
        if (!$skipSlack && count($list) > 0) {
            $this->send($text, $channel, $message);
        }
        return $list;
    }

    private function cancelledAndPaymentOwed($channel, $skipSlack, $message)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findAll();

        $lines = [];
        $now = \DateTime::createFromFormat('U', time());
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            if (!$policy->isPolicy() || !$policy->isCancelledAndPaymentOwed()) {
                continue;
            }
            $diff = $now->diff($policy->getEnd());
            if (!in_array($diff->days, [0])) {
                continue;
            }
            // @codingStandardsIgnoreStart
            $text = sprintf(
                "*Policy <%s|%s> has been cancelled w/success claim. User must re-purchase policy or pay outstanding amount.*",
                $this->routerService->generateUrl('admin_policy', ['id' => $policy->getId()]),
                $policy->getPolicyNumber()
            );
            $lines[] = $text;

            // @codingStandardsIgnoreEnd
            if (!$skipSlack) {
                $this->send($text, $channel, $message);
            }
        }

        return $lines;
    }

    private function unpaid($channel, $skipSlack, $message)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->getUnpaidPolicies();

        $lines = [];
        $now = \DateTime::createFromFormat('U', time());
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $diff = $now->diff($policy->getPolicyExpirationDate());
            if (!in_array($diff->days, [7, 14])) {
                continue;
            }
            // @codingStandardsIgnoreStart
            $text = sprintf(
                "*Policy <%s|%s> is scheduled to be cancelled in %d days*",
                $this->routerService->generateUrl('admin_policy', ['id' => $policy->getId()]),
                $policy->getPolicyNumber(),
                $diff->days
            );
            if ($policy->hasMonetaryClaimed(true)) {
                $text = sprintf(
                    "%s\n*WARNING: Policy has a monetary claim and should be retained if at all possible*",
                    $text
                );
            }
            if ($policy->hasOpenClaim(true)) {
                $text = sprintf(
                    "%s\n*WARNING: Policy has a open claim which should be cleared with Davies prior to cancellation*",
                    $text
                );
            }
            $lines[] = $text;

            // @codingStandardsIgnoreEnd
            if (!$skipSlack) {
                $this->send($text, $channel, $message);
            }
        }

        return $lines;
    }

    private function renewals($channel, $skipSlack, $message)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findBy(['status' => Policy::STATUS_DECLINED_RENEWAL]);

        $lines = [];
        $now = \DateTime::createFromFormat('U', time());
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $diff = $now->diff($policy->getRenewalExpiration());
            if (!in_array($diff->days, [5])) {
                continue;
            }
            if (!$policy->getPreviousPolicy()->displayRenewal($now)) {
                continue;
            }

            // @codingStandardsIgnoreStart
            $text = sprintf(
                "Policy <%s|%s> will be expired in %d days and customer has declined to renew. Please call customer.",
                $this->routerService->generateUrl('admin_policy', ['id' => $policy->getPreviousPolicy()->getId()]),
                $policy->getPreviousPolicy()->getPolicyNumber(),
                $diff->days
            );
            $lines[] = $text;

            // @codingStandardsIgnoreEnd
            if (!$skipSlack) {
                $this->send($text, $channel, $message);
            }
        }

        return $lines;
    }

    private function policies($channel, $weeks, $skipSlack, $message)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);

        $weekText = '';
        $start = new \DateTime('2016-12-05', SoSure::getSoSureTimezone());
        $targetEnd = new \DateTime('2020-12-31', SoSure::getSoSureTimezone());
        $dowOffset = 0;

        $startOfDay = $this->startOfDay();
        $startOfWeek = $this->startOfWeek();
        $dow = $startOfDay->diff($start)->days % 7;
        $offset = $dow - $dowOffset >= 0 ? $dow - $dowOffset : (7 - $dowOffset) + $dow;
        $start = clone $startOfDay;
        $start = $start->sub(new \DateInterval(sprintf('P%dD', $offset)));
        $end = clone $start;
        $end = $end->add(new \DateInterval('P6D'));
        $targetEndDiff = $targetEnd->diff($start);
        $weeksRemaining = floor($targetEndDiff->days / 7);

        $weekText = sprintf(
            '%s - %s (Full weeks remaining: %d)',
            $start->format('d/m/Y'),
            $end->format('d/m/Y'),
            $weeksRemaining
        );
        $growthTarget = 25000;

        $yesterday = $this->subDays($startOfDay, 1);
        $oneWeekAgo = $this->subDays($startOfDay, 7);

        $gross = 0;
        $renewal = 0;
        $upgrade = 0;
        $policies = $repo->findAllStartedPolicies($yesterday, $startOfDay);
        /** @var PhonePolicy $policy */
        foreach ($policies as $policy) {
            if ($policy->hasPreviousPolicy()) {
                $renewal++;
            } elseif ($policy->getUpgradedFrom()) {
                $upgrade++;
            } else {
                $gross++;
            }
        }
        $coolofsRenewal = $repo->countAllEndingPolicies(
            Policy::CANCELLED_COOLOFF,
            $yesterday,
            $startOfDay,
            true,
            null,
            'renewal'
        );
        $coolofsNew = $repo->countAllEndingPolicies(
            Policy::CANCELLED_COOLOFF,
            $yesterday,
            $startOfDay,
            true,
            null,
            'new'
        );
        $cancellations = $repo->countEndingByStatus(Policy::STATUS_CANCELLED, $yesterday, $startOfDay);
        $weekStart = $repo->countAllActivePolicies($startOfWeek);
        $weekTarget = ($growthTarget - $weekStart) / $weeksRemaining;
        $weekTargetIncCancellations = 1.2 * $weekTarget;
        $total = $repo->countAllActivePolicies($startOfDay);

        // @codingStandardsIgnoreStart
        $text = sprintf(
            "*%s*\n\nGross New Policies (last 24 hours): *%d*\nNet New Policies (last 24 hours): *%d*\nNon cooloff cancellations (last 24 hours): *%d*\nRenewals (last 24 hours): *%d*\nRenewals cooloff (last 24 hours): *%d*\nUpgrades (last 24 hours): *%d*\n\nWeekly Base Target: %d\nWeekly Target inc Cancellation: %d\nWeekly Actual: *%d*\nWeekly Remaining: *%d*\n\nOverall Target (%s): %d\nOverall Actual: *%d*\nOverall Remaining: *%d*\n\n_*Data as of %s (Europe/London)*_",
            $weekText,
            $gross,
            $gross - $coolofsNew,
            $cancellations - ($coolofsNew + $coolofsRenewal),
            $renewal,
            $coolofsRenewal,
            $upgrade,
            $weekTarget,
            $weekTargetIncCancellations,
            $total - $weekStart,
            $weekTargetIncCancellations + $weekStart - $total,
            $targetEnd->format('d/m/Y'),
            $growthTarget,
            $total,
            $growthTarget - $total,
            $startOfDay->format('d/m/Y H:i')
        );
        // @codingStandardsIgnoreEnd
        if (!$skipSlack) {
            $this->send($text, $channel, $message);
        }

        return $text;
    }

    private function send($text, $channel, $message)
    {
        if ($message) {
            $text = sprintf("*%s*\n%s", $message, $text);
        }
        $message = $this->slackClient->createMessage();
        $message
            ->to($channel)
            ->setText($text)
        ;
        $this->slackClient->sendMessage($message);
    }
}
