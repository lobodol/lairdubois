<?php

namespace Ladb\CoreBundle\Controller\Knowledge;

use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Knowledge\Value\Picture;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Form\Type\Knowledge\NewWoodType;
use Ladb\CoreBundle\Form\Model\NewWood;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Knowledge\Value\Text;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\KnowledgeUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\TextureUtils;
use Ladb\CoreBundle\Utils\ElasticaQueryUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Manager\Knowledge\WoodManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\KnowledgeEvent;
use Ladb\CoreBundle\Event\KnowledgeListener;

/**
 * @Route("/xylotheque")
 */
class WoodController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_wood_new")
	 * @Template("LadbCoreBundle:Knowledge/Wood:new.html.twig")
	 */
	public function newAction() {

		// Exclude if user is not email confirmed
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed - User email not confirmed (core_wood_new)');
		}

		$knowledgeUtils = $this->get(KnowledgeUtils::NAME);

		$newWood = new NewWood();
		$form = $this->createForm(NewWoodType::class, $newWood);

		return array(
			'form'           => $form->createView(),
			'sourcesHistory' => $knowledgeUtils->getValueSourcesHistory(),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_wood_create")
	 * @Template("LadbCoreBundle:Knowledge/Wood:new.html.twig")
	 */
	public function createAction(Request $request) {

		// Exclude if user is not email confirmed
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('Not allowed - User email not confirmed (core_wood_create)');
		}

		$this->createLock('core_wood_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();
		$dispatcher = $this->get('event_dispatcher');

		$newWood = new NewWood();
		$form = $this->createForm(NewWoodType::class, $newWood);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$nameValue = $newWood->getNameValue();
			$grainValue = $newWood->getGrainValue();
			$user = $this->getUser();

			// Sanitize Name values
			if ($nameValue instanceof Text) {
				$nameValue->setData(trim(ucfirst($nameValue->getData())));
			}

			$wood = new Wood();
			$wood->setName($nameValue->getData());
			$wood->incrementContributorCount();

			$om->persist($wood);
			$om->flush();	// Need to save wood to be sure ID is generated

			$wood->addNameValue($nameValue);
			$wood->addGrainValue($grainValue);

			// Dispatch knowledge events
			$dispatcher->dispatch(KnowledgeListener::FIELD_VALUE_ADDED, new KnowledgeEvent($wood, array( 'field' => Wood::FIELD_NAME, 'value' => $nameValue )));
			$dispatcher->dispatch(KnowledgeListener::FIELD_VALUE_ADDED, new KnowledgeEvent($wood, array( 'field' => Wood::FIELD_GRAIN, 'value' => $grainValue )));

			$nameValue->setParentEntity($wood);
			$nameValue->setParentEntityField(Wood::FIELD_NAME);
			$nameValue->setUser($user);

			$grainValue->setParentEntity($wood);
			$grainValue->setParentEntityField(Wood::FIELD_GRAIN);
			$grainValue->setUser($user);

			$user->getMeta()->incrementProposalCount(2);	// Name and Grain of this new wood

			// Create activity
			$activityUtils = $this->get(ActivityUtils::NAME);
			$activityUtils->createContributeActivity($nameValue, false);
			$activityUtils->createContributeActivity($grainValue, false);

			// Dispatch publication event
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($wood));

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(PublicationListener::PUBLICATION_PUBLISHED, new PublicationEvent($wood));

			return $this->redirect($this->generateUrl('core_wood_show', array('id' => $wood->getSluggedId())));
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		return array(
			'newWood'     => $newWood,
			'form'        => $form->createView(),
			'hideWarning' => true,
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_wood_delete")
	 * @Security("is_granted('ROLE_ADMIN')", statusCode=404, message="Not allowed (core_wood_delete)")
	 */
	public function deleteAction($id) {

		$wood = $this->retrievePublication($id, Wood::CLASS_NAME);
		$this->assertDeletable($wood);

		// Delete
		$woodMananger = $this->get(WoodManager::NAME);
		$woodMananger->delete($wood);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('knowledge.wood.form.alert.delete_success', array( '%title%' => $wood->getTitle() )));

		return $this->redirect($this->generateUrl('core_wood_list'));
	}

	/**
	 * @Route("/{id}/textures", requirements={"id" = "\d+"}, name="core_wood_texture_list")
	 * @Route("/{id}/textures/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_wood_texture_list_filter")
	 * @Route("/{id}/textures/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_wood_texture_list_filter_page")
	 * @Template("LadbCoreBundle:Knowledge/Wood:texture-list.html.twig")
	 */
	public function textureListAction(Request $request, $id, $page = 0, $filter = 'all') {
		$om = $this->getDoctrine()->getManager();
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$wood = $this->retrievePublication($id, Wood::CLASS_NAME);
		$this->assertShowable($wood);

		$textureRepository = $om->getRepository(Wood\Texture::CLASS_NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $textureRepository->findPaginedByWood($wood, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_wood_texture_list_filter_page', array( 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'wood'        => $wood,
			'textures'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Knowledge/Wood:texture-list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/textures/{id}/download", requirements={"id" = "\d+"}, name="core_wood_texture_download")
	 */
	public function textureDownloadAction($id) {
		$om = $this->getDoctrine()->getManager();
		$textureRepository = $om->getRepository(Wood\Texture::CLASS_NAME);

		$texture = $textureRepository->findOneById($id);
		if (is_null($texture)) {
			throw $this->createNotFoundException('Unable to find Texture entity (id='.$id.').');
		}

		$textureUtils = $this->get(TextureUtils::NAME);
		$zipAbsolutePath = $textureUtils->getZipAbsolutePath($texture);
		if (!file_exists($zipAbsolutePath)) {
			if (!$textureUtils->createZipArchive($texture)) {
				throw $this->createNotFoundException('Zip archive not found (core_wood_texture_download)');
			}
		}

		$texture->incrementDownloadCount(1);

		$om->flush();

		$content = file_get_contents($zipAbsolutePath);

		$response = new Response();
		$response->headers->set('Content-Type', 'mime/type');
		$response->headers->set('Content-Length', filesize($zipAbsolutePath));
		$response->headers->set('Content-Disposition', 'attachment;filename="lairdubois_texture_'.$textureUtils->getBaseFilename($texture).'.zip"');
		$response->headers->set('Expires', 0);
		$response->headers->set('Cache-Control', 'no-cache, must-revalidate');
		$response->headers->set('Pragma', 'no-cache');

		$response->setContent($content);

		return $response;
	}

	/**
	 * @Route("/textures/{id}", requirements={"id" = "\d+"}, name="core_wood_texture_show")
	 * @Template("LadbCoreBundle:Knowledge/Wood:texture-show-xhr.html.twig")
	 */
	public function textureShowAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$textureRepository = $om->getRepository(Wood\Texture::CLASS_NAME);

		$texture = $textureRepository->findOneById($id);
		if (is_null($texture)) {
			throw $this->createNotFoundException('Unable to find Texture entity (id='.$id.').');
		}

		return array(
			'texture' => $texture,
		);
	}

	/**
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_wood_widget")
	 * @Template("LadbCoreBundle:Knowledge/Wood:widget-xhr.html.twig")
	 */
	public function widgetAction(Request $request, $id) {

		$wood = $this->retrievePublication($id, Wood::CLASS_NAME);
		$this->assertShowable($wood, true);

		return array(
			'wood' => $wood,
		);
	}

	/**
	 * @Route("/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_wood_list_filter")
	 * @Route("/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_wood_list_filter_page")
	 */
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Route("/", name="core_wood_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_wood_list_page")
	 * @Template("LadbCoreBundle:Knowledge/Wood:list.html.twig")
	 */
	public function listAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_wood_list_page)');
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils) {
				switch ($facet->name) {

					// Filters /////

					case 'name':

						$elasticaQueryUtils = $this->get(ElasticaQueryUtils::NAME);
						$filters[] = $elasticaQueryUtils->createShouldMatchPhraseQuery('name', $facet->value);

						break;

					case 'origin':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'origin' ));
						$filters[] = $filter;

						break;

					case 'utilization':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'utilization' ));
						$filters[] = $filter;

						break;

					case 'rejected':

						$filter = new \Elastica\Query\BoolQuery();
						$filter->addShould(new \Elastica\Query\Range('nameRejected', array( 'gte' => 1 )));
						$filter->addShould(new \Elastica\Query\Range('grainRejected', array( 'gte' => 1 )));
						$filters[] = $filter;

						$noGlobalFilters = true;

						break;

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'changedAt' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-views':
						$sort = array( 'viewCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-likes':
						$sort = array( 'likeCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-comments':
						$sort = array( 'commentCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-density':
						$sort = array( 'density' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-alphabetical':
						$sort = array( 'titleWorkaround' => array( 'order' => $searchUtils->getSorterOrder($facet, 'asc') ) );
						break;

					case 'sort-completion':
						$sort = array( 'completion100' => array( 'order' =>  $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'name^100', 'scientificname', 'englishname' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				if ($this->get('security.authorization_checker')->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getUnlistedKnowledgeWoodCount() > 0) {
					$sort = array('changedAt' => array('order' => 'desc'));
				} else {
					$sort = array('completion100' => array('order' => 'desc'));
				}

			},
			function(&$filters) {

				$filters[] = new \Elastica\Query\Range('nameRejected', array( 'lt' => 1 ));
				$filters[] = new \Elastica\Query\Range('grainRejected', array( 'lt' => 1 ));

			},
			'fos_elastica.index.ladb.knowledge_wood',
			\Ladb\CoreBundle\Entity\Knowledge\Wood::CLASS_NAME,
			'core_wood_list_page'
		);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()));

		$parameters = array_merge($searchParameters, array(
			'woods' => $searchParameters['entities'],
		));

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Knowledge/Wood:list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/{id}.html", name="core_wood_show")
	 * @Template("LadbCoreBundle:Knowledge/Wood:show.html.twig")
	 */
	public function showAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$woodRepository = $om->getRepository(Wood::CLASS_NAME);
		$witnessManager = $this->get(WitnessManager::NAME);

		$id = intval($id);

		$wood = $woodRepository->findOneById($id);
		if (is_null($wood)) {
			if ($response = $witnessManager->checkResponse(Wood::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Wood entity.');
		}

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($wood));

		$searchUtils = $this->get(SearchUtils::NAME);
		$elasticaQueryUtils = $this->get(ElasticaQueryUtils::NAME);
		$searchableCreationCount = $searchUtils->searchEntitiesCount(array( $elasticaQueryUtils->createShouldMatchPhraseQuery('woods.label', $wood->getName()) ), 'fos_elastica.index.ladb.wonder_creation');
		$searchableProviderCount = $searchUtils->searchEntitiesCount(array( $elasticaQueryUtils->createShouldMatchPhraseQuery('woodsWorkaround', $wood->getName()) ), 'fos_elastica.index.ladb.knowledge_provider');

		$likableUtils = $this->get(LikableUtils::NAME);
		$watchableUtils = $this->get(WatchableUtils::NAME);
		$commentableUtils = $this->get(CommentableUtils::NAME);
		$collectionnableUtils = $this->get(CollectionnableUtils::NAME);

		return array(
			'wood'                    => $wood,
			'permissionContext'       => $this->getPermissionContext($wood),
			'searchableCreationCount' => $searchableCreationCount,
			'searchableProviderCount' => $searchableProviderCount,
			'likeContext'             => $likableUtils->getLikeContext($wood, $this->getUser()),
			'watchContext'            => $watchableUtils->getWatchContext($wood, $this->getUser()),
			'commentContext'          => $commentableUtils->getCommentContext($wood),
			'collectionContext'       => $collectionnableUtils->getCollectionContext($wood),
		);
	}

}
