<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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

class ClaimFnolUpdateType extends AbstractType
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
        $hoursChoices = [];
        for ($h = 0; $h < 24; $h++) {
            $formattedTime = sprintf("%02d:00", $h);
            $hoursChoices[$formattedTime] = $formattedTime;
        }
        $builder
            ->add('when', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('time', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Select',
                'choices' => $hoursChoices,
            ])
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
            if ($claim->getType() == Claim::TYPE_DAMAGE && $claim->needPictureOfPhone()) {
                $form->add('pictureOfPhone', FileType::class, ['required' => false]);
            } else {
                $form->add('pictureOfPhone', HiddenType::class);
            }
            if (($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS)) {
                $form->add('proofOfBarring', FileType::class, ['required' => false]);
            } else {
                $form->add('proofOfBarring', HiddenType::class);
            }
            if (($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS) &&
                $claim->needProofOfPurchase()
            ) {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            } else {
                $form->add('proofOfPurchase', HiddenType::class);
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
            if ($filename = $data->getPictureOfPhone()) {
                $s3key = $this->claimsService->saveFile(
                    $filename,
                    sprintf('picture-of-phone-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setPictureOfPhone($s3key);
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
            'data_class' => 'AppBundle\Document\Form\ClaimFnolUpdate',
        ));
    }
}
