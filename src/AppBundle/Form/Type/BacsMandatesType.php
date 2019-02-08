<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;

class BacsMandatesType extends AbstractType
{
    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPendingMandates()->getQuery()->execute();
        $mandates = [];
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $serialNumber = $policy->getBacsBankAccount()->getMandateSerialNumber();
            $mandates[$serialNumber][$policy->getPolicyNumber()] = $serialNumber;
        }

        $builder
            ->add('serialNumber', ChoiceType::class, [
                'placeholder' => 'Select a name (will approval all name for that serial number)',
                'choices' => $mandates,
            ])
            ->add('approve', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
