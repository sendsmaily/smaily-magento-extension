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

class SyncTime implements \Magento\Framework\Option\ArrayInterface
{
	// Get time list for Smaily
 	public function toOptionArray(){
		
		$list = [
			['value' => '1:hour', 'label' => '1 Hour'],	
			['value' => '2:hour', 'label' => '2 Hour'],	
			['value' => '3:hour', 'label' => '3 Hour'],	
			['value' => '4:hour', 'label' => '4 Hour'],	
			['value' => '5:hour', 'label' => '5 Hour'],	
			['value' => '6:hour', 'label' => '6 Hour'],
			['value' => '1:day', 'label' => '1 Day'],
			['value' => '2:day', 'label' => '2 Days'],
			['value' => '3:day', 'label' => '3 Days']
			
		];
		
		return $list;
 	}
}