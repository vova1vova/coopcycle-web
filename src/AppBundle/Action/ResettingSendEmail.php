<?php

namespace AppBundle\Action;

use FOS\UserBundle\Mailer\MailerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use M6Web\Bundle\StatsdBundle\Statsd\StatsdEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ResettingSendEmail
{
    private $userManager;
    private $dispatcher;
    private $tokenGenerator;
    private $mailer;

    /**
     * @var int
     */
    private $retryTtl;

    public function __construct(
        UserManagerInterface $userManager,
        EventDispatcherInterface $dispatcher,
        TokenGeneratorInterface $tokenGenerator,
        MailerInterface $mailer,
        int $retryTtl)
    {
        $this->userManager = $userManager;
        $this->dispatcher = $dispatcher;
        $this->tokenGenerator = $tokenGenerator;
        $this->mailer = $mailer;
        $this->retryTtl = $retryTtl;
    }

    /**
     * @Route(
     *     path="/resetting/send-email",
     *     name="api_resetting_send_email",
     *     methods={"POST"}
     * )
     */
    public function sendEmailAction(Request $request)
    {
        $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.send_email.requested');

        $username = $request->request->get('username');

        // @see FOS\UserBundle\Controller\ResettingController
        $user = $this->userManager->findUserByUsernameOrEmail($username);

        if (!$user) {
            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.send_email.user_does_not_exist');

            // for security reasons don't disclose to the client that the user does not exist
            return new JsonResponse(null, Response::HTTP_ACCEPTED);
        }

        if (null !== $user && !$user->isPasswordRequestNonExpired($this->retryTtl)) {
            if (null === $user->getConfirmationToken()) {
                $user->setConfirmationToken($this->tokenGenerator->generateToken());
            }

            $this->mailer->sendResettingEmailMessage($user);
            $user->setPasswordRequestedAt(new \DateTime());
            $this->userManager->updateUser($user);

            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.send_email.done');
        } else {
            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.send_email.request_not_expired');
        }

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }
}
