<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DirectBacsReceiptType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $now = \DateTime::createFromFormat('U', time());
        $year = $now->format('Y');
        $years = [$year, $year - 1];
        $builder
            ->add('date', DateType::class, ['required' => true, 'years' => $years])
            ->add('manual', CheckboxType::class, ['required' => false])
            ->add('amount', TextType::class, ['required' => true])
            ->add('totalCommission', TextType::class, ['required' => true])
            ->add('notes', TextType::class, ['required' => true])
            ->add('reference', TextType::class, ['required' => true])
            ->add('save', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Payment\BacsPayment',
        ));
    }
}
