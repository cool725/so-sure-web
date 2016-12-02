<?php

namespace AppBundle\Form\Type;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;

class PhoneMakeType extends AbstractType
{
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $make = $builder->getData()->getMake();
        $models = [];
        $phonePlaceholder = 'Select your phone make first';
        if ($make) {
            $phones = $this->dm->getRepository(Phone::class)->findActiveModels($make);
            foreach ($phones as $phone) {
                $models[$phone->getId()] = $phone->getModelMemory();
            }
            $phonePlaceholder = sprintf('Select your %s device', $make);
        }
        $builder
            ->add('make', ChoiceType::class, [
                    'placeholder' => 'Select phone make',
                    'choices' => $this->dm->getRepository(Phone::class)->findActiveMakes()
            ])
            ->add('phoneId', ChoiceType::class, [
                    'placeholder' => $phonePlaceholder,
                    'choices' => $models,
            ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PhoneMake',
            'csrf_protection'   => false,
        ));
    }
}
