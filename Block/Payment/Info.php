<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Block\Payment;

use Dpo\Dpo\Model\InfoFactory;
use Magento\Framework\View\Element\Template\Context;

/**
 * Dpo common payment info block
 * Uses default templates
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var InfoFactory
     */
    protected InfoFactory $infoFactory;

    /**
     * @param Context $context
     * @param InfoFactory $dpoInfoFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        InfoFactory $dpoInfoFactory,
        array $data = []
    ) {
        $this->infoFactory = $dpoInfoFactory;
        parent::__construct($context, $data);
    }
}
