<?php

/**
 * Sendsmaily Sync
 * Module to export Magento newsletter subscribers to Sendsmaily
 * Copyright (C) 2010 Sendsmaily
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Magento\Smaily\Model\Config\Source;

class Fields implements \Magento\Framework\Option\ArrayInterface
{
	// Get Additional fields list for Smaily
 	public function toOptionArray(){
		
		$list = [
			['value' => 'subscription_type', 'label' => 'Subscription Type'],
			['value' => 'customer_group', 'label' => 'Customer Group'],
			['value' => 'customer_id', 'label' => 'Customer ID'],
			['value' => 'prefix', 'label' => 'Prefix'],
			['value' => 'firstname', 'label' => 'Firstname'],
			['value' => 'lastname', 'label' => 'Lastname'],
			['value' => 'gender', 'label' => 'Gender'],
			['value' => 'birthday', 'label' => 'Date Of Birth'],
			['value' => 'website', 'label' => 'Website'],
			['value' => 'store', 'label' => 'Store']
		];
		
		return $list;
 	}
}