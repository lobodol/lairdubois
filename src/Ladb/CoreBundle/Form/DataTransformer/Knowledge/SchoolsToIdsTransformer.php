<?php

namespace Ladb\CoreBundle\Form\DataTransformer\Knowledge;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Doctrine\Common\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Knowledge\School;

class SchoolsToIdsTransformer implements DataTransformerInterface {

	private $om;

	public function __construct(ObjectManager $om) {
		$this->om = $om;
	}

	public function transform($schools) {
		if (null === $schools) {
			return '';
		}

		if (!$schools instanceof \Doctrine\Common\Collections\Collection) {
			throw new UnexpectedTypeException($schools, '\Doctrine\Common\Collections\Collection');
		}

		$idsArray = array();
		foreach ($schools as $school) {
			$idsArray[] = $school->getId();
		}
		return implode(',', $idsArray);
	}

	public function reverseTransform($idsString) {
		if (!$idsString) {
			return array();
		}

		$schools = array();
		$idsStrings = preg_split("/[,]+/", $idsString);
		$repository = $this->om->getRepository( School::CLASS_NAME);
		foreach ($idsStrings as $idString) {
			$id = intval($idString);
			if ($id == 0) {
				continue;
			}
			$school = $repository->find($id);
			if (is_null($school)) {
				throw new TransformationFailedException();
			}
			$schools[] = $school;
		}

		return $schools;
	}

}