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

        if (!$user->hasRole('ROLE_STORE') && !$user->hasRole('ROLE_RESTAURANT')) {

            return;
        }

        if (0 === count($user->getStores()) && 0 === count($user->getRestaurants())) {

            return;
        }

        // if ($route !== 'fos_user_profile_show') {

        //     return;
        // }


        if ($user->hasRole('ROLE_STORE')) {

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


        if ($user->hasRole('ROLE_RESTAURANT')) {

            $restaurants = $user->getRestaurants();

            if (!$request->getSession()->has('_restaurant')) {
                $restaurant = $restaurants->first();
                $request->getSession()->set('_restaurant', $restaurant->getId());
            }

            foreach ($restaurants as $restaurant) {
                if ($restaurant->getId() === $request->getSession()->get('_restaurant')) {
                    break;
                }
            }

            $request->attributes->set('_restaurant', $restaurant);
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => 'onKernelRequest',
        );
    }
}
