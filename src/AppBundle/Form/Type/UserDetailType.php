<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use AppBundle\Validator\Constraints\AgeValidator;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\OptOut\EmailOptOut;

class UserDetailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $years = [];
        $now = new \DateTime();
        for ($year = (int) $now->format('Y'); $year >= $now->format('Y') - AgeValidator::MAX_AGE; $year--) {
            $years[] = $year;
        }

        $hasActivePolicy = $builder->getData()->hasActivePolicy();
        $builder
            ->add('birthday', DateType::class, [
                'attr' => [
                    'readonly' => $hasActivePolicy,
                    'disabled' => $hasActivePolicy
                ],
                'years' => $years,
            ])
            ->add('mobileNumber', TextType::class, [
                'attr' => [
                    'readonly' => $hasActivePolicy,
                    'disabled' => $hasActivePolicy
                ]
            ])
            ->add('update', SubmitType::class, [
                'attr' => [
                    'readonly' => $hasActivePolicy,
                    'disabled' => $hasActivePolicy
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\User',
        ));
    }
}
