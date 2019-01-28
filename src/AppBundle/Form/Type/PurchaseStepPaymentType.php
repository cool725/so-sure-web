<?php

namespace AppBundle\Form\Type;

use Psr\Log\LoggerInterface;
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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PurchaseStepPaymentType extends AbstractType
{
    use SixpackFormTrait;

    /**
     * @var boolean
     */
    private $required;

    /**
     * @var RequestStack
     */
    private $requestStack;

    protected $logger;

    /**
     * @param RequestStack    $requestStack
     * @param boolean         $required
     * @param LoggerInterface $logger
     */
    public function __construct(RequestStack $requestStack, $required, LoggerInterface $logger)
    {
        $this->requestStack = $requestStack;
        $this->required = $required;
        $this->logger = $logger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('next', SubmitType::class)
        ;

        $this->setFormAction($builder, $this->requestStack);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();

            if ($purchase->getPolicy()->getPhone()) {
                $price = $purchase->getPolicy()->getPhone()->getCurrentPhonePrice();
                $additionalPremium = $purchase->getUser()->getAdditionalPremium();
                $choices = [];
                if ($purchase->getUser()->allowedMonthlyPayments()) {
                    $choices[sprintf('£%.2f Monthly', $price->getMonthlyPremiumPrice($additionalPremium))] =
                            sprintf('%.2f', $price->getMonthlyPremiumPrice($additionalPremium));
                }
                if ($purchase->getUser()->allowedYearlyPayments()) {
                    $choices[sprintf('£%.2f Yearly', $price->getYearlyPremiumPrice($additionalPremium))] =
                            sprintf('%.2f', $price->getYearlyPremiumPrice($additionalPremium));
                }

                if (count($choices) > 0) {
                    $form->add('amount', ChoiceType::class, [
                        'choices' => $choices,
                        'placeholder' => false,
                        'expanded' => 'true',
                        'required' => $this->required,
                        'disabled' => $purchase->allowedAmountChange() ? false : true,
                        'attr' => [
                            'class' => '',
                        ],
                    ]);
                }
            } else {
                $form->add('amount', TextType::class, [
                    'attr' => [
                        'class' => 'form-control',
                        'readonly' => true,
                        'placeholder' => 'Select phone above'],
                        'disabled' => true
                ]);
            }
            if ($purchase->getPolicy()->hasPolicyOrUserValidPaymentMethod()) {
                $form->add('existing', SubmitType::class);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPayment',
        ));
    }
}
