<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use PicsureMLBundle\Document\TrainingData;
use PicsureMLBundle\Service\PicsureMLService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class SearchType extends AbstractType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var PicsureMLService
     */
    private $picsureMLService;

    /**
     * @param RequestStack $requestStack
     * @param PicsureMLService $picsureMLService
     */
    public function __construct(RequestStack $requestStack, PicsureMLService $picsureMLService)
    {
        $this->requestStack = $requestStack;
        $this->picsureMLService = $picsureMLService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('label', ChoiceType::class, [
                'required' => true,
                'multiple' => false,
                'expanded' => true,
                'choices' => [
                    'All' => null,
                    'None' => 'none',
                    'Undamaged' => TrainingData::LABEL_UNDAMAGED,
                    'Invalid' => TrainingData::LABEL_INVALID,
                    'Damaged' => TrainingData::LABEL_DAMAGED,
                ]
            ])
            ->add('images_per_page', IntegerType::class, [
                'required' => true,
                'attr' => array('min' => 1, 'placeholder' => 32)
            ])
            ->add('search', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            
            $choices = array('None' => null);
            $existingVersions = $this->picsureMLService->getTrainingVersions();
            foreach ($existingVersions as $version) {
                $choices[$version] = $version;
            }

            $form->add('version', ChoiceType::class, [
                'required' => true,
                'choices' => $choices
            ]);
        });

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            if ($currentRequest->query->get('version')) {
                $form->get('version')->setData($currentRequest->query->get('version'));
            } else {
                $form->get('version')->setData(null);
            }
            if ($currentRequest->query->get('label')) {
                $form->get('label')->setData($currentRequest->query->get('label'));
            } else {
                $form->get('label')->setData(null);
            }
            if ($currentRequest->query->get('images_per_page')) {
                $form->get('images_per_page')->setData($currentRequest->query->get('images_per_page'));
            } else {
                $form->get('images_per_page')->setData(32);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'PicsureMLBundle\Document\Form\Search',
            'csrf_protection'   => false,
        ));
    }
}
