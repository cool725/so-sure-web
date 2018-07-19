<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\ClaimFnolUpdate;
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
        $builder
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $claim = $event->getData()->getClaim();

            if ($claim->needProofOfUsage()) {
                $form->add('proofOfUsage', FileType::class, ['required' => false]);
            }

            if ($claim->getType() == Claim::TYPE_DAMAGE && $claim->needPictureOfPhone()) {
                $form->add('pictureOfPhone', FileType::class, ['required' => false]);
            }

            if (($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS)) {
                $form->add('proofOfBarring', FileType::class, ['required' => false]);
            }

            if (($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS) &&
                $claim->needProofOfPurchase()
            ) {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            }

            if ($claim->getType() == Claim::TYPE_LOSS) {
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
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-usage-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfUsage($s3key);
            }
            if ($filename = $data->getPictureOfPhone()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('picture-of-phone-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setPictureOfPhone($s3key);
            }
            if ($filename = $data->getProofOfBarring()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-barring-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfBarring($s3key);
            }
            if ($filename = $data->getProofOfPurchase()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-purchase-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfPurchase($s3key);
            }
            if ($filename = $data->getProofOfLoss()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-loss-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfLoss($s3key);
            }
            if ($filename = $data->getOther()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('other-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setOther($s3key);
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
