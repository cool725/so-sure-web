<?php

namespace AppBundle\Command;

use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class SalvaManualPolicyCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /** @var CheckoutService */
    protected $checkoutService;

    public function __construct(DocumentManager $dm, PolicyService $policyService, CheckoutService $checkoutService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->policyService = $policyService;
        $this->checkoutService = $checkoutService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:salva:manual:policy')
            ->setDescription('Manually create a policy')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'email of user'
            )
            ->addArgument(
                'imei',
                InputArgument::REQUIRED,
                'Imei'
            )
            ->addArgument(
                'device',
                InputArgument::REQUIRED,
                'device'
            )
            ->addArgument(
                'memory',
                InputArgument::REQUIRED,
                'memory'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date to create'
            )
            ->addOption(
                'payments',
                null,
                InputOption::VALUE_REQUIRED,
                '1 for yearly, 12 monthly',
                12
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $imei = $input->getArgument('imei');
        $device = $input->getArgument('device');
        $memory = $input->getArgument('memory');
        $payments = $input->getOption('payments');
        $date = $input->getOption('date');
        if ($date) {
            $date = new \DateTime($date);
        } else {
            $date = \DateTime::createFromFormat('U', time());
        }

        $phone = $this->getPhone($device, $memory);

        $user = $this->getUser($email);
        if (!$user->getBirthday()) {
            $user->setBirthday(new \DateTime('1980-01-01'));
        }
        $phone = $this->getPhone($device, $memory);

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $policy->setPhone($phone, $date);
        $policy->setImei($imei);

        $this->dm->persist($policy);
        $this->dm->flush();

        $currentPrice = $phone->getCurrentPhonePrice(
            ($payments == 12) ? PhonePrice::STREAM_MONTHLY : PhonePrice::STREAM_YEARLY
        );
        if ($currentPrice && $payments == 12) {
            $amount = $currentPrice->getMonthlyPremiumPrice($date);
        } elseif ($currentPrice && $payments = 1) {
            $amount = $currentPrice->getYearlyPremiumPrice($date);
        } else {
            throw new \Exception('1 or 12 payments only');
        }

        $details = $this->checkoutService->testPayDetails(
            $policy,
            random_int(0, 999999),
            $amount,
            '4976 0000 0000 3436',
            '12/20',
            '452',
            $policy->getId()
        );
        // @codingStandardsIgnoreStart
        $this->checkoutService->add(
            $policy,
            $details['receiptId'],
            Payment::SOURCE_WEB_API,
            $date
        );

        // @codingStandardsIgnoreEnd

        $output->writeln(sprintf('Created Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
    }

    /**
     * @param string $email
     * @return User
     */
    private function getUser($email)
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $user;
    }

    /**
     * @param string     $device
     * @param mixed|null $memory
     * @return Phone
     */
    private function getPhone($device, $memory = null)
    {
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $this->dm->getRepository(Phone::class);

        $phone = null;
        if ($memory) {
            /** @var Phone $phone */
            $phone = $phoneRepo->findOneBy(['devices' => $device, 'memory' => (int)$memory]);
        } else {
            /** @var Phone $phone */
            $phone = $phoneRepo->findOneBy(['devices' => $device]);
        }

        if (!$phone) {
            throw new \Exception('Unable to find phone');
        }

        return $phone;
    }
}
