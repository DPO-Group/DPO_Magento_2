<?php

namespace Dpo\Dpo\Model;

use Magento\Framework\Model\AbstractModel;

class TransactionData extends AbstractModel
{

    protected function _construct()
    {
        $this->_init( ResourceModel\TransactionData::class );
    }

}
