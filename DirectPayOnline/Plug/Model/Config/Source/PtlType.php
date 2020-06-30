<?php
/**
 * Copyright © 2020 DPO Group. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Used in creating options for Hours|Minutes config value selection
 */
namespace DirectPayOnline\Plug\Model\Config\Source;

class PtlType implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [['value' => 1, 'label' => __( 'Hours' )], ['value' => 2, 'label' => __( 'Minutes' )]];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [1 => __( 'Hours' ), 2 => __( 'Minutes' )];
    }
}
