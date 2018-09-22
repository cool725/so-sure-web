<?php

namespace AppBundle\Form\Type;

use AppBundle\Classes\NoOp;
use AppBundle\Document\Form\ClaimFnolUpdate;
use AppBundle\Exception\ValidationException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
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
    use ClaimFnolFileTrait;

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
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var Claim $claim */
            $claim = $event->getData()->getClaim();

            if ($claim->needProofOfUsage()) {
                $form->add('proofOfUsage', FileType::class, ['required' => false]);
            }

            if ($claim->needPictureOfPhone()) {
                $form->add('pictureOfPhone', FileType::class, ['required' => false]);
            }

            if ($claim->needProofOfBarring()) {
                $form->add('proofOfBarring', FileType::class, ['required' => false]);
            }

            if ($claim->needProofOfPurchase()) {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            }

            if ($claim->needProofOfLoss()) {
                $form->add('proofOfLoss', FileType::class, ['required' => false]);
            }

            $form->add('other', FileType::class, ['required' => false]);

        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var ClaimFnolUpdate $data */
            $data = $event->getData();

            $now = new \DateTime();
            $timestamp = $now->format('U');

            if ($filename = $data->getProofOfUsage()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('proof-of-usage-%s-%06d', $timestamp, rand(1, 999999)),
                    'proofOfUsage'
                );
                if ($s3Key) {
                    $data->setProofOfUsage($s3Key);
                } else {
                    $data->setProofOfUsage(null);
                }
            }
            if ($filename = $data->getPictureOfPhone()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('picture-of-phone-%s-%06d', $timestamp, rand(1, 999999)),
                    'pictureOfPhone'
                );
                if ($s3Key) {
                    $data->setPictureOfPhone($s3Key);
                } else {
                    $data->setPictureOfPhone(null);
                }
            }
            if ($filename = $data->getProofOfBarring()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('proof-of-barring-%s-%06d', $timestamp, rand(1, 999999)),
                    'proofOfBarring'
                );
                if ($s3Key) {
                    $data->setProofOfBarring($s3Key);
                } else {
                    $data->setProofOfBarring(null);
                }
            }
            if ($filename = $data->getProofOfPurchase()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('proof-of-purchase-%s-%06d', $timestamp, rand(1, 999999)),
                    'proofOfPurchase'
                );
                if ($s3Key) {
                    $data->setProofOfPurchase($s3Key);
                } else {
                    $data->setProofOfPurchase(null);
                }
            }
            if ($filename = $data->getProofOfLoss()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('proof-of-loss-%s-%06d', $timestamp, rand(1, 999999)),
                    'proofOfLoss'
                );
                if ($s3Key) {
                    $data->setProofOfLoss($s3Key);
                } else {
                    $data->setProofOfLoss(null);
                }
            }
            if ($filename = $data->getOther()) {
                $s3Key = $this->handleFile(
                    $filename,
                    $this->claimsService,
                    $form,
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    sprintf('other-%s-%06d', $timestamp, rand(1, 999999)),
                    'other'
                );
                if ($s3Key) {
                    $data->setOther($s3Key);
                } else {
                    $data->setOther(null);
                }
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
