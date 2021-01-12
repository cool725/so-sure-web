<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\BaseImeiService;
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

    /**
     * @var BaseImeiService
     */
    private $imeiService;

    protected $logger;

    /**
     * @param RequestStack    $requestStack
     * @param boolean         $required
     * @param BaseImeiService $imeiService
     * @param LoggerInterface $logger
     */
    public function __construct(RequestStack $requestStack, $required, $imeiService, LoggerInterface $logger)
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
                if ($purchase->getPhone()->isApple()) {
                    // Un-comment to start taking serial number for iphones
                    //$form->add('serialNumber', TextType::class, ['required' => $this->required]);
                }
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
                    $url = $this->imeiService->saveFailedOcr(
                        $filename,
                        $purchase->getUser()->getId(),
                        $filename->guessExtension()
                    );
                    $msg = sprintf(
                        'Failed to find imei for user: %s; picture saved in %s ; ocr: %s',
                        $purchase->getUser()->getEmail(),
                        $url,
                        $ocr['raw']
                    );
                    if (mb_strlen(trim($ocr['raw'])) > 0) {
                        $this->logger->warning($msg);
                    } else {
                        $this->logger->info($msg);
                    }
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
