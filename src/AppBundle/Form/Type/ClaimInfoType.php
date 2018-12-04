<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class ClaimInfoType extends AbstractType
{
    /**
     * @var ReceperioService
     */
    private $receperio;

    protected $dm;

    /**
     * @param DocumentManager  $dm
     * @param ReceperioService $receperio
     */
    public function __construct(DocumentManager $dm, ReceperioService $receperio)
    {
        $this->dm = $dm;
        $this->receperio = $receperio;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('number', TextType::class, [
                'mapped' => false,
                'trim' => true
            ])
            ->add('replacementImei', NumberType::class, [
                'attr' => [
                    'pattern' => '[0-9]{15}',
                    'title' => '15 digit number'
                ],
                'required' => false
            ])
            ->add('replacementPhone', ChoiceType::class, [
                'choices' => $this->dm->getRepository(Phone::class)->findActiveInactive()->getQuery()->execute(),
                'choice_label' => function ($phone, $key, $value) {
                    return $phone->getName();
                },
                'placeholder' => 'Choose a phone',
                'required' => false
            ])
            ->add('approvedDate', TextType::class, [
                'required' => false
            ])
            ->add('notes', TextareaType::class, [
                'required' => false
            ])
            ->add('update', SubmitType::class);

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
                'mapped' => false,
                'choices' => $choices
            ]);
        });

        /*
         * $number doesnt map well, as the setter requires a variable passed
         * We manually populate the form and update the data
         */
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            /** @var Claim $claim */
            $claim = $event->getData();
            $form = $event->getForm();

            $form->get('number')->setData($claim->getNumber());
            $form->get('type')->setData($claim->getType());
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $claim = $event->getForm()->getData();
            $claim->setNumber($event->getForm()->get('number')->getData(), true);
            $claim->setType($event->getForm()->get('type')->getData(), true);
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
