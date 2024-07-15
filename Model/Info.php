<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

/**
 * Dpo payment information model
 *
 * Aware of all Dpo payment methods
 * Collects and provides access to Dpo-specific payment data
 * Provides business logic information about payment flow
 */
class Info
{
    /**
     * Apply a filter upon value getting
     *
     * @param string $value
     *
     * @return string
     */
    protected function getValue(string $value): string
    {
        $label       = '';
        $outputValue = implode(', ', (array)$value);

        return sprintf('#%s%s', $outputValue, $outputValue == $label ? '' : ': ' . $label);
    }
}
