<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\SalvaExportService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SalvaRequeueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('requeue', SubmitType::class)
            ->add('reason', ChoiceType::class, [
                'choices' => [
                    SalvaExportService::QUEUE_UPDATED,
                    SalvaExportService::QUEUE_CANCELLED,
                    SalvaExportService::QUEUE_CREATED
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return $value;
                },
                'placeholder' => 'Choose a reason',
                'required' => true
            ])
        ;
    }
}
