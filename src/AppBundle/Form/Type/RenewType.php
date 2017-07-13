<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class RenewType extends AbstractType
{
    use CurrencyTrait;

    /**
     * @var boolean
     */
    private $required;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     * @param boolean      $required
     */
    public function __construct(RequestStack $requestStack, $required)
    {
        $this->requestStack = $requestStack;
        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('agreed', CheckboxType::class, ['required' => $this->required])
            ->add('renew', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $renew = $event->getData();
            $form = $event->getForm();

            $policy = $renew->getPolicy();
            $choices = [];
            if (!$renew->isCustom()) {
                if ($policy->getPremiumPlan() == Policy::PLAN_MONTHLY &&
                    $renew->getPolicy()->getUser()->allowedMonthlyPayments()) {
                    $choices = array_merge($choices, $this->getMonthlyPrice($policy, !$renew->isCustom()));
                } elseif ($policy->getPremiumPlan() == Policy::PLAN_YEARLY &&
                    $renew->getPolicy()->getUser()->allowedYearlyPayments()) {
                    $choices = array_merge($choices, $this->getYearlyPrice($policy, !$renew->isCustom()));
                }
            }

            // If for some reason, we didn't get any simple renewals above
            // e.g. not a simple renewal (or perhaps rules changed so a simple renewal is not possible)
            if (count($choices) == 0) {
                if ($renew->getPolicy()->getUser()->allowedMonthlyPayments()) {
                    $choices = array_merge($choices, $this->getMonthlyPrice($policy, true));
                    if ($policy->getPotValue() > 0) {
                        $choices = array_merge($choices, $this->getMonthlyPrice($policy, false));
                    }
                }
                if ($renew->getPolicy()->getUser()->allowedYearlyPayments()) {
                    $choices = array_merge($choices, $this->getYearlyPrice($policy, true));
                    if ($policy->getPotValue() > 0) {
                        $choices = array_merge($choices, $this->getYearlyPrice($policy, false));
                    }
                }
            }
            if (count($choices) > 0) {
                $form->add('encodedAmount', ChoiceType::class, [
                    'choices' => $choices,
                    'placeholder' => false,
                    'expanded' => 'true',
                    'required' => $this->required,
                    'disabled' => $renew->isAgreed() ? true : false,
                ]);
            }
        });
    }

    private function getMonthlyPrice($policy, $includeDiscount)
    {
        $choices = [];
        $price = $policy->getPhone()->getCurrentPhonePrice();
        $monthlyPrice = $price->getMonthlyPremiumPrice();
        $monthlyInitialAdjustedPrice = $price->getAdjustedInitialMonthlyPremiumPrice($policy->getPotValue());
        $monthlyStandardAdjustedPrice = $price->getAdjustedStandardMonthlyPremiumPrice($policy->getPotValue());

        if ($includeDiscount) {
            $copy = sprintf('£%.2f Monthly', $monthlyStandardAdjustedPrice);
            if ($policy->getPotValue() > 0) {
                if (!$this->areEqualToTwoDp($monthlyInitialAdjustedPrice, $monthlyStandardAdjustedPrice)) {
                    $copy = sprintf('%s (£%.2f 1st month)', $copy, $monthlyInitialAdjustedPrice);
                }
                $copy = sprintf('%s - [£%.2f without your pot]', $copy, $monthlyPrice);
            }
            $choices[$copy] = sprintf('%.2f|12|1', $monthlyStandardAdjustedPrice);
        } else {
            $copy = sprintf('£%.2f Monthly', $monthlyPrice);
            $choices[$copy] = sprintf('%.2f|12|0', $monthlyPrice);
        }

        return $choices;
    }

    private function getYearlyPrice($policy, $includeDiscount)
    {
        $choices = [];
        $price = $policy->getPhone()->getCurrentPhonePrice();
        $yearlyPrice = $price->getYearlyPremiumPrice();
        $yearlyAdjustedPrice = $price->getAdjustedYearlyPremiumPrice($policy->getPotValue());
        if ($includeDiscount) {
            $copy = sprintf('£%.2f Yearly', $yearlyAdjustedPrice);
            if ($policy->getPotValue() > 0) {
                $copy = sprintf('%s - [£%.2f without your pot]', $copy, $yearlyPrice);
            }
            $choices[$copy] = sprintf('%.2f|1|1', $yearlyAdjustedPrice);
        } else {
            $copy = sprintf('£%.2f Yearly', $yearlyPrice);
            $choices[$copy] = sprintf('%.2f|1|0', $yearlyPrice);
        }

        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Renew',
        ));
    }
}
