<?php

namespace Ladb\CoreBundle\Controller\Message;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Message\Message;
use Ladb\CoreBundle\Entity\Message\MessageMeta;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Form\Type\Message\ReplyMessageType;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;

/**
 * @Route("/messagerie")
 */
class MessageController extends AbstractThreadController {

	/**
	 * @Route("/thread/{threadId}/new", requirements={"threadId" = "\d+"}, name="core_message_new")
	 * @Template("LadbCoreBundle:Message:new-xhr.html.twig")
	 */
	public function newAction(Request $request, $threadId) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_message_new)');
		}
		if (!is_null($this->getUser()) && $this->getUser()->getIsTeam()) {
			throw $this->createNotFoundException('Team not allowed (core_message_new)');
		}

		// Retrieve related thread
		$thread = $this->retrieveThread($threadId);
		$this->assertShowable($thread);

		$message = new Message();
		$form = $this->createForm(ReplyMessageType::class, $message);

		return array(
			'thread' => $thread,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Route("/thread/{threadId}/create", requirements={"threadId" = "\d+"}, name="core_message_create")
	 * @Template("LadbCoreBundle:Message:new-xhr.html.twig")
	 */
	public function createAction(Request $request, $threadId) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_message_create)');
		}
		if (!is_null($this->getUser()) && $this->getUser()->getIsTeam()) {
			throw $this->createNotFoundException('Team not allowed (core_message_create)');
		}

		$this->createLock('core_message_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$thread = $this->retrieveThread($threadId);
		$this->assertShowable($thread);

		$message = new Message();
		$form = $this->createForm(ReplyMessageType::class, $message);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$om = $this->getDoctrine()->getManager();
			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);

			$sender = $this->getUser();
			$participants = $thread->getParticipants();
			$recipients = array();

			$thread->setLastMessageDate(new \DateTime());

			$message->setSender($sender);
			$fieldPreprocessorUtils->preprocessFields($message);
			$thread->addMessage($message);

			// Flag message as read for sender
			$messageMeta = new MessageMeta();
			$messageMeta->setParticipant($sender);
			$messageMeta->setIsRead(true);
			$message->addMeta($messageMeta);

			// Populate recipients list
			foreach ($participants as $participant) {
				if ($participant != $sender) {
					$recipients[] = $participant;
				}
			}

			// Reactivate deleted threads
			foreach ($thread->getMetas() as $threadMeta) {
				$threadMeta->setIsDeleted(false);
			}

			if (!$sender->getEmailConfirmed() && $sender->getMeta()->getIncomingMessageEmailNotificationEnabled()) {
				$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.alert.email_not_confirmed_error'));
			}

			// Notifications (after flush to have a thread id)
			$this->notifyRecipientsForIncomingMessage($recipients, $sender, $thread, $message);

			$om->flush();

			return $this->render('LadbCoreBundle:Message:create-xhr.html.twig', array( 'message' => $message ));
		}

		return array(
			'thread' => $thread,
			'form'   => $form->createView(),
		);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_message_edit")
	 * @Template("LadbCoreBundle:Message:edit-xhr.html.twig")
	 */
	public function editAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_message_edit)');
		}

		$om = $this->getDoctrine()->getManager();
		$messageRepository = $om->getRepository(Message::CLASS_NAME);

		$message = $messageRepository->findOneById($id);
		if (is_null($message)) {
			throw $this->createNotFoundException('Unable to find Message entity (id='.$id.').');
		}
		if ($message->getSender() != $this->getUser()) {
			throw $this->createNotFoundException('Not allowed (core_message_edit)');
		}

		$form = $this->createForm(ReplyMessageType::class, $message);

		return array(
			'message' => $message,
			'form'    => $form->createView(),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, name="core_message_update")
	 * @Template("LadbCoreBundle:Message:edit-xhr.html.twig")
	 */
	public function updateAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_message_update)');
		}

		$om = $this->getDoctrine()->getManager();
		$messageRepository = $om->getRepository(Message::CLASS_NAME);

		$message = $messageRepository->findOneById($id);
		if (is_null($message)) {
			throw $this->createNotFoundException('Unable to find Message entity (id='.$id.').');
		}
		if ($message->getSender() != $this->getUser()) {
			throw $this->createNotFoundException('Not allowed (core_message_edit)');
		}

		$form = $this->createForm(ReplyMessageType::class, $message);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$om = $this->getDoctrine()->getManager();

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($message);

			$om->flush();

			return $this->render('LadbCoreBundle:Message:update-xhr.html.twig', array('message' => $message));
		}

		return array(
			'message' => $message,
			'form'    => $form->createView(),
		);
	}

}
