<?php

namespace AppBundle\Form\Type;

use AppBundle\Repository\PhoneRepository;
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
                    'query_builder' => function (PhoneRepository $dr) {
                        return $dr->findActiveInactive();
                    },
                    'choice_label' => function ($phone) {
                        return sprintf('%s%s', $phone->getActive() ? '' : '(OLD) ', $phone);
                    }
            ])
            ->add('imei', TextType::class, ['required' => false])
            ->add('serialNumber', TextType::class, ['required' => false])
            ->add('add', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\SalvaPhonePolicy',
        ));
    }
}
