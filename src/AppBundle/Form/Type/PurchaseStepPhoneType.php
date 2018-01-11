<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\BaseImeiService;
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
            ->add('file', FileType::class, ['attr' => ['accept' => 'image/*,.png,.jpg,.jpeg,.gif']])
            ->add('fileValid', CheckboxType::class)
            ->add('next', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();

            if ($purchase->getPolicy() && $purchase->getPolicy()->isRepurchase()) {
                $form->remove('imei');
                $form->add('imei', TelType::class, ['attr' => ['readonly' => true], 'required' => $this->required]);
            }
 
            if ($purchase->getPhone()) {
                $price = $purchase->getPhone()->getCurrentPhonePrice();
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
                $ocr = $this->imeiService->ocr(
                    $filename,
                    $purchase->getPhone()->getMake(),
                    $filename->guessExtension()
                );
                if ($ocr['success'] === false) {
                    $purchase->setFileValid(false);
                    $this->logger->warning(sprintf(
                        'Failed to find imei for user: %s; picture saved in %s/%s ; ocr: %s',
                        $purchase->getUser()->getEmail(),
                        BaseImeiService::S3_FAILED_OCR_FOLDER,
                        $purchase->getUser()->getId(),
                        $ocr['raw']
                    ));
                    $this->imeiService->saveFailedOcr($filename, $purchase->getUser()->getId());
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
