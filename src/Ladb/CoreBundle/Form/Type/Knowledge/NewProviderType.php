<?php

namespace Ladb\CoreBundle\Form\Type\Knowledge;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\Common\Persistence\ObjectManager;
use Ladb\CoreBundle\Form\Type\Knowledge\Value\PictureValueType;
use Ladb\CoreBundle\Form\Type\Knowledge\Value\SignValueType;

class NewProviderType extends AbstractType {

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$builder
			->add('signValue', SignValueType::class, array(
				'choices'         => null,
				'dataConstraints' => null,
				'constraints'     => array(
					new \Symfony\Component\Validator\Constraints\Valid(),
					new \Ladb\CoreBundle\Validator\Constraints\UniqueProvider(),
				),
				'validation_groups' => array( 'mandatory' )
			))
			->add('logoValue', PictureValueType::class, array(
				'choices'         => null,
				'dataConstraints' => null,
				'constraints'     => array(
					new \Symfony\Component\Validator\Constraints\Valid(),
				),
				'validation_groups' => array( 'mandatory' )
			))
		;
	}

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults(array(
			'data_class' => 'Ladb\CoreBundle\Form\Model\NewProvider',
		));
	}

	public function getBlockPrefix() {
		return 'ladb_knowledge_newprovider';
	}

}
