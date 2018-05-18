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

class Autoresponders implements \Magento\Framework\Option\ArrayInterface
{
	// Get Option values for AutoResponder ID field
	public function toOptionArray(){
		
		$list = [];
		
		// load object manager object
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		
		// get autoresponder IDs list from Smaily
		$autoresponders = $objectManager->create('Magento\Smaily\Helper\Data')->getAutoresponders();
		
		foreach($autoresponders as $id => $name){
			$list[] = ['value' => $id, 'label' => $name];
		}
				
		return $list;
 	}
}