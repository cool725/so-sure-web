<?php

namespace AppBundle\Form\Type;

use AppBundle\Repository\ClaimRepository;
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

class ClaimCrimeRefType extends AbstractType
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
            ->add('force', ChoiceType::class, [
                    'choices' => $this->receperio->getForces(),
                    'required' => false,
                    'placeholder' => 'Select a Police Force',
            ])
            ->add('crime_ref', TextType::class, ['required' => false])
            ->add('run', SubmitType::class)
        ;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $claimsCheck = $event->getData();
            $form = $event->getForm();

            $form->add('claim', DocumentType::class, [
                    'placeholder' => 'Select a claim',
                    'class' => 'AppBundle:Claim',
                    'choice_label' => 'number',
                    'query_builder' => function (ClaimRepository $dr) use ($claimsCheck) {
                        return $dr->findByPolicy($claimsCheck->getPolicy());
                    }
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\CrimeRef',
        ));
    }
}
