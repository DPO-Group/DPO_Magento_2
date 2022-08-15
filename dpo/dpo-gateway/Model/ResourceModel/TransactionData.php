<?php
/** @noinspection PhpUnused */

/** @noinspection PhpUndefinedNamespaceInspection */

/** @noinspection PhpUndefinedMethodInspection */

namespace Dpo\Dpo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class TransactionData extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('dpo_transaction_data', 'id');
    }
}
