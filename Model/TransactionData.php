<?php

namespace Dpo\Dpo\Model;

use Magento\Framework\Model\AbstractModel;

class TransactionData extends AbstractModel
{
    /**
     * Model construct that should be used for object initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\TransactionData::class);
    }
}
