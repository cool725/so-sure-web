<?php

namespace AppBundle\Command;

use AppBundle\Listener\UnpaidListener;
use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Service\JudopayService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RouterService;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Mailer\Mailer;
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
use Symfony\Component\Routing\Router;

class EmailDebugCommand extends ContainerAwareCommand
{
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $environment;

    /** @var PolicyService */
    protected $policyService;

    /** @var JudopayService */
    protected $judopayService;

    /** @var Client */
    protected $redisMailer;

    /** @var RouterService */
    protected $routerService;

    /** @var MailerService */
    protected $mailerService;

    /** @var UnpaidListener */
    protected $unpaidListener;

    public function __construct(
        DocumentManager $dm,
        $environment,
        PolicyService $policyService,
        JudopayService $judopayService,
        Client $redisMailer,
        RouterService $routerService,
        MailerService $mailerService,
        UnpaidListener $unpaidListener
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->environment = $environment;
        $this->policyService = $policyService;
        $this->judopayService = $judopayService;
        $this->redisMailer = $redisMailer;
        $this->routerService = $routerService;
        $this->mailerService = $mailerService;
        $this->unpaidListener = $unpaidListener;
    }

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
        $output->writeln(sprintf(
            '%d emails in queue (prior to this)',
            $this->redisMailer->llen('swiftmailer')
        ));
        if (!in_array($this->environment, ['vagrant', 'staging', 'testing'])) {
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
                'bacs/bacsPaymentFailed',
                'bacs/bacsPaymentFailedWithClaim',
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
                'policy/skippedRenewal',
                'policy/trustpilot',
                'policy/detectedImei',
                'policy/invalidImei',
            ],
            'card' => [
                'card/failedPayment',
                'card/failedPaymentWithClaim',
                'card/cardExpiring',
                'card/cardMissing',
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
            'card' => [
                '1',
                '2',
                '3',
                '4',
            ],
            'policy' => [
                'preapproved',
            ],
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
            $claimed = $variation == 'cancelledClaimed' || $template == 'bacs/bacsPaymentFailedWithClaim';

            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = null;
            $policies = $repo->findBy(['paymentMethod.type' => 'bacs']);
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                if ($claimed  == $policy->hasMonetaryClaimed(true, true)) {
                    break;
                }
                $policy = null;
            }

            if ($policy) {
                $data = [
                    'user' => $policy->getUser(),
                    'policy' => $policy,
                    'claimed' => $claimed ? true : false,
                ];
            } else {
                // TODO: Eventually remove
                /** @var UserRepository $repo */
                $repo = $this->dm->getRepository(User::class);
                /** @var User $user */
                $user = $repo->findOneBy(['paymentMethod.type' => 'bacs']);
                if (!$user) {
                    throw new \Exception('Unknown  User');
                }
                /** @var Policy $policy */
                $policy = $user->getLatestPolicy();
                if (!$policy) {
                    throw new \Exception('Unknown Policy');
                }
                $data = [
                    'user' => $user,
                    'policy' => $policy,
                    'claimed' => $claimed ? true : false,
                ];
            }
            if (in_array($template, ['bacs/bacsPaymentFailed', 'bacs/bacsPaymentFailedWithClaim'])) {
                $failedPayments = $variation ?: 1;
                return $this->unpaidListener->emailNotification($policy, $failedPayments);
            }
        } elseif (in_array($template, $templates['cashback'])) {
            /** @var CashbackRepository $repo */
            $repo = $this->dm->getRepository(Cashback::class);
            /** @var Cashback $cashback */
            $cashback = $repo->findOneBy([]);
            $data = [
                'cashback' => $cashback,
                'withdraw_url' => $this->routerService->generateUrl(
                    'homepage',
                    []
                ),
            ];
        } elseif (in_array($template, $templates['potReward'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            $policies = $repo->findBy(['nextPolicy' => ['$ne' => null]]);
            $policy = null;
            foreach ($policies as $checkPolicy) {
                /** @var Policy $checkPolicy */
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
            /** @var ConnectionRepository $repo */
            $repo = $this->dm->getRepository(StandardConnection::class);
            /** @var StandardConnection $connection */
            $connection = $repo->findOneBy(['value' => ['$gt' => 0]]);

            return $this->policyService->connectionReduced($connection);
        } elseif (in_array($template, $templates['policy'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                /** @var PhonePolicy $policy */
                if ($variation == 'preapproved' &&
                    $policy->getPicSureStatus() == PhonePolicy::PICSURE_STATUS_PREAPPROVED) {
                    break;
                } elseif ($variation != 'preapproved' &&
                    $policy->getPicSureStatus() != PhonePolicy::PICSURE_STATUS_PREAPPROVED) {
                    break;
                }

                $policy = null;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }

            if ($template == 'policy/trustpilot') {
                $policy = new PhonePolicy();
                $policy->setPolicyNumber('Invalid/123');
                $user = new User();
                $user->setEmail('patrick@so-sure.com');
                $user->setFirstName('Patrick');
                $user->setLastName('McAndrew');
                $policy->setUser($user);
                return $this->mailerService->trustpilot($policy, MailerService::TRUSTPILOT_PURCHASE);
            } elseif ($template == 'policy/detectedImei') {
                return $this->policyService->detectedImeiEmail($policy);
            } elseif ($template == 'policy/invalidImei') {
                return $this->policyService->invalidImeiEmail($policy);
            } elseif ($template == 'policy/skippedRenewal') {
                return $this->policyService->skippedRenewalEmail($policy);
            } else {
                return $this->policyService->generatePolicyFiles($policy, true);
            }
        } elseif (in_array($template, $templates['card'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                if (!$policy->hasPolicyOrPayerOrUserJudoPaymentMethod()) {
                    $policy = null;
                    continue;
                }

                if ($template == 'card/failedPaymentWithClaim' &&
                    $policy->hasMonetaryClaimed(true, true) &&
                    $policy->hasPolicyOrUserValidPaymentMethod()) {
                    break;
                }

                if ($template == 'card/cardMissing' && !$policy->hasPolicyOrPayerOrUserValidPaymentMethod()) {
                    break;
                }

                if ($template != 'card/cardMissing' && $policy->hasPolicyOrPayerOrUserValidPaymentMethod()) {
                    break;
                }

                $policy = null;
            }

            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }

            if ($template != 'card/cardExpiring') {
                $failedPayments = $variation ?: 1;
                return $this->unpaidListener->emailNotification($policy, $failedPayments);
            } else {
                return $this->judopayService->cardExpiringEmail($policy);
            }
        } elseif (in_array($template, $templates['policyCancellation'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            $policy = null;
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            $baseTemplate = sprintf('AppBundle:Email:%s', $template);

            return $this->policyService->cancelledPolicyEmail($policy, $baseTemplate);
        } elseif (in_array($template, $templates['policyRenewal'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
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

            return $this->policyService->pendingRenewalEmail($policy);
        } elseif (in_array($template, $templates['picsure'])) {
            /** @var PolicyRepository $repo */
            $repo = $this->dm->getRepository(Policy::class);
            $policy = $repo->findOneBy(['status' => Policy::STATUS_ACTIVE]);
            $data = [
                'policy' => $policy,
            ];
        } else {
            throw new \Exception(sprintf('Unsupported template %s for email debug. Add data', $template));
        }

        $this->mailerService->sendTemplate(
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
