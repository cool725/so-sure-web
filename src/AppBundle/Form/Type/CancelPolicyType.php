<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Cancel;
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
        /** @var Policy $policy */
        $policy = $builder->getData()->getPolicy();
        $data = [];
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_ACTUAL_FRAUD, 'Fraud (actual)');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_SUSPECTED_FRAUD, 'Fraud (suspected)');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_DISPOSSESSION, 'Dispossession');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_WRECKAGE, 'Wreckage');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_UPGRADE, 'Upgrade');

        $preferred = [];
        if ($policy->isWithinCooloffPeriod() && !$policy->hasMonetaryClaimed(true)) {
            // if requested cancellation reason has already been set by the user, just allow cooloff
            // however, if not set, then allow subcategories
            if ($policy->getRequestedCancellationReason()) {
                $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, 'Cooloff');
                $preferred[] = Policy::CANCELLED_COOLOFF;
            } else {
                foreach (Policy::$cooloffReasons as $cooloff) {
                    $value = Cancel::getEncodedCooloffReason($cooloff);
                    $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, $value, $value);
                    $preferred[] = $value;
                }
            }
        } else {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User Requested');
        }

        if ($policy->getStatus() == Policy::STATUS_UNPAID) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_UNPAID, 'Unpaid');
            $preferred[] = Policy::CANCELLED_UNPAID;
        }

        $builder
            ->add('cancellationReason', ChoiceType::class, [
                'choices' => $data,
                'preferred_choices' => $preferred,
                'placeholder' => 'Cancellation reason'
            ])
            ->add('cancel', SubmitType::class)
        ;
    }

    private function addCancellationReason($data, $policy, $reason, $name, $value = null)
    {
        if (!$value) {
            $value = $reason;
        }
        if ($policy->canCancel($reason)) {
            $data[$name] = $value;
        }

        return $data;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Cancel',
        ));
    }
}
