<?php

namespace AppBundle\EventSubscriber;

use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class StoreSubscriber implements EventSubscriberInterface
{
    private $tokenStorage;
    private $objectManager;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        ObjectManager $objectManager)
    {
        $this->tokenStorage = $tokenStorage;
        $this->objectManager = $objectManager;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if (!$request->hasPreviousSession()) {

            return;
        }

        $route = $request->attributes->get('_route');

        if (null === $token = $this->tokenStorage->getToken()) {

            return;
        }

        if (!is_object($user = $token->getUser())) {

            return; // e.g. anonymous authentication
        }

        if (!$user->hasRole('ROLE_STORE')) {

            return;
        }

        if (0 === count($user->getStores())) {

            return;
        }

        // if ($route !== 'fos_user_profile_show') {

        //     return;
        // }

        $stores = $user->getStores();

        if (!$request->getSession()->has('_store')) {
            $store = $stores->first();
            $request->getSession()->set('_store', $store->getId());
        }

        foreach ($stores as $store) {
            if ($store->getId() === $request->getSession()->get('_store')) {
                break;
            }
        }

        $request->attributes->set('_store', $store);
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => 'onKernelRequest',
        );
    }
}
