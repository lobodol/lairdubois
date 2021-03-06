<?php

namespace Ladb\CoreBundle\Repository\Core\Activity;

use Ladb\CoreBundle\Repository\AbstractEntityRepository;

class InviteRepository extends AbstractEntityRepository {

	/////

	public function findByInvitation(\Ladb\CoreBundle\Entity\Core\MemberInvitation $invitation) {
		$queryBuilder = $this->getEntityManager()->createQueryBuilder();
		$queryBuilder
			->select(array( 'a' ))
			->from($this->getEntityName(), 'a')
			->where('a.invitation = :invitation')
			->setParameter('invitation', $invitation)
		;

		try {
			return $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return null;
		}
	}

}