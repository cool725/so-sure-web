<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use PicsureMLBundle\Document\TrainingData;
use PicsureMLBundle\Service\PicsureMLService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class EditType extends AbstractType
{

    /**
     * @var PicsureMLService
     */
    private $picsureMLService;

    /**
     * @param PicsureMLService $picsureMLService
     */
    public function __construct(PicsureMLService $picsureMLService)
    {
        $this->picsureMLService = $picsureMLService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('label', ChoiceType::class, [
                'choices'  => array(
                    'None' => null,
                    'Undamaged' => TrainingData::LABEL_UNDAMAGED,
                    'Invalid' => TrainingData::LABEL_INVALID,
                    'Damaged' => TrainingData::LABEL_DAMAGED,
                ),
                'placeholder' => false,
                'expanded' => true,
                'multiple' => false,
                'required' => false
            ])
            ->add('x', TextType::class, ['required' => false])
            ->add('y', TextType::class, ['required' => false])
            ->add('width', TextType::class, ['required' => false])
            ->add('height', TextType::class, ['required' => false])
            ->add('forDetection', CheckboxType::class, ['required' => false])
            ->add('clear', ButtonType::class)
            ->add('previous', SubmitType::class)
            ->add('save', SubmitType::class)
            ->add('next', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            
            $choices = array();
            $existingVersions = $this->picsureMLService->getTrainingVersions();
            foreach ($existingVersions as $version) {
                $choices[$version] = $version;
            }

            $form->add('versions', ChoiceType::class, [
                'required' => true,
                'expanded' => true,
                'multiple' => true,
                'choices' => $choices
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'PicsureMLBundle\Document\TrainingData',
        ));
    }
}
