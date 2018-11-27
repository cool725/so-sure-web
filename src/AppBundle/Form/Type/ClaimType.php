<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\VarDumper\Cloner\Data;

class ClaimType extends AbstractType
{
    /**
     * @var ReceperioService
     */
    private $receperio;

    /**
     * @param ReceperioService $receperio
     */
    public function __construct(ReceperioService $receperio)
    {
        $this->receperio = $receperio;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('replacementPhone', ChoiceType::class, [
                'choices' => $this->dm->getRepository(Phone::class)->findActiveInactive()->getQuery()->execute(),
                'choice_label' => function ($phone, $key, $value) {
                    return $phone->getName();
                },
                'placeholder' => 'Choose a phone'
            ])
            ->add('shouldCancelPolicy', CheckboxType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
            ->add('update', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var Claim $claim */
            $claim = $event->getData();
            $form = $event->getForm();
            /** @var PhonePolicy $policy */
            $policy = $claim->getPolicy();
            $picSureEnabled = $policy ? $policy->isPicSurePolicy() : true;
            $validated = $policy ? $policy->isPicSureValidated() : false;
            $choices = [];
            if ($policy && $policy->isAdditionalClaimLostTheftApprovedAllowed()) {
                $choices = [
                    $this->getClaimTypeCopy(Claim::TYPE_LOSS, $validated, $picSureEnabled) => Claim::TYPE_LOSS,
                    $this->getClaimTypeCopy(Claim::TYPE_THEFT, $validated, $picSureEnabled) => Claim::TYPE_THEFT,
                ];
            }
            $choices = array_merge($choices, [
                $this->getClaimTypeCopy(Claim::TYPE_DAMAGE, $validated, $picSureEnabled) => Claim::TYPE_DAMAGE,
                $this->getClaimTypeCopy(Claim::TYPE_WARRANTY, $validated, $picSureEnabled) => Claim::TYPE_WARRANTY,
                $this->getClaimTypeCopy(Claim::TYPE_EXTENDED_WARRANTY, $validated, $picSureEnabled) =>
                    Claim::TYPE_EXTENDED_WARRANTY,
            ]);
            $form->add('type', ChoiceType::class, [
                'placeholder' => 'Select Claim Type',
                'choices' => $choices,
                'disabled' => $claim->getType() == null ? false : true,
            ]);

            $form->add('number', TextType::class, [
                'data' => $claim->getNumber(),
                'mapped' => false,
                'trim' => true
            ]);

            $form->add('approvedDate', DateType::class, [
                'data' => $claim->getApprovedDate() ? $claim->getApprovedDate() : \DateTime::createFromFormat('U', time())
            ]);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            /** @var Claim $claim */
            $claim = $event->getData();
            $form = $event->getForm();

            $claim->setNumber($form->get('number')->getData(), true);
        });
    }

    private function getClaimTypeCopy($claimType, $picSureValidated, $picSureEnabled)
    {
        return sprintf(
            '%s - £%d excess',
            $claimType,
            Claim::getExcessValue($claimType, $picSureValidated, $picSureEnabled)
        );
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Claim',
        ));
    }
}
