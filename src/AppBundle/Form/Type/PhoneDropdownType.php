<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\RequestService;
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

class PhoneDropdownType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    protected $dm;

    /** @var RequestService */
    protected $requestService;

    /**
     * @param DocumentManager $dm
     * @param boolean         $required
     * @param RequestService  $requestService
     */
    public function __construct(DocumentManager $dm, $required, RequestService $requestService)
    {
        $this->dm = $dm;
        $this->required = $required;
        $this->requestService = $requestService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $isAndroid = $this->requestService->isDeviceOsAndroid();
        $make = $builder->getData()->getMake();
        $models = [];
        $memory = [];
        if ($make) {
            $phones = $this->dm->getRepository(Phone::class)->findActiveModels($make);
            foreach ($phones as $phone) {
                $models[$phone->getId()] = $phone->getModelMemory();
            }
        }
        $makeChoiceOptions = [
            'placeholder' => 'Make',
            'choices' => $this->dm->getRepository(Phone::class)->findActiveMakes(),
            'required' => $this->required,
        ];
        if (!$isAndroid) {
            $makeChoiceOptions['preferred_choices'] = ['Apple', 'Samsung'];
            $makeChoiceOptions['group_by'] = function ($value) {
                if ($value === 'Apple' or $value === 'Samsung') {
                    return 'Top Makes:';
                } else {
                    return 'Other Makes:';
                }
            };
        }

        $builder
            ->add('make', ChoiceType::class, $makeChoiceOptions)
            ->add('model', ChoiceType::class, [
                'placeholder' => 'Model',
                'choices' => $models,
                'required' => $this->required
            ])
            ->add('memory', ChoiceType::class, [
                'placeholder' => 'Memory',
                'choices' => $memory,
                'required' => $this->required
            ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PhoneDropdown',
            'csrf_protection'   => false,
        ));
    }
}
