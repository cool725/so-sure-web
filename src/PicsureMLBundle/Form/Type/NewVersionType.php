<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use PicsureMLBundle\Service\PicsureMLService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class NewVersionType extends AbstractType
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
            ->add('add', SubmitType::class)
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
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'PicsureMLBundle\Document\Form\NewVersion',
        ));
    }
}
