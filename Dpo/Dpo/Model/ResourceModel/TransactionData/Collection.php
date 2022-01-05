<?php

namespace Dpo\Dpo\Model\ResourceModel\TransactionData;

use Dpo\Dpo\Model\ResourceModel\TransactionData as TransactionDataResource;
use Dpo\Dpo\Model\TransactionData;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init( TransactionData::class, TransactionDataResource::class );
    }
}
