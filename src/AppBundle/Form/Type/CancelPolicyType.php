<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Cancel;
use AppBundle\Document\User;
use AppBundle\Service\RequestService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class CancelPolicyType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    /**
     * @var RequestService
     */
    private $requestService;

    /**
     * @param RequestService $requestService
     * @param boolean        $required
     */
    public function __construct(RequestService $requestService, $required)
    {
        $this->requestService = $requestService;
        $this->required = $required;
    }

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
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User requested');


        $preferred = [];
        if ($policy->isWithinCooloffPeriod(null, false) && !$policy->hasMonetaryClaimed(true)) {
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
        } elseif ($policy->isWithinCooloffPeriod(null, true) && !$policy->hasMonetaryClaimed(true)) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User Requested');
            $preferred[] = Policy::CANCELLED_USER_REQUESTED;

            // if requested cancellation reason has already been set by the user, just allow cooloff
            // however, if not set, then allow subcategories
            if ($policy->getRequestedCancellationReason()) {
                $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, 'Cooloff (Extended)');
            } else {
                foreach (Policy::$cooloffReasons as $cooloff) {
                    $value = Cancel::getEncodedCooloffReason($cooloff);
                    $data = $this->addCancellationReason(
                        $data,
                        $policy,
                        Policy::CANCELLED_COOLOFF,
                        sprintf('%s (Extended)', $value),
                        $value
                    );
                }
            }
        } elseif (!$policy->hasMonetaryClaimed(true) && $policy->getStatus() != Policy::STATUS_UNPAID) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User Requested');
            $preferred[] = Policy::CANCELLED_USER_REQUESTED;
        }

        if ($policy->getStatus() == Policy::STATUS_UNPAID) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_UNPAID, 'Unpaid');
            $preferred[] = Policy::CANCELLED_UNPAID;
        }

        $builder
            ->add('cancellationReason', ChoiceType::class, [
                'choices' => $data,
                'preferred_choices' => $preferred,
                'placeholder' => $policy->hasOpenClaim() ? 'OPEN CLAIM - DO NOT CANCEL' : 'Cancellation reason'
            ])
            ->add('cancellationReason', ChoiceType::class, [
                'choices' => $data,
                'preferred_choices' => $preferred,
                'placeholder' => $policy->hasOpenClaim() ? 'OPEN CLAIM - DO NOT CANCEL' : 'Cancellation reason'
            ])
            ->add('cancel', SubmitType::class)
        ;

        /** @var User $user */
        $user = $this->requestService->getUser();
        if ($user && $user->hasRole(User::ROLE_ADMIN)) {
            $builder->add('fullRefund', ChoiceType::class, [
                'required' => $this->required,
                'data' => false,
                'choices' => [
                    'Yes' => true,
                    'No' => false,
                ],
                'expanded' => true,
                'multiple' => false,
            ]);
        }

        if ($policy->hasOpenClaim()) {
            $builder->add('force', CheckboxType::class, [
                'required' => false,
            ]);
        }
    }

    private function addCancellationReason($data, Policy $policy, $reason, $name, $value = null)
    {
        if (!$value) {
            $value = $reason;
        }
        if ($policy->canCancel($reason, null, true)) {
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
