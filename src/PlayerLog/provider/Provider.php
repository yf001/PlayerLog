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

use pocketmine\plugin\Plugin;

abstract class Provider{
	
	protected $plugin;
	
	public function __construct(Plugin $plugin){
		$this->plugin = $plugin;
	}
	
	public abstract function init();
	
	public abstract function getName();
	
	public abstract function addBlockLog($player, $pos, $block, $type);
	
	public abstract function addPlayerLog($player, $type);
	
	public abstract function getBlockLog($pos, $n);
	
	public abstract function getBlocklogCount($pos);
	
	public abstract function getPlayerLog($keyword, $ipSearch = false);
}