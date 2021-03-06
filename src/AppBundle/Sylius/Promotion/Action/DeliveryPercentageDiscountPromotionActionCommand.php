<?php

declare(strict_types=1);

namespace AppBundle\Sylius\Promotion\Action;

use AppBundle\Sylius\Order\AdjustmentInterface;
use AppBundle\Sylius\Order\OrderInterface;
use AppBundle\Sylius\Order\OrderItemInterface;
use Sylius\Component\Order\Model\AdjustmentInterface as OrderAdjustmentInterface;
use Sylius\Component\Promotion\Action\PromotionActionCommandInterface;
use Sylius\Component\Promotion\Model\PromotionInterface;
use Sylius\Component\Promotion\Model\PromotionSubjectInterface;
use Sylius\Component\Resource\Exception\UnexpectedTypeException;
use Sylius\Component\Resource\Factory\FactoryInterface;

/**
 * @see https://github.com/Sylius/Sylius/blob/master/src/Sylius/Component/Core/Promotion/Action/ShippingPercentageDiscountPromotionActionCommand.php
 */
final class DeliveryPercentageDiscountPromotionActionCommand implements PromotionActionCommandInterface
{
    public const TYPE = 'shipping_percentage_discount';

    /** @var FactoryInterface */
    private $adjustmentFactory;

    public function __construct(FactoryInterface $adjustmentFactory)
    {
        $this->adjustmentFactory = $adjustmentFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(PromotionSubjectInterface $subject, array $configuration, PromotionInterface $promotion): bool
    {
        if (!$subject instanceof OrderInterface) {
            throw new UnexpectedTypeException($subject, OrderInterface::class);
        }

        if (!isset($configuration['percentage'])) {
            return false;
        }

        $adjustment = $this->createAdjustment($promotion);

        $adjustmentAmount = (int) round($subject->getAdjustmentsTotal(AdjustmentInterface::DELIVERY_ADJUSTMENT) * $configuration['percentage']);
        if (0 === $adjustmentAmount) {
            return false;
        }

        $adjustment->setAmount(-$adjustmentAmount);
        $subject->addAdjustment($adjustment);

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedTypeException
     */
    public function revert(PromotionSubjectInterface $subject, array $configuration, PromotionInterface $promotion): void
    {
        if (!$subject instanceof OrderInterface && !$subject instanceof OrderItemInterface) {
            throw new UnexpectedTypeException(
                $subject,
                'Sylius\Component\Core\Model\OrderInterface or Sylius\Component\Core\Model\OrderItemInterface'
            );
        }

        foreach ($subject->getAdjustments(AdjustmentInterface::DELIVERY_PROMOTION_ADJUSTMENT) as $adjustment) {
            if ($promotion->getCode() === $adjustment->getOriginCode()) {
                $subject->removeAdjustment($adjustment);
            }
        }
    }

    private function createAdjustment(
        PromotionInterface $promotion,
        string $type = AdjustmentInterface::DELIVERY_PROMOTION_ADJUSTMENT
    ): OrderAdjustmentInterface {
        /** @var OrderAdjustmentInterface $adjustment */
        $adjustment = $this->adjustmentFactory->createNew();
        $adjustment->setType($type);
        $adjustment->setLabel($promotion->getName());
        $adjustment->setOriginCode($promotion->getCode());

        return $adjustment;
    }
}
