<?php

namespace AppBundle\Command;

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
            'policyRenewal' => [
                'potIncrease',
                'potDecrease',
                'noPotIncrease',
                'noPotDecrease',
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
        if (in_array($template, $templates['cashback'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Cashback::class);
            $data = [
                'cashback' => $repo->findOneBy([]),
                'withdraw_url' => $this->getContainer()->get('app.router')->generateUrl(
                    'homepage',
                    []
                ),
            ];
        } elseif (in_array($template, $templates['potReward'])) {
            $dm = $this->getManager();
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
            $repo = $dm->getRepository(StandardConnection::class);
            $connection = $repo->findOneBy(['value' => ['$gt' => 0]]);
            $policyService = $this->getContainer()->get('app.policy');

            return $policyService->connectionReduced($connection);
        } elseif (in_array($template, $templates['policy'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            $policyService = $this->getContainer()->get('app.policy');

            return $policyService->resendPolicyEmail($policy);
        } elseif (in_array($template, $templates['policyCancellation'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_ACTIVE]);
            foreach ($policies as $policy) {
                break;
            }
            if (!$policy) {
                throw new \Exception('Unable to find matching policy');
            }
            $policyService = $this->getContainer()->get('app.policy');
            $baseTemplate = sprintf('AppBundle:Email:%s', $template);

            return $policyService->cancelledPolicyEmail($policy, $baseTemplate);
        } elseif (in_array($template, $templates['policyRenewal'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_PENDING_RENEWAL]);
            $policy = null;
            foreach ($policies as $pendingRenewal) {
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
                if ($prevPolicy->getNextPolicy()->getPremium()->getMonthlyPremiumPrice() <
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
            $policyService = $this->getContainer()->get('app.policy');
            return $policyService->pendingRenewalEmail($policy);
        } elseif (in_array($template, $templates['picsure'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->findOneBy(['status' => Policy::STATUS_ACTIVE]);
            $data = [
                'policy' => $policy,
            ];
        } else {
            throw new \Exception(sprintf('Unsupported template %s for email debug. Add data', $template));
        }

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
