<?php

namespace AppBundle\Form\Type;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;

class PhoneMakeType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param boolean         $required
     */
    public function __construct(DocumentManager $dm, $required)
    {
        $this->dm = $dm;
        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $make = $builder->getData()->getMake();
        $models = [];
        $phonePlaceholder = 'Select your phone make first';
        if ($make) {
            $phones = $this->dm->getRepository(Phone::class)->findActiveModels($make);
            foreach ($phones as $phone) {
                $models[$phone->getId()] = $phone->getModelMemory();
            }
            $phonePlaceholder = sprintf('Select your %s device', $make);
        }
        $models[''] = $phonePlaceholder;
        $memory[''] = $phonePlaceholder;
        $builder
            ->add('make', ChoiceType::class, [
                    'placeholder' => 'Select phone make to get a quote',
                    'choices' => $this->dm->getRepository(Phone::class)->findActiveMakes(),
                    'required' => $this->required,
                    'preferred_choices' => array('Apple', 'Samsung'),
                    'group_by' => function ($value) {
                        if ($value === 'Apple' or $value === 'Samsung') {
                            return 'Top Makes:';
                        } else {
                            return 'Other Makes:';
                        }
                    }
            ])
            ->add('model', ChoiceType::class, [
                'choices' => $models,
                'required' => $this->required
            ])
            ->add('memory', ChoiceType::class, [
                    'choices' => $memory,
                    'required' => $this->required
            ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PhoneMake',
            'csrf_protection'   => false,
        ));
    }
}
