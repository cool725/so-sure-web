<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\ClaimFnolTheftLoss;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Service\ClaimsService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolTheftLossType extends AbstractType
{

    /**
     * @var ClaimsService
     */
    private $claimsService;

    /**
     * @param ClaimsService $claimsService
     */
    public function __construct(ClaimsService $claimsService)
    {
        $this->claimsService = $claimsService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('hasContacted', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Please choose..',
                'choices' => [
                    'Yes' => true,
                    'No' => false,
                    'N/A' => null,
                ],
            ])
            ->add('contactedPlace', TextType::class, ['required' => false])
            ->add('blockedDate', DateType::class, [
                  'required' => false,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportedDate', DateType::class, [
                  'required' => false,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('proofOfBarring', FileType::class, ['required' => false])
            ->add('isSave', HiddenType::class)
            ->add('save', ButtonType::class)
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $claim = $event->getData()->getClaim();

            if (!$claim->needProofOfUsage()) {
                $form->add('proofOfUsage', HiddenType::class);
            } else {
                $form->add('proofOfUsage', FileType::class, ['required' => false]);
            }
            if (!$claim->needProofOfPurchase()) {
                $form->add('proofOfPurchase', HiddenType::class);
            } else {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            }
            if ($claim->getType() == Claim::TYPE_THEFT) {
                $form->add('crimeReferenceNumber', TextType::class, ['required' => false]);
                $form->add('proofOfLoss', HiddenType::class);
                $form->add('reportType', HiddenType::class);
            } else {
                $form->add('crimeReferenceNumber', TextType::class, ['required' => false]);
                $form->add('proofOfLoss', FileType::class, ['required' => false]);
                $form->add('reportType', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => 'Please choose...',
                    'choices' => [
                        'Police station' => Claim::REPORT_POLICE_STATION,
                        'Online' => Claim::REPORT_ONLINE,
                    ],
                ]);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var ClaimFnolTheftLoss $data */
            $data = $event->getData();

            $now = new \DateTime();
            $timestamp = $now->format('U');

            if ($filename = $data->getProofOfUsage()) {
                $s3key = $this->claimsService->saveFile(
                    $filename,
                    sprintf('proof-of-usage-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfUsage($s3key);
            }
            if ($filename = $data->getProofOfBarring()) {
                $s3key = $this->claimsService->saveFile(
                    $filename,
                    sprintf('proof-of-barring-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfBarring($s3key);
            }
            if ($filename = $data->getProofOfPurchase()) {
                $s3key = $this->claimsService->saveFile(
                    $filename,
                    sprintf('proof-of-purchase-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfPurchase($s3key);
            }
            if ($filename = $data->getProofOfLoss()) {
                $s3key = $this->claimsService->saveFile(
                    $filename,
                    sprintf('proof-of-loss-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfLoss($s3key);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolTheftLoss',
        ));
    }
}
