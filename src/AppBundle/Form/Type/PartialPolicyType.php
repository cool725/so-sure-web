<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PartialPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('phone', DocumentType::class, [
                    'placeholder' => 'Select your device',
                    'class' => 'AppBundle:Phone',
                    'query_builder' => function (DocumentRepository $dr) {
                        return $dr->findActive();
                    }
            ])
            ->add('imei', TextType::class)
            ->add('serialNumber', TextType::class)
            ->add('add', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\SalvaPhonePolicy',
        ));
    }
}
