<?php
/**
 * Copyright © 2020 DPO Group. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace DirectPayOnline\Plug\Model\Config\Source\Order\Status;

use Magento\Sales\Model\Config\Source\Order\Status;
use Magento\Sales\Model\Order;

/**
 * Order Status source model
 */
class Pendingpayment extends Status
{
    /**
     * @var string[]
     */
    protected $_stateStatuses = [Order::STATE_PENDING_PAYMENT];
}
