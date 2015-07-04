<?php
/*
 * PlayerLog
 * Copyright (C) 2015 yf001
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
*/

namespace PlayerLog\provider;

class ProviderManager{
	
	private static $providers = [];
	
	public static function getProvider($name){
		if(isset(self::$providers[$name])){
			return self::$providers[$name];
		}else{
			return false;
		}
	}
	
	public static function addProvider($class){
		if($class instanceof Provider){
			self::$providers[$class->getName()] = $class;
			return true;
		}else{
			return false;
		}
	}
	
	public static function getProviderName($class){
		foreach(self::$providers as $name => $c){
			if($c === $class){
				return $name;
			}
		}
	}
	
	public static function getProviderList(){
		return array_keys(self::$providers);
	}
}