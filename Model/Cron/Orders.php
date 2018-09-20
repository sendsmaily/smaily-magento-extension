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

namespace Magento\Smaily\Model\Cron;

class Orders
{
	
	// Get Abandoned cart items
	public function getList($limit=1000){
		
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
		$connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
		
		$list = [];
		
		$quotes = $connection->fetchAll('SELECT
			`main_table`.*,(main_table.base_subtotal_with_discount * main_table.base_to_global_rate) AS `subtotal`,
			`cust_email`.`email`
			FROM `quote` AS `main_table`
			INNER JOIN `customer_entity` AS `cust_email` ON cust_email.entity_id = main_table.customer_id				  
			WHERE (main_table.items_count != 0) AND (main_table.is_active = 1)');
			
		foreach($quotes as $quote){
			
			$itemData = $connection->fetchAll('SELECT product_id, name, description, sku, qty, price, base_price, weight From `quote_item` WHERE quote_id = '.$quote['entity_id']);
		
			if( !empty($itemData) )
				$list[] = [
					'quote_id' => $quote['entity_id'],
					'store_id' => $quote['store_id'],					
					'subtotal' => $quote['subtotal'],
					'grand_total' => $quote['grand_total'],
					'currency_code' => $quote['quote_currency_code'],
					'customer_firstname' => $quote['customer_firstname'],
					'customer_lastname' => $quote['customer_lastname'],
					'customer_id' => $quote['customer_id'],
					'customer_email' => $quote['customer_email'],
					'reminder_date' => $quote['reminder_date'],
					'products' => $itemData,
				];
		}
		return $list;
	}
}