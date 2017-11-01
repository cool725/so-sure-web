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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PurchaseStepPhoneType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    /**
     * @var RequestStack
     */
    private $requestStack;

    private $imeiService;

    /**
     * @param RequestStack $requestStack
     * @param boolean      $required
     * @param              $imeiService
     * @param              $logger
     */
    public function __construct(RequestStack $requestStack, $required, $imeiService, $logger)
    {
        $this->requestStack = $requestStack;
        $this->required = $required;
        $this->imeiService = $imeiService;
        $this->logger = $logger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('imei', TelType::class, ['required' => $this->required])
            ->add('agreed', CheckboxType::class, ['required' => $this->required])
            ->add('file', FileType::class)
            ->add('fileValid', CheckboxType::class)
            ->add('next', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();
 
            if ($purchase->getPhone()) {
                $price = $purchase->getPhone()->getCurrentPhonePrice();
                $choices = [];
                if ($purchase->getUser()->allowedMonthlyPayments()) {
                    $choices[sprintf('£%.2f Monthly', $price->getMonthlyPremiumPrice())] =
                            sprintf('%.2f', $price->getMonthlyPremiumPrice());
                }
                if ($purchase->getUser()->allowedYearlyPayments()) {
                    $choices[sprintf('£%.2f Yearly', $price->getYearlyPremiumPrice())] =
                            sprintf('%.2f', $price->getYearlyPremiumPrice());
                }
                if (count($choices) > 0) {
                    $form->add('amount', ChoiceType::class, [
                        'choices' => $choices,
                        'placeholder' => false,
                        'expanded' => 'true',
                        'required' => $this->required,
                        'disabled' => $purchase->allowedAmountChange() ? false : true,
                    ]);
                }

                if ($purchase->getPhone()->getMake() == "Apple") {
                    $form->add('serialNumber', TextType::class, ['required' => $this->required]);
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
            if ($purchase->getUser()->hasValidPaymentMethod()) {
                $form->add('existing', SubmitType::class);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();

            if ($filename = $purchase->getFile()) {
                $ocr = $this->imeiService->ocr($filename, $purchase->getPhone()->getMake());
                if ($ocr === null) {
                    $purchase->setFileValid(false);
                    $raw = $this->imeiService->ocrRaw($filename);
                    $this->logger->warning(sprintf(
                        'Failed to find imei for user %s ocr: %s',
                        $purchase->getUser()->getEmail(),
                        $raw
                    ));
                } else {
                    $purchase->setFileValid(true);
                    $purchase->setImei($ocr['imei']);
                    if (isset($ocr['serialNumber'])) {
                        $purchase->setSerialNumber($ocr['serialNumber']);
                    }
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPhone',
        ));
    }
}
