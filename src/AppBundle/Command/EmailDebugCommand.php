<?php

namespace AppBundle\Command;

use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Service\JudopayService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RouterService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Cashback;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\SoSure;

class EmailDebugCommand extends BaseCommand
{
    use CurrencyTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:email:debug')
            ->setDescription('Send out an email of one of our templates')
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'Email to send to',
                'non-prod-web@so-sure.com'
            )
            ->addOption(
                'variation',
                null,
                InputOption::VALUE_REQUIRED,
                'Variation to use'
            )
            ->addOption(
                'options',
                null,
                InputOption::VALUE_NONE,
                'Display all options'
            )
            ->addArgument(
                'template',
                InputArgument::REQUIRED,
                'Template (e.g. cashback/approved)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Client $redis */
        $redis = $this->getContainer()->get('snc_redis.mailer');
        $output->writeln(sprintf('%d emails in queue (prior to this)', $redis->llen('swiftmailer')));
        $env = $this->getContainer()->getParameter('kernel.environment');
        if (!in_array($env, ['vagrant', 'staging', 'testing'])) {
            throw new \Exception('Only able to run in vagrant/testing/staging environments');
        }

        $template = $input->getArgument('template');
        $email = $input->getOption('email');
        $options = true === $input->getOption('options');
        $variation = $input->getOption('variation');

        $templates = [
            'bacs' => [
                'bacs/notification',
                'bacs/mandateCancelled',
                'bacs/mandateCancelledNameChange',
            ],
            'cashback' => [
                'cashback/approved-reduced',
                'cashback/approved',
                'cashback/claimed',
                'cashback/delay',
                'cashback/failed',
                'cashback/missing',
                'cashback/paid',
            ],
            'potReward' => [
                'potReward/adjusted',
            ],
            'policyConnection' => [
                'policy/connectionReduction',
            ],
            'policy' => [
                'policy/new',
            ],
            'policyFailedPayment' => [
                'policy/failedPayment',
                'policy/failedPaymentFinal',
                'policy/failedPaymentWithClaim',
                'policy/failedPaymentWithClaimFinal',
            ],
            'policyCancellation' => [
                'policy-cancellation/actual-fraud',
                'policy-cancellation/badrisk',
                'policy-cancellation/cooloff',
                'policy-cancellation/dispossesion',
                'policy-cancellation/suspected-fraud',
                'policy-cancellation/unpaid',
                'policy-cancellation/unpaidWithClaim',
                'policy-cancellation/upgrade',
                'policy-cancellation/user-requested',
                'policy-cancellation/user-requestedWithClaim',
                'policy-cancellation/wreckage',
            ],
            'policyRenewal' => [
                'policy/pendingRenewal',
            ],
            'picsure' => [
                'picsure/accepted',
                'picsure/rejected',
                'picsure/invalid',
            ],
        ];
        $variations = [
            'bacs' => [
                'cancelledClaimed',
            ],
            'policyRenewal' => [
                'potIncrease',
                'potDecrease',
                'potSame',
                'noPotIncrease',
                'noPotDecrease',
                'noPotSame',
            ],
            'potReward' => [
                'monthly',
                'yearly',
            ]
        ];

        if ($options) {
            $displayTemplates = [];
            foreach ($templates as $type => $typeTemplates) {
                $displayVariations = null;
                if (isset($variations[$type])) {
                    $displayVariations = json_encode($variations[$type]);
                }
                foreach ($typeTemplates as $typeTemplate) {
                    $displayTemplates[] = [$typeTemplate, $displayVariations];
                }
            }
            $table = new Table($output);
            $table
                ->setHeaders(array('Template', 'Variations'))
                ->setRows($displayTemplates)
            ;
            $table->render();
            return;
        }
        $data = [];
        if (in_array($template, $templates['bacs'])) {
            $dm = $this->getManager();
            /** @var UserRepository $repo */
            $repo = $dm->getRepository(User::class);
            /** @var User $user */
            $user = $repo->findOneBy(['paymentMethod.type' => 'bacs']);
            $data = [
                'user' => $user,
                'policy' => $user->getLatestPolicy(),
                'claimed' => $variation == 'cancelledClaimed' ? true : false,
            ];
        } elseif (in_array($template, $templates['cashback'])) {
            $dm = $this->getManager();
            /** @var CashbackRepository $repo */
            $repo = $dm->getRepository(Cashback::class);
            /** @var Cashback $cashback */
            $cashback = $repo->findOneBy([]);
            /** @var RouterService $router */
            $router = $this->getContainer()->get('app.router');
            $data = [
                'cashback' => $cashback,
                'withdraw_url' => $router->generateUrl(
                    'homepage',
                    []
                ),
            ];
        } elseif (in_array($template, $templates['potReward'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['nextPolicy' => ['$ne' => null]]);
            $policy = null;
            foreach ($policies as $checkPolicy) {
                if ($variation && $checkPolicy->getNextPolicy()->getPremiumPlan() != $variation) {
                    continue;
                }
                $policy = $checkPolicy;
                break;
            }
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy with a next policy for %s', $variation));
            }
            $data = [
                'policy' => $policy->getNextPolicy(),
                'additional_amount' => 10,
            ];
        } elseif (in_array($template, $templates['policyConnection'])) {
            $dm = $this->getManager();
            /** @var ConnectionRepository $repo */
            $repo = $dm->getRepository(StandardConnection::class);
            /** @var StandardConnection $connection */
            $connection = $repo->findOneBy(['value' => ['$gt' => 0]]);
            /** @var PolicyService $policyService */
            $policyService = $this->getContainer()->get('app.policy');

            return $policyService->connectionReduced($connection);
        } elseif (in_array($template, $templates['policy'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            /** @var PolicyService $policyService */
            $policyService = $this->getContainer()->get('app.policy');

            return $policyService->resendPolicyEmail($policy);
        } elseif (in_array($template, $templates['policyFailedPayment'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            /** @var JudopayService $judopayService */
            $judopayService = $this->getContainer()->get('app.judopay');

            return $judopayService->failedPaymentEmail($policy);
        } elseif (in_array($template, $templates['policyCancellation'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            /** @var PolicyService $policyService */
            $policyService = $this->getContainer()->get('app.policy');
            $baseTemplate = sprintf('AppBundle:Email:%s', $template);

            return $policyService->cancelledPolicyEmail($policy, $baseTemplate);
        } elseif (in_array($template, $templates['policyRenewal'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_PENDING_RENEWAL]);
            $policy = null;
            foreach ($policies as $pendingRenewal) {
                /** @var Policy $pendingRenewal */
                $prevPolicy = $pendingRenewal->getPreviousPolicy();
                if (!$prevPolicy || !$prevPolicy->getPremium() || !$prevPolicy->getNextPolicy() ||
                    !$prevPolicy->getNextPolicy()->getPremium()) {
                    continue;
                }
                if ($prevPolicy->getPotValue() > 0) {
                    if (in_array($variation, ['noPotIncrease', 'noPotDecrease'])) {
                        continue;
                    }
                } else {
                    if (in_array($variation, ['potIncrease', 'potDecrease'])) {
                        continue;
                    }
                }
                if ($this->areEqualToTwoDp(
                    $prevPolicy->getNextPolicy()->getPremium()->getMonthlyPremiumPrice(),
                    $prevPolicy->getPremium()->getMonthlyPremiumPrice()
                )) {
                    if (in_array($variation, ['noPotSame', 'potSame'])) {
                        continue;
                    }
                } elseif ($prevPolicy->getNextPolicy()->getPremium()->getMonthlyPremiumPrice() <
                    $prevPolicy->getPremium()->getMonthlyPremiumPrice()) {
                    if (in_array($variation, ['noPotIncrease', 'potIncrease'])) {
                        continue;
                    }
                } else {
                    if (in_array($variation, ['noPotDecrease', 'potDecrease'])) {
                        continue;
                    }
                }
                $policy = $prevPolicy;
                break;
            }
            if (!$policy || !$policy->getPremium() && !$policy->getNextPolicy() &&
                !$policy->getNextPolicy()->getPremium()) {
                throw new \Exception('Unable to find matching policy');
            }
            /** @var PolicyService $policyService */
            $policyService = $this->getContainer()->get('app.policy');
            return $policyService->pendingRenewalEmail($policy);
        } elseif (in_array($template, $templates['picsure'])) {
            $dm = $this->getManager();
            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->findOneBy(['status' => Policy::STATUS_ACTIVE]);
            $data = [
                'policy' => $policy,
            ];
        } else {
            throw new \Exception(sprintf('Unsupported template %s for email debug. Add data', $template));
        }

        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            sprintf('sosure:email:debug %s', $template),
            $email,
            sprintf('AppBundle:Email:%s.html.twig', $template),
            $data,
            sprintf('AppBundle:Email:%s.txt.twig', $template),
            $data
        );
        $output->writeln(sprintf('Queued email to %s', $email));
    }
}
