<?php

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\ResourceModel\TransactionData\CollectionFactory;
use Dpo\Dpo\Model\TransactionData;
use Dpo\Dpo\Model\ResourceModel\TransactionData as TransactionDataResource;
use Exception;

class TransactionDataRepository
{
    /**
     * @var CollectionFactory
     */
    public CollectionFactory $collectionFactory;
    /**
     * @var TransactionDataResource
     */
    protected $transactionDataResource;

    /**
     * @param CollectionFactory $collectionFactory
     * @param TransactionDataResource $transactionDataResource
     */
    public function __construct(CollectionFactory $collectionFactory, TransactionDataResource $transactionDataResource)
    {
        $this->collectionFactory       = $collectionFactory;
        $this->transactionDataResource = $transactionDataResource;
    }

    /**
     * Save TransactionData
     *
     * @param TransactionData $transactionData
     *
     * @throws Exception
     */
    public function save(TransactionData $transactionData): void
    {
        try {
            $this->transactionDataResource->save($transactionData);
        } catch (Exception $e) {
            throw new Exception(__('Could not save transaction data: %1', $e->getMessage()));
        }
    }
}
