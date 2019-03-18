<?php

namespace AppBundle\Command;

use AppBundle\Document\DateTrait;
use AppBundle\Classes\SoSure;
use AppBundle\Repository\PhonePolicyRepository;
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
                'weeks',
                null,
                InputOption::VALUE_REQUIRED,
                'Run for # of weeks instead of current date',
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
        $weeks = $input->getOption('weeks');
        $skipSlack = $input->getOption('skip-slack');

        $text = $this->policies($policyChannel, $weeks, $skipSlack);
        $output->writeln('KPI');
        $output->writeln('----');
        $output->writeln($text);
        $output->writeln('');

        $output->writeln('Unpaid');
        $output->writeln('----');
        $lines = $this->unpaid($customerChannel, $skipSlack);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $output->writeln('Renewals');
        $output->writeln('----');
        $lines = $this->renewals($customerChannel, $skipSlack);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $output->writeln('Cancelled w/Payment Owed');
        $output->writeln('----');
        $lines = $this->cancelledAndPaymentOwed($customerChannel, $skipSlack);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        $output->writeln('');
    }

    private function cancelledAndPaymentOwed($channel, $skipSlack)
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
                $this->send($text, $channel);
            }
        }

        return $lines;
    }

    private function unpaid($channel, $skipSlack)
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
                $this->send($text, $channel);
            }
        }

        return $lines;
    }

    private function renewals($channel, $skipSlack)
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
                $this->send($text, $channel);
            }
        }

        return $lines;
    }

    private function policies($channel, $weeks, $skipSlack)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);

        $weekText = '';
        $start = new \DateTime('2016-12-05', SoSure::getSoSureTimezone());
        $targetEnd = new \DateTime('2019-09-01', SoSure::getSoSureTimezone());
        $dowOffset = 0;

        $startOfDay = $this->startOfDay(new \DateTime("now", SoSure::getSoSureTimezone()));
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
        $growthTarget = 10000;

        $yesterday = $this->subDays($startOfDay, 1);
        $oneWeekAgo = $this->subDays($startOfDay, 7);

        $cumulativeReport = $this->reportingService->getCumulativePolicies(
            new \DateTime(SoSure::POLICY_START, SoSure::getSoSureTimezone()),
            $startOfDay
        );
        $total = end($cumulativeReport)["close"];
        $upgrades = $repo->countAllEndingPolicies(Policy::CANCELLED_UPGRADE, $yesterday);
        $gross = $total - $repo->countAllActivePolicies($yesterday) - $upgrades;
        $cooloff = $repo->countAllEndingPolicies(Policy::CANCELLED_COOLOFF, $yesterday, $startOfDay);
        $cancellations = $repo->countEndingByStatus(Policy::STATUS_CANCELLED, $yesterday, $startOfDay);
        $weekStart = $repo->countAllActivePolicies($start);
        $weekTarget = ($growthTarget - $weekStart) / $weeksRemaining;
        $weekTargetIncCancellations = 1.2 * $weekTarget;

        // @codingStandardsIgnoreStart
        $text = sprintf(
            "*%s*\n\nGross Policies (last 24 hours): *%d*\nNet Policies (last 24 hours): *%d*\nNon cooloff cancellations (last 24 hours): *%d*\n\nWeekly Base Target: %d\nWeekly Target inc Cancellation: %d\nWeekly Actual: *%d*\nWeekly Remaining: *%d*\n\nOverall Target (%s): %d\nOverall Actual: *%d*\nOverall Remaining: *%d*\n\n_*Data as of %s (Europe/London)*_",
            $weekText,
            $gross,
            $gross - $cooloff,
            $cancellations - $cooloff,
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
            $this->send($text, $channel);
        }

        return $text;
    }

    private function send($text, $channel)
    {
        $message = $this->slackClient->createMessage();
        $message
            ->to($channel)
            ->setText($text)
        ;
        $this->slackClient->sendMessage($message);
    }
}
