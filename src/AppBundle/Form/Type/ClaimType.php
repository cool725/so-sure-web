<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

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
            ->add('number', TextType::class)
            ->add('status', ChoiceType::class, ['choices' => [
                Claim::STATUS_INREVIEW => Claim::STATUS_INREVIEW,
                Claim::STATUS_APPROVED => Claim::STATUS_APPROVED,
                Claim::STATUS_WITHDRAWN => Claim::STATUS_WITHDRAWN,
                Claim::STATUS_DECLINED => Claim::STATUS_DECLINED,
            ],
                'preferred_choices' => [Claim::STATUS_INREVIEW]
            ])
            ->add('suspected_fraud', CheckboxType::class, ['required' => false])
            ->add('shouldCancelPolicy', CheckboxType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
            ->add('record', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $claim = $event->getData();
            $form = $event->getForm();
            $choices = [];
            if ($claim->getPolicy()->isAdditionalClaimLostTheftApprovedAllowed()) {
                $choices = [
                    sprintf('%s - £%d excess', Claim::TYPE_LOSS, Claim::getExcessValue(Claim::TYPE_LOSS)) =>
                        Claim::TYPE_LOSS,
                    sprintf('%s - £%d excess', Claim::TYPE_THEFT, Claim::getExcessValue(Claim::TYPE_THEFT)) =>
                        Claim::TYPE_THEFT,
                ];
            }
            $choices = array_merge($choices, [
                sprintf('%s - £%d excess', Claim::TYPE_DAMAGE, Claim::getExcessValue(Claim::TYPE_DAMAGE)) =>
                    Claim::TYPE_DAMAGE,
                sprintf('%s - £%d excess', Claim::TYPE_WARRANTY, Claim::getExcessValue(Claim::TYPE_WARRANTY)) =>
                    Claim::TYPE_WARRANTY,
                sprintf('%s - £%d excess', Claim::TYPE_EXTENDED_WARRANTY, Claim::getExcessValue(Claim::TYPE_EXTENDED_WARRANTY)) =>
                    Claim::TYPE_EXTENDED_WARRANTY,
            ]);
            $form->add('type', ChoiceType::class, ['choices' => $choices]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Claim',
        ));
    }
}
