<?php

/** @noinspection PhpUndefinedNamespaceInspection */

/*
 * Copyright (c) 2023 DPO Group
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
    protected InfoFactory $_DpoInfoFactory;

    /**
     * @param Context $context
     * @param InfoFactory $DpoInfoFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        InfoFactory $DpoInfoFactory,
        array $data = []
    ) {
        $this->_DpoInfoFactory = $DpoInfoFactory;
        parent::__construct($context, $data);
    }
}
