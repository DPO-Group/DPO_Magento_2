<?php

/** @noinspection PhpUnused */

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\ResourceModel\TransactionData\CollectionFactory;

class TransactionDataRepository
{
    public CollectionFactory $collectionFactory;

    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }
}
