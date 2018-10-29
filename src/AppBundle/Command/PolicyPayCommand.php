<?php

namespace AppBundle\Command;

use AppBundle\Document\PhonePolicy;
use AppBundle\Service\JudopayService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class PolicyPayCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /** @var JudopayService */
    protected $judopayService;

    public function __construct(DocumentManager $dm, PolicyService $policyService, JudopayService $judopayService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->policyService = $policyService;
        $this->judopayService = $judopayService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:policy:pay')
            ->setDescription('Pay for policy')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'email of user'
            )
            ->addOption(
                'payments',
                null,
                InputOption::VALUE_REQUIRED,
                '1 for yearly, 12 monthly',
                12
            )
            ->addOption(
                'customer-ref',
                null,
                InputOption::VALUE_REQUIRED,
                'Use a different customer reference'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy (partial) Id'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $payments = $input->getOption('payments');
        $customerRef = $input->getOption('customer-ref');
        $policyId = $input->getOption('id');
        $date = \DateTime::createFromFormat('U', time());

        $policy = null;
        $user = null;

        if ($policyId) {
            if ($policy = $this->getPhonePolicy($policyId)) {
                $user = $policy->getUser();
            }
        } else {
            $user = $this->getUser($email);
        }

        if (!$user) {
            throw new \Exception('Unable to find user');
        }

        if (!$user->getPaymentMethod()) {
            throw new \Exception('Policy payment only works if the user has a payment method');
        }
        if (!$user->hasValidDetails()) {
            throw new \Exception(
                'User is missing details required to create a policy such as mobile, birthday and/or address'
            );
        }

        if (!$policy) {
            foreach ($user->getPolicies() as $policyItem) {
                if (!$policyItem->getStatus()) {
                    $policy = $policyItem;
                }
            }
        }

        if (!$policy) {
            throw new \Exception('Unable to find a partial policy');
        }
        if ($policy->getStatus()) {
            throw new \Exception('Policy is not partial');
        }

        $phone = $policy->getPhone();

        if ($payments == 12) {
            $amount = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date);
        } elseif ($payments = 1) {
            $amount = $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $date);
        } else {
            throw new \Exception('1 or 12 payments only');
        }

        $details = $this->judopayService->runTokenPayment(
            $user,
            $amount,
            $date->getTimestamp(),
            $policy->getId(),
            $customerRef
        );
        $this->judopayService->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_TOKEN,
            $user->getJudoPaymentMethod() ? $user->getJudoPaymentMethod()->getDeviceDna() : null,
            $date
        );

        $output->writeln(sprintf('Created Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
    }

    /**
     * @param string $email
     * @return User
     */
    private function getUser($email)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $user;
    }

    /**
     * @param mixed $id
     * @return PhonePolicy
     */
    private function getPhonePolicy($id)
    {
        $repo = $this->dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        return $policy;
    }
}
