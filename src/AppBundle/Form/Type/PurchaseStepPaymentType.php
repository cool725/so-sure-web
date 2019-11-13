<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\PhonePrice;
use AppBundle\Service\PostcodeService;
use AppBundle\Service\PriceService;
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

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PostcodeService
     */
    protected $postcodeService;

    /**
     * @var PriceService
     */
    protected $priceService;

    /**
     * Inserts the required dependencies.
     * @param RequestStack    $requestStack    is used to set the form action.
     * @param boolean         $required        sets whether this form must be filled in.
     * @param LoggerInterface $logger          is used to log output.
     * @param PostcodeService $postcodeService is used to check if users are in dangerous postcodes.
     * @param PriceService    $priceService    gets the prices that should be allowed.
     */
    public function __construct(
        RequestStack $requestStack,
        $required,
        LoggerInterface $logger,
        PostcodeService $postcodeService,
        PriceService $priceService
    ) {
        $this->requestStack = $requestStack;
        $this->required = $required;
        $this->logger = $logger;
        $this->postcodeService = $postcodeService;
        $this->priceService = $priceService;
    }

    /**
     * @InheritDoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('next', SubmitType::class);
        $this->setFormAction($builder, $this->requestStack);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();
            if ($purchase->getPolicy()->getPhone()) {
                // get the appropriate prices and add them to the form as options.
                $monthlyPrice = $this->priceService->userPhonePrice(
                    $purchase->getUser(),
                    $purchase->getPolicy()->getPhone(),
                    PhonePrice::STREAM_MONTHLY,
                    new \DateTime()
                );
                $yearlyPrice = $this->priceService->userPhonePrice(
                    $purchase->getUser(),
                    $purchase->getPolicy()->getPhone(),
                    PhonePrice::STREAM_YEARLY,
                    new \DateTime()
                );
                $additionalPremium = $purchase->getUser()->getAdditionalPremium();
                $monthlyPremiumPrice = $monthlyPrice ? $monthlyPrice->getMonthlyPremiumPrice($additionalPremium) : 0;
                $yearlyPremiumPrice = $yearlyPrice ? $yearlyPrice->getYearlyPremiumPrice($additionalPremium) : 0;
                $choices = [];
                if ($purchase->getUser()->allowedMonthlyPayments($this->postcodeService)) {
                    $choices[sprintf('£%.2f Monthly', $monthlyPremiumPrice)] = sprintf('%.2f', $monthlyPremiumPrice);
                }
                if ($purchase->getUser()->allowedYearlyPayments()) {
                    $choices[sprintf('£%.2f Yearly', $yearlyPremiumPrice)] = sprintf('%.2f', $yearlyPremiumPrice);
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
                } else {
                    throw new \Exception("No payment amounts are allowed for this user.");
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
        });
    }

    /**
     * @InheritDoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPayment',
        ));
    }
}
