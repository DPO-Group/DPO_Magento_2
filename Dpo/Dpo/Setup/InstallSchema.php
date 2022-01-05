<?php

namespace Dpo\Dpo\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    public function install( SchemaSetupInterface $setup, ModuleContextInterface $context )
    {
        $setup->startSetup();

        $table = $setup->getConnection()->newTable(
            $setup->getTable( 'dpo_transaction_data' )
        )->addColumn(
            'id',
            Table::TYPE_INTEGER,
            null,
            ['identity' => true, 'nullable' => false, 'primary' => true],
            'Id column'
        )->addColumn(
            'recordtype',
            Table::TYPE_TEXT,
            20,
            ['nullable' => false],
            'Record type'
        )->addColumn(
            'recordid',
            Table::TYPE_TEXT,
            50,
            ['nullable' => false],
            'Record identifier'
        )->addColumn(
            'recordval',
            Table::TYPE_TEXT,
            50,
            ['nullable' => false],
            'Record value'
        );
        $setup->getConnection()->createTable( $table );
        $setup->endSetup();
    }
}
