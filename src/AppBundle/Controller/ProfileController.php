<?php

namespace AppBundle\Controller;

use AppBundle\Controller\Utils\AccessControlTrait;
use AppBundle\Controller\Utils\DeliveryTrait;
use AppBundle\Controller\Utils\LocalBusinessTrait;
use AppBundle\Controller\Utils\OrderTrait;
use AppBundle\Controller\Utils\RestaurantTrait;
use AppBundle\Controller\Utils\StoreTrait;
use AppBundle\Controller\Utils\UserTrait;
use AppBundle\Entity\Address;
use AppBundle\Entity\ApiUser;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\Notification;
use AppBundle\Entity\Task;
use AppBundle\Entity\TaskList;
use AppBundle\Form\AddressType;
use AppBundle\Form\OrderType;
use AppBundle\Form\UpdateProfileType;
use AppBundle\Form\TaskCompleteType;
use Doctrine\ORM\Query\Expr;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route as Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sylius\Component\Order\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProfileController extends Controller
{
    const ITEMS_PER_PAGE = 20;

    use AccessControlTrait;
    use DeliveryTrait;
    use LocalBusinessTrait;
    use OrderTrait;
    use RestaurantTrait;
    use StoreTrait;
    use UserTrait;

    /**
     * @Route("/profile/edit", name="profile_edit")
     * @Template()
     *
     * @param Request $request
     * @return array
     */
    public function editProfile(Request $request) {

        $user = $this->getUser();

        $editForm = $this->createForm(UpdateProfileType::class, $user);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $userManager = $this->getDoctrine()->getManagerForClass(ApiUser::class);
            $userManager->persist($user);
            $userManager->flush();

            return $this->redirectToRoute('fos_user_profile_show');
        }

        return array(
            'form' => $editForm->createView()
        );
    }

    protected function getOrderList(Request $request)
    {
        $qb = $this->get('sylius.repository.order')
            ->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.state != :state')
            ->setParameter('customer', $this->getUser())
            ->setParameter('state', OrderInterface::STATE_CART);

        $count = (clone $qb)
            ->select('COUNT(o)')
            ->getQuery()
            ->getSingleScalarResult();

        $pages  = ceil($count / self::ITEMS_PER_PAGE);
        $page   = $request->query->get('p', 1);
        $offset = self::ITEMS_PER_PAGE * ($page - 1);

        $orders = (clone $qb)
            ->setMaxResults(self::ITEMS_PER_PAGE)
            ->setFirstResult($offset)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return [ $orders, $pages, $page ];
    }

    public function orderAction($id, Request $request)
    {
        $order = $this->container->get('sylius.repository.order')->find($id);

        if ($order->getCustomer() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($order->isFoodtech()) {
            return $this->render('@App/Order/foodtech.html.twig', [
                'layout' => '@App/profile.html.twig',
                'order' => $order,
                'order_normalized' => $this->get('serializer')->normalize($order, 'json', ['groups' => ['order']]),
                'breadcrumb_path' => 'profile_orders'
            ]);
        }

        $form = $this->createForm(OrderType::class, $order);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->getClickedButton()) {

                if ('cancel' === $form->getClickedButton()->getName()) {
                    $this->get('coopcycle.order_manager')->cancel($order);
                }

                $this->get('sylius.manager.order')->flush();

                return $this->redirectToRoute('profile_orders');
            }
        }

        return $this->render('@App/Order/service.html.twig', [
            'layout' => '@App/profile.html.twig',
            'order' => $order,
            'delivery' => $order->getDelivery(),
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/profile/addresses", name="profile_addresses")
     * @Template()
     */
    public function addressesAction(Request $request)
    {
        return array(
            'addresses' => $this->getUser()->getAddresses(),
        );
    }

    /**
     * @Route("/profile/addresses/new", name="profile_address_new")
     * @Template()
     */
    public function newAddressAction(Request $request)
    {
        $address = new Address();

        $form = $this->createForm(AddressType::class, $address);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $address = $form->getData();

            $this->getUser()->addAddress($address);

            $manager = $this->getDoctrine()->getManagerForClass(Address::class);
            $manager->persist($address);
            $manager->flush();

            return $this->redirectToRoute('profile_addresses');
        }

        return array(
            'form' => $form->createView(),
        );
    }

    protected function getRestaurantList(Request $request)
    {
        return [ $this->getUser()->getRestaurants(), 1, 1 ];
    }

    protected function getStoreList(Request $request)
    {
        return [ $this->getUser()->getStores(), 1, 1 ];
    }

    /**
     * @Route("/profile/stripe", name="profile_stripe")
     * @Template()
     */
    public function stripeAction(Request $request)
    {
        $settingsManager = $this->get('coopcycle.settings_manager');

        // @see https://stripe.com/docs/connect/standard-accounts#integrating-oauth
        // @see https://stripe.com/docs/connect/oauth-reference
        $queryString = http_build_query([
            'response_type' => 'code',
            'client_id' => $settingsManager->get('stripe_connect_client_id'),
            'scope' => 'read_write',
            'redirect_uri' => $this->get('router')->generate('stripe_connect_standard_account', [], UrlGeneratorInterface::ABSOLUTE_URL)
        ]);
        $stripeAuthorizeURL = 'https://connect.stripe.com/oauth/authorize?' . $queryString;

        return [
            'stripe_authorize_url' => $stripeAuthorizeURL,
            'stripe_accounts' => $this->getUser()->getStripeAccounts()
        ];
    }

    protected function getDeliveryRoutes()
    {
        return [
            'list'    => 'profile_tasks',
            'pick'    => 'profile_delivery_pick',
            'deliver' => 'profile_delivery_deliver'
        ];
    }

    /**
     * @Route("/profile/tracking/{date}", name="profile_tracking")
     * @Template("@App/User/tracking.html.twig")
     */
    public function trackingAction($date, Request $request)
    {
        $date = new \DateTime($date);

        return $this->userTracking($this->getUser(), $date);
    }

    /**
     * @Route("/profile/tasks", name="profile_tasks")
     * @Template
     */
    public function tasksAction(Request $request)
    {
        $date = new \DateTime();
        if ($request->query->has('date')) {
            $date = new \DateTime($request->query->get('date'));
        }

        $taskList = $this->getDoctrine()
            ->getRepository(TaskList::class)
            ->findOneBy([
                'courier' => $this->getUser(),
                'date' => $date
            ]);

        $tasks = [];
        if ($taskList) {
            $tasks = $taskList->getTasks();
        }

        return [
            'date' => $date,
            'tasks' => $tasks,
        ];
    }

    /**
     * @Route("/profile/tasks/{id}/complete", name="profile_task_complete")
     * @Template()
     */
    public function completeTaskAction($id, Request $request)
    {
        $taskManager = $this->get('coopcycle.task_manager');

        $task = $this->getDoctrine()
            ->getRepository(Task::class)
            ->find($id);

        $form = $this->createForm(TaskCompleteType::class, $task);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $task = $form->getData();
            $notes = $form->get('notes')->getData();

            if ($form->getClickedButton()) {

                try {
                    if ('done' === $form->getClickedButton()->getName()) {
                        $taskManager->markAsDone($task);
                    }
                    if ('fail' === $form->getClickedButton()->getName()) {
                        $taskManager->markAsFailed($task, $notes);
                    }
                } catch (\Exception $e) {
                    $this->addFlash(
                        'error',
                        $this->get('translator')->trans($e->getMessage())
                    );
                }
            }

            return $this->redirectToRoute('profile_tasks', ['date' => $task->getDoneBefore()->format('Y-m-d')]);
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/profile/notifications", name="profile_notifications")
     */
    public function notificationsAction(Request $request)
    {
        $notificationRepository = $this->getDoctrine()->getRepository(Notification::class);

        $notifications = $notificationRepository->findByUser($this->getUser());

        if ($request->query->has('format') && 'json' === $request->query->get('format')) {
            return new JsonResponse($this->get('serializer')->normalize($notifications, 'json'));
        }

        $notificationRepository->markAllAsRead($this->getUser());

        return $this->render('@App/Profile/notifications.html.twig', [
            'notifications' => $notifications
        ]);
    }

    /**
     * @Route("/profile/notifications/unread", name="profile_notifications_unread")
     */
    public function unreadNotificationsAction(Request $request)
    {
        $unread = $this->getDoctrine()
            ->getRepository(Notification::class)
            ->countUnreadByUser($this->getUser());

        return new JsonResponse((int) $unread);
    }

    /**
     * @Route("/profile/notifications/read", methods={"POST"}, name="profile_notifications_mark_as_read")
     */
    public function markNotificationsAsReadAction(Request $request)
    {
        $ids = [];
        $content = $request->getContent();
        if (!empty($content)) {
            $ids = json_decode($content, true);
        }

        $this->getDoctrine()
            ->getRepository(Notification::class)
            ->markAsRead($this->getUser(), $ids);

        $this->get('coopcycle.notification_manager')->publishCount($this->getUser());

        return new Response('', 204);
    }
}
