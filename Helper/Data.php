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

namespace Magento\Smaily\Helper;
use Magento\Store\Model\ScopeInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
	const XML_PATH = 'smaily/';
	
	/*
	* Check  Smaily Extension is enabled
	*
	* @reutun (bool)
	*/
	public function isEnabled(){
        return (bool)$this->getGeneralConfig('enable');
    }
	
	/*
	* Get Magento main configuration by field
	*
	* @reutun value
	*/
	public function getConfigValue($field, $storeId = null){
		return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
	}
	
	/*
	* Get Smaily configuration by field
	*
	* @reutun value
	*/
	public function getGeneralConfig($code, $storeId = null){
		return trim($this->getConfigValue(self::XML_PATH .'general/'. $code, $storeId));
	}	
	
	/*
	* Get Smaily Subdomain
	*
	* @reutun value
	*/
	public function getSubdomain(){
		$domain = $this->getGeneralConfig('subdomain');
		$domain = trim(strtolower(str_replace(['https://','http://','/','.sendsmaily.net'],'',$domain)));
		return $domain;
	}
	
	/*
	* Get Customer Group name by Group Id
	*
	* @reutun value
	*/
	public function getCustomerGroupName($group_id){
		$group_id = (int)$group_id;
		$list = [];
		
		if( empty($_SESSION['Smaily_customergroups']) ){			
			$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
			$customerGroups = $objectManager->get('\Magento\Customer\Model\ResourceModel\Group\Collection');
			
			foreach($customerGroups->toOptionArray() as $opt){
				$list[intval($opt['value'])] = trim($opt['label']); 	
			}
			$_SESSION['Smaily_customergroups'] = $list;
		
		} else 
			$list = (array)$_SESSION['Smaily_customergroups'];

		return isset($list[$group_id]) ? $list[$group_id] : 'Customer';
	}
	
	/*
	* Get AutoResponders list from Smaily API
	*
	* @reutun array
	*/
	public function getAutoresponders(){
		if( empty($_SESSION['Smaily_autoresponder']) ){
			
			$_list = $this->callApi('autoresponder',['page'=>1,'limit'=>100,'status'=>['ACTIVE']]);
			$list = [];
			foreach($_list as $r){
				if( !empty($r['id']) && !empty($r['name']) )
				$list[$r['id']] = trim($r['name']);
			}
			$_SESSION['Smaily_autoresponder'] = $list;
		
		} else 
			$list = (array)$_SESSION['Smaily_autoresponder'];

		return $list;
	}
	
	/*
	* Subscribe/Import Customer to Smaily by email
	*
	* @reutun Smaily api response
	*/
	public function subscribe($email,$data=[],$update = 0){
		$address = [
			'email'=>$email,
			'is_unsubscribed' => $update
		];
		
		if( !empty($data) ){
			$fields = explode(",",trim($this->getGeneralConfig('fields')));
		
			foreach($data as $field => $val){
				if( in_array($field,$fields) || $field == 'name' ){
					$address[$field] = trim($val); 	
				}
			}
		}
		
		$response = $this->callApi('contact',$address,'POST');
		
		return $response;
	}
	
	/*
	* Get Subscribe/Import Customer to Smaily by email wuth AutoResponder ID
	*
	* @reutun Smaily api response
	*/
	public function subscribeAutoresponder($aid,$email,$data=[]){
		
		$address = [
			'email'=>$email,
		];
		
		if( !empty($data) ){
			$fields = explode(",",trim($this->getGeneralConfig('fields')));
			foreach($data as $field => $val){
				if( in_array($field,$fields) || $field == 'name' ){
					$address[$field] = trim($val); 	
				}
			}
		}
		
		$post  = [
			'autoresponder' => $aid,
			'addresses' => [$address],
		];
	
		$response = $this->callApi('autoresponder',$post,'POST');
		
		return $response;
	}
	
	/*
	* Get Subsbribe/Import all Customers to Smaily by array list
	*
	* @reutun Smaily api response
	*/
	public function cronSubscribeAll($list){

		$data = [];
		$fields = explode(",",trim($this->getGeneralConfig('fields')));
		
		foreach($list as $row){
		
			$_data = [
				'email'=>$row['email'],
				'is_unsubscribed' => 0
			];
		
			foreach($row as $field => $val){
				if( in_array($field,$fields) ){
					$_data[$field] = trim($val); 	
				}
			}
		
			$data[] = $_data;
		}

		$response = $this->callApi('contact',$data,'POST');
		
		return $response;
	}
	
	/*
	* Call to Smaily API
	*
	* @reutun api response
	*/
	public function callApi($endpoint,$data,$method='GET'){
		
		// get smaily subdomain, username and password
		$subdomain = $this->getSubdomain();
		$username = trim($this->getGeneralConfig('username'));
		$password = trim($this->getGeneralConfig('password'));
		
		// create api url
		$apiUrl = "https://".$subdomain.".sendsmaily.net/api/".trim($endpoint,'/').".php";
		
		// create api post data
		$data = http_build_query($data);
		if( $method == 'GET' )
			$apiUrl = $apiUrl.'?'.$data;
			
		// curl call
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $apiUrl);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if( $method == 'POST' ){
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		}
		curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
		
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		
		// get result
		$result = json_decode(@curl_exec($ch),true);
		$error = false;
		
		// check error
		if( curl_errno($ch) )
			$result = ["code"=>0,"message"=>curl_error($ch)];

		curl_close($ch); 
		
		return $result;
	}	
}