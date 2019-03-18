<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\Policy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class BillingDayType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $enabled = false;
        /** @var Policy $policy */
        $policy = $builder->getData()->getPolicy();
        if ($policy) {
            if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
                $enabled = false;
            } else {
                $enabled = $policy->isPolicyPaidToDate() &&
                    !$policy->isWithinCooloffPeriod();
            }
        }

        $days = [];
        for ($i = 1; $i <= 28; $i++) {
            $days[$i] = $i;
        }
        $builder
            ->add('day', ChoiceType::class, [
                    'choices' => $days,
                    'required' => true
            ])
            ->add('update', SubmitType::class, [
                'disabled' => !$enabled,
                'attr' => ['title' => $enabled ?
                    null :
                    'Policy must be paid to date, not within cooloff period, and user cannot have bacs enabled'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\BillingDay',
        ));
    }
}
