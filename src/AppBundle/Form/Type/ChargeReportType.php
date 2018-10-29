<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use AppBundle\Document\Charge;

class ChargeReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'All' => 'all',
                    'Address' => Charge::TYPE_ADDRESS,
                    'SMS' => Charge::TYPE_SMS,
                    'GSMA' => Charge::TYPE_GSMA,
                    'Make and Model' => Charge::TYPE_MAKEMODEL,
                    'Claims Check' => Charge::TYPE_CLAIMSCHECK,
                    'Claims Damage' => Charge::TYPE_CLAIMSDAMAGE,
                    'Bank Account' => Charge::TYPE_BANK_ACCOUNT,
                    'Affiliate' => Charge::TYPE_AFFILIATE
                ],
                'attr' => ['class' => 'form-control']
            ])
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'format' => 'MM-yyyy',
                'data' => \DateTime::createFromFormat('U', time()),
                'html5' => false,
                'attr' => ['class' => 'form-control', 'autocomplete' => 'off']
            ])
            ->add('build', SubmitType::class, [
                'label' => 'Build Report',
                'attr' => ['class' => 'btn btn-info']
            ])
            ->setMethod('GET');
    }
}
