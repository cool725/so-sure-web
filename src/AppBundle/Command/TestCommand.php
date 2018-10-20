<?php

namespace AppBundle\Command;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\Claim;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\User;
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

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:test')
            ->setDescription('Test')
        ;
    }

    protected $reader;

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->reader = $this->getContainer()->get('annotation_reader');
        // $this->testBirthday();
        $claim = new Claim();
        $claim->setNotificationDate(new \DateTime());
        print_r($this->getDataChangeAnnotation($claim, 'salva'));
        $output->writeln('Finished');
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
