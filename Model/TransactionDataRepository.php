<?php

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\ResourceModel\TransactionData\CollectionFactory;

class TransactionDataRepository
{
    /**
     * @var CollectionFactory
     */
    public CollectionFactory $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }
}
