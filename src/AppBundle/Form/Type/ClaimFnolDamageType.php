<?php

namespace AppBundle\Form\Type;

use AppBundle\Classes\NoOp;
use AppBundle\Document\File\UploadFile;
use AppBundle\Document\Form\ClaimFnolDamage;
use AppBundle\Exception\ValidationException;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Service\ClaimsService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolDamageType extends AbstractType
{
    use ClaimFnolFileTrait;

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
        $years = [];
        $currentYear = date('Y');
        for ($i = 2013; $i <= $currentYear; $i++) {
            $years[$i] = $i;
        }

        $builder
            ->add('typeDetails', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Choose type of damage...',
                'choices' => [
                    'Broken screen' => Claim::DAMAGE_BROKEN_SCREEN,
                    'Water damage' => Claim::DAMAGE_WATER,
                    'Out of warranty breakdown' => Claim::DAMAGE_OUT_OF_WARRANTY,
                    'Other' => Claim::DAMAGE_OTHER,
                ],
            ])
            ->add('typeDetailsOther', TextType::class, ['required' => false])
            ->add('monthOfPurchase', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Choose...',
                'choices' => [
                    'January' => 'January',
                    'February' => 'February',
                    'March' => 'March',
                    'April' => 'April',
                    'May' => 'May',
                    'June' => 'June',
                    'July' => 'July',
                    'August' => 'August',
                    'September' => 'September',
                    'October' => 'October',
                    'November' => 'November',
                    'December' => 'December',
                ],
            ])
            ->add('yearOfPurchase', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Choose...',
                'choices' => $years
            ])
            ->add('phoneStatus', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Choose the condition of your phone...',
                'choices' => [
                    'New' => Claim::PHONE_STATUS_NEW,
                    'Refurbished' => Claim::PHONE_STATUS_REFURBISHED,
                    'Second hand' => Claim::PHONE_STATUS_SECOND_HAND,
                ],
            ])
            ->add('save', SubmitType::class)
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var Claim $claim */
            $claim = $event->getData()->getClaim();

            if ($claim->needProofOfUsage()) {
                $form->add('proofOfUsage', FileType::class, ['required' => false]);
            }
            if ($claim->needProofOfPurchase()) {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            }
            if ($claim->needPictureOfPhone()) {
                $form->add('pictureOfPhone', FileType::class, ['required' => false]);
            }
        });

        $uploadMimeTypes = self::$uploadMimeTypes;
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var ClaimFnolDamage $data */
            $data = $event->getData();

            $now = \DateTime::createFromFormat('U', time());
            $timestamp = $now->format('U');

            /** @var UploadedFile $filename */
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
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolDamage',
        ));
    }
}
