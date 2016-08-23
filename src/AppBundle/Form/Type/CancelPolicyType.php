<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class CancelPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $policy = $builder->getData()->getPolicy();
        $data = [
            Policy::CANCELLED_FRAUD => 'Fraud (actual)',
            Policy::CANCELLED_DISPOSSESSION => 'Dispossession',
            Policy::CANCELLED_WRECKAGE => 'Wreckage',
        ];
        $preferred = [];
        if ($policy->isWithinCooloffPeriod() && !$policy->hasMonetaryClaimed(true)) {
            $data[Policy::CANCELLED_COOLOFF] = 'Cooloff';
            $preferred[] = Policy::CANCELLED_COOLOFF;
        } else {
            $data[Policy::CANCELLED_USER_REQUESTED] = 'User Requested';
            $preferred[] = Policy::CANCELLED_USER_REQUESTED;
        }
        if ($policy->getStatus() == Policy::STATUS_UNPAID) {
            $data[Policy::CANCELLED_UNPAID] = 'Unpaid';
            $preferred[] = Policy::CANCELLED_UNPAID;
        }

        $builder
            ->add('cancellationReason', ChoiceType::class, [
                'choices' => $data,
                'preferred_choices' => $preferred,
            ])
            ->add('cancel', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Cancel',
        ));
    }
}
