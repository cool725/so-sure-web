<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
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
     * @var boolean
     */
    private $required;

     /**
     * @var ClaimsService
     */
    private $claimsService;

    /**
     * @param boolean       $required
     * @param ClaimsService $claimsService
     */
    public function __construct($required, ClaimsService $claimsService)
    {
        $this->required = $required;
        $this->claimsService = $claimsService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('hasContacted', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Did you contact the last place you had it?',
                'choices' => [
                    'contacted' => true,
                    'did not contact' => false,
                ],
            ])
            ->add('contactedPlace', TextType::class, ['required' => true])
            ->add('blockedDate', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportedDate', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportType', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Where?',
                'choices' => [
                    'police station' => Claim::REPORT_POLICE_STATION,
                    'online' => Claim::REPORT_ONLINE,
                ],
            ])
            ->add('proofOfBarring', FileType::class)
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $claim = $event->getData()->getClaim();

            if (!$claim->needProofOfUsage()) {
                $form->add('proofOfUsage', HiddenType::class);
            } else {
                $form->add('proofOfUsage', FileType::class, ['required' => true]);
            }
            if (!$claim->needProofOfPurchase()) {
                $form->add('proofOfPurchase', HiddenType::class);
            } else {
                $form->add('proofOfPurchase', FileType::class, ['required' => true]);
            }
            if ($claim->getType() == Claim::TYPE_THEFT) {
                $form->add('crimeReferenceNumber', TextType::class, ['required' => true]);
                $form->add('policeLossReport', HiddenType::class);
            } else {
                $form->add('crimeReferenceNumber', HiddenType::class);
                $form->add('policeLossReport', TextType::class, ['required' => true]);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
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
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolTheftLoss',
        ));
    }
}
