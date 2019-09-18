<?php

namespace AppBundle\Action;

use ApiPlatform\Core\Bridge\Symfony\Validator\Exception\ValidationException;
use AppBundle\Form\ApiResetPasswordType;
use FOS\UserBundle\Model\UserManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationSuccessResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use M6Web\Bundle\StatsdBundle\Statsd\StatsdEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

class ResettingReset
{
    private $userManager;
    private $formFactory;
    private $jwtManager;
    private $dispatcher;
    private $logger;

    /**
     * @var int
     */
    private $tokenTtl;

    public function __construct(
        UserManagerInterface $userManager,
        FormFactoryInterface $formFactory,
        JWTTokenManagerInterface $jwtManager,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        int $tokenTtl)
    {
        $this->userManager = $userManager;
        $this->formFactory = $formFactory;
        $this->jwtManager = $jwtManager;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->tokenTtl = $tokenTtl;
    }

    /**
     * @Route(
     *     path="/resetting/reset/{token}",
     *     name="api_resetting_reset",
     *     methods={"POST"}
     * )
     */
    public function resetAction($token, Request $request)
    {
        $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.set_password.requested');

        $password = $request->request->get('password');

        // @see FOS\UserBundle\Controller\ResettingController
        $user = $this->userManager->findUserByConfirmationToken($token);

        if (null === $user) {
            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.set_password.token_not_found');

            throw new AccessDeniedException();
        }

        // @see FOSUserEvents::RESETTING_RESET_INITIALIZE
        if (!$user->isPasswordRequestNonExpired($this->tokenTtl)) {
            $data = [
                'message' => 'token expired',
            ];
            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.set_password.token_expired');
            return new JsonResponse($data, Response::HTTP_BAD_REQUEST);
        }

        $data = [
            'plainPassword' => [
                'password' => $password,
                'password_confirmation' => $password
            ]
        ];

        // validate password
        $form = $this->formFactory->create(ApiResetPasswordType::class, $user);
        $form->submit($data);

        if (!$form->isValid()) {
            $violations = new ConstraintViolationList();
            foreach ($form->getErrors(true) as $error) {
                $cause = $error->getCause();
                if ($cause instanceof ConstraintViolationInterface) {
                    $violations->add($cause);
                }
            }
            $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.set_password.password_not_valid');
            throw new ValidationException($violations);
        }

        $user->setPlainPassword($password);

        // @see FOSUserEvents::RESETTING_RESET_SUCCESS
        $user->setConfirmationToken(null);
        $user->setPasswordRequestedAt(null);
        $user->setEnabled(true);

        $this->userManager->updateUser($user);


        $jwt = $this->jwtManager->create($user);

        // @see Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler
        $response = new JWTAuthenticationSuccessResponse($jwt);
        $event = new AuthenticationSuccessEvent(['token' => $jwt], $user, $response);

        $this->dispatcher->dispatch($event, Events::AUTHENTICATION_SUCCESS);
        $response->setData($event->getData());

        $this->dispatcher->dispatch(new StatsdEvent(), 'user.resetting.set_password.done');

        return $response;
    }
}
