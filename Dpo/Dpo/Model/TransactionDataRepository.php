<?php

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\ResourceModel\TransactionData\CollectionFactory;

class TransactionDataRepository
{
    private $collectionFactory;

    public function __construct( CollectionFactory $collectionFactory )
    {
        $this->collectionFactory = $collectionFactory;
    }
}
