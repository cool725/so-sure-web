<?php

namespace AppBundle\Command;

use AppBundle\Annotation\DataChange;
use AppBundle\Classes\NoOp;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Charge;
use AppBundle\Document\Claim;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Service\FacebookService;
use AppBundle\Service\IntercomService;
use AppBundle\Service\MixpanelService;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

class OneOffCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var string */
    protected $environment;

    /** @var Reader */
    protected $reader;

    /** @var IntercomService */
    protected $intercomService;

    /** @var FacebookService */
    protected $facebookService;

    /** @var MixpanelService */
    protected $mixpanelService;

    public function __construct(
        DocumentManager $dm,
        Reader $reader,
        $environment,
        IntercomService $intercomService,
        FacebookService $facebookService,
        MixpanelService $mixpanelService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->reader = $reader;
        $this->environment = $environment;
        $this->intercomService = $intercomService;
        $this->facebookService = $facebookService;
        $this->mixpanelService = $mixpanelService;
    }

    protected function configure()
    {
        $this->setName('sosure:oneoff')
            ->setDescription('Run one off functionality')
            ->addArgument(
                'method',
                InputArgument::REQUIRED,
                'command to run'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Date at which to do things that require a date'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $method = $input->getArgument('method');
        if (method_exists($this, $method)) {
            call_user_func([$this, $method], $input, $output);
        } else {
            throw new \Exception(sprintf('Unknown command %s', $method));
        }
        $output->writeln('Finished');
    }

    private function goCompareAttribution(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findBy(['attribution.goCompareQuote' => ['$ne' => null]]);
        $count = 0;
        foreach ($users as $user) {
            /** @var User $user */
            if ($user->getAttribution()) {
                $this->mixpanelService->queuePersonProperties(
                    $user->getAttribution()->getMixpanelProperties(),
                    true,
                    $user
                );
                $count++;
            }
        }
        $this->dm->flush();
        $output->writeln(sprintf('%d attributions requeued', $count));
    }

    private function migratePaymentMethod(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findAll();
        $count = 0;
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            if (!$policy->hasPaymentMethod() && $policy->getUser() && $policy->getUser()->hasPaymentMethod()) {
                $policy->setPaymentMethod($policy->getUser()->getPaymentMethod());
                $count++;
            }
        }
        $this->dm->flush();
        $output->writeln(sprintf('%d policies updated with user payment method', $count));
    }

    private function facebookAds(InputInterface $input, OutputInterface $output)
    {
        $this->facebookService->monthlyLookalike(new \DateTime(), 'VAGRANT');
    }

    private function intercomConversation(InputInterface $input, OutputInterface $output)
    {
        $adminId = $this->intercomService->getAdminIdForConversationId(20387006809);
        $output->writeln($adminId);

        $userId = $this->intercomService->getUserIdForConversationId(20399101869);
        $output->writeln($userId);
    }

    private function removeTwoFactor(InputInterface $input, OutputInterface $output)
    {
        if (!in_array($this->environment, ['vagrant', 'staging', 'testing'])) {
            throw new \Exception('Only able to run in vagrant/testing/staging environments');
        }

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $admins = $repo->findUsersInRole(User::ROLE_ADMIN);
        foreach ($admins as $admin) {
            /** @var User $admin */
            $admin->setGoogleAuthenticatorSecret(null);
        }

        $this->dm->flush();
        $output->writeln('Removed 2fa for all admins');
    }

    private function cancelScheduledPayments(InputInterface $input, OutputInterface $output)
    {
        $count = 0;
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $twoDays = $this->subBusinessDays($this->now(), 2);
        $blocked = $repo->findBy(['status' => ScheduledPayment::STATUS_SCHEDULED, 'scheduled' => ['$lt' => $twoDays]]);
        foreach ($blocked as $block) {
            /** @var ScheduledPayment $block */
            if ($block->getPolicy()->isCancelled() || $block->getPolicy()->isExpired()) {
                $block->setStatus(ScheduledPayment::STATUS_CANCELLED);
                $count++;
            }
        }

        $this->dm->flush();
        $output->writeln(sprintf("%d updated", $count));
    }

    private function updateClaimExcess(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findAll();
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            if ($claim->getPolicy()) {
                $claim->setExpectedExcess($claim->getPolicy()->getCurrentExcess());
            }
        }

        $this->dm->flush();
    }

    private function updatePhoneExcess(InputInterface $input, OutputInterface $output)
    {
        // technically should compare the price dates to see if pic-sure excess should be set, but it doesn't add
        // any current value and is time consuming
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findAll();
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            foreach ($phone->getPhonePrices() as $price) {
                /** @var PhonePrice $price */
                $price->setExcess(PolicyTerms::getHighExcess());
                $price->setPicSureExcess(PolicyTerms::getLowExcess());
            }
        }

        $this->dm->flush();
    }

    private function updatePolicyExcess(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findAll();
        foreach ($policies as $policy) {
            /** @var PhonePolicy $policy */
            $terms = $policy->getPolicyTerms();
            /** @var PhonePremium $premium */
            $premium = $policy->getPremium();
            if ($premium) {
                $premium->setExcess($terms->getDefaultExcess());
                $premium->setPicSureExcess($terms->getDefaultPicSureExcess());
            }
        }

        $this->dm->flush();
    }

    private function updateNotes(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(Policy::class);
        $userRepo = $this->dm->getRepository(User::class);
        $polices = $repo->findAll();
        $validator = new AlphanumericSpaceDotValidator();
        foreach ($polices as $policy) {
            try {
                $updated = false;
                /** @var Policy $policy */
                $notes = $policy->getNotes();
                foreach ($notes as $time => $note) {
                    $date = \DateTime::createFromFormat('U', $time);
                    try {
                        $details = json_decode($note, true);
                        $user = $userRepo->find($details['user_id']);
                        $policy->addNoteDetails($validator->conform($details['notes']), $user, $date);
                    } catch (\Exception $e) {
                        if (is_string($note)) {
                            $policy->addNoteDetails($validator->conform($note), null, $date);
                        }
                    }
                    $policy->removeNote($time);
                    $updated = true;
                }

                if ($updated) {
                    $this->dm->flush();
                }
            } catch (\Exception $e) {
                throw new \Exception(sprintf('Error in policy %s. Ex: %s', $policy->getId(), $e->getMessage()));
            }
        }
    }

    private function removeOrphanUsersOnCharges(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(Charge::class);
        foreach ($repo->findAll() as $charge) {
            /** @var Charge $charge */
            try {
                $user = $charge->getUser();
                if ($user) {
                    NoOp::ignore([$user->getName()]);
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf('Removing orphaned user for charge %s', $charge->getId()));
                $charge->setUser(null);
                $this->dm->flush();
            }
        }
    }

    private function birthday(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(User::class);
        $count = 0;
        foreach ($repo->findAll() as $user) {
            /** @var User $user */
            if ($user->getBirthday()) {
                $midnight = $user->getBirthday()->format('H') == 0 && $user->getBirthday()->format('i') == 0 &&
                    $user->getBirthday()->format('P') == "+01:00";
                $eleven = $user->getBirthday()->format('H') == 23 && $user->getBirthday()->format('i') == 0 &&
                    $user->getBirthday()->format('P') == "+00:00";
                if ($midnight || $eleven) {
                    $convertedDate = clone $user->getBirthday();
                    $convertedDate = $convertedDate->add(new \DateInterval('PT1H'));
                    if (count($user->getValidPolicies(true)) > 0) {
                        print sprintf(
                            "%s %s %s 1%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            $convertedDate->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    } else {
                        print sprintf(
                            "%s %s %s 0%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            $convertedDate->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    }

                    $user->setBirthday($convertedDate);
                    $count++;
                }
            }
        }

        $this->dm->flush();
        $output->writeln(sprintf('%d records updated', $count));
    }

    /**
     * Adds a new yearly price to all active phones which is the current monthly price * 11.
     * @param InputInterface  $input  is used to get the date at which the price should start.
     * @param OutputInterface $output is used to write info to the user.
     */
    private function oneMonthFree(InputInterface $input, OutputInterface $output)
    {
        // validate the arguments.
        $dateString = $input->getOption("date");
        if (!$dateString) {
            $output->writeln("<error>Date must be provided to oneMonthFree</error>");
            return;
        }
        $date = \DateTime::createFromFormat("Y-m-d-H-i", $dateString);
        // Begin adding the prices on the phones.
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $this->dm->getRepository(Phone::class);
        $phones = $phoneRepo->findActive()->getQuery()->execute();
        $nSuccessful = 0;
        $nCrashed = 0;
        $nPriceless = 0;
        foreach ($phones as $phone) {
            $currentPrice = $phone->getCurrentMonthlyPhonePrice();
            if (!$currentPrice) {
                $nPriceless++;
                continue;
            }
            try {
                $yearlyPrice = new PhonePrice();
                $yearlyPrice->setGwp(($currentPrice->getGwp() * 11) / 12);
                $yearlyPrice->setValidFrom($date);
                $yearlyPrice->setStream(PhonePrice::STREAM_YEARLY);
                if ($currentPrice->getExcess()) {
                    $yearlyPrice->setExcess($currentPrice->getExcess());
                }
                if ($currentPrice->getPicSureExcess()) {
                    $yearlyPrice->setPicSureExcess($currentPrice->getPicSureExcess());
                }
                $phone->addPhonePrice($yearlyPrice);
                $this->dm->persist($phone);
                $nSuccessful++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $nCrashed++;
            }
        }
        $this->dm->flush();
        $output->writeln("Successful: {$nSuccessful}");
        if ($nCrashed > 0) {
            $output->writeln("Errored: {$nCrashed}");
        }
        if ($nPriceless > 0) {
            $output->writeln("No Price: {$nPriceless}");
        }
    }

    private function getDataChangeAnnotation($object, $category)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $items = [];
        // Get method annotation
        $reflectionObject = new \ReflectionObject($object);
        $properties = $reflectionObject->getProperties();
        foreach ($properties as $property) {
            /** @var \ReflectionProperty $property */
            /** @var DataChange $propertyAnnotation */
            $propertyAnnotation = $this->reader->getPropertyAnnotation($property, DataChange::class);
            if ($propertyAnnotation && in_array($category, $propertyAnnotation->getCategories())) {
                $items[$property->getName()] = $propertyAccessor->getValue($object, $property->getName());
            }
        }

        return $items;
    }
}
