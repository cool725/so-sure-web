<?php

namespace AppBundle\Command;

use AppBundle\Annotation\DataChange;
use AppBundle\Classes\NoOp;
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

class TestCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var Reader */
    protected $reader;

    public function __construct(DocumentManager $dm, Reader $reader)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->reader = $reader;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:test')
            ->setDescription('Test')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // $this->testBirthday();
        // $this->removeOrphanUsersOnCharges($output);
        // $this->updateNotes();

        //$this->updatePhoneExcess();
        //$this->updatePolicyExcess();

        $this->updateClaimExcess();

        $output->writeln('Finished');
    }

    private function updateClaimExcess()
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

    private function updatePhoneExcess()
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

    private function updatePolicyExcess()
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

    private function updateNotes()
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

    private function removeOrphanUsersOnCharges(OutputInterface $output)
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

    private function testBirthday()
    {
        $repo = $this->dm->getRepository(User::class);
        foreach ($repo->findAll() as $user) {
            /** @var User $user */
            if ($user->getBirthday()) {
                if (($user->getBirthday()->format('H') == 0 && $user->getBirthday()->format('P') == "+01:00") ||
                    $user->getBirthday()->format('H') == 23 && $user->getBirthday()->format('P') == "+00:00") {
                    if (count($user->getValidPolicies(true)) > 0) {
                        print sprintf(
                            "%s %s 1%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    } else {
                        print sprintf(
                            "%s %s 0%s",
                            $user->getId(),
                            $user->getBirthday()->format(\DateTime::ATOM),
                            PHP_EOL
                        );
                    }
                }
            }
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
