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

use pocketmine\block\Block;
use pocketmine\plugin\Plugin;
use pocketmine\tile\Tile;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use PlayerLog\MainClass;

class Sqlite extends Provider{
	
	private $dbb, $dbs, $dbp;
	
/////////////////////////////////
// Construct
/////////////////////////////////
	
	public function __construct(Plugin $plugin){
		parent::__construct($plugin);
		$this->init();
	}
	
	public function init(){
		if(!file_exists($this->plugin->getDataFolder() . "Block.sqlite3")){
			$this->dbb = new \SQLite3($this->plugin->getDataFolder() . "Block.sqlite3", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}else{
			$this->dbb = new \SQLite3($this->plugin->getDataFolder() . "Block.sqlite3", SQLITE3_OPEN_READWRITE);
		}
		if(!file_exists($this->plugin->getDataFolder() . "Player.sqlite3")){
			$this->dbp = new \SQLite3($this->plugin->getDataFolder() . "Player.sqlite3", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}else{
			$this->dbp = new \SQLite3($this->plugin->getDataFolder() . "Player.sqlite3", SQLITE3_OPEN_READWRITE);
		}
	}
	
/////////////////////////////////
// Query
/////////////////////////////////
	
	public function query($db, $sql, $result = false){
		return ($result) ? $db->query($sql)->fetchArray(SQLITE3_ASSOC):$db->query($sql);
	}
	
	public function arrayQuery($db, $sql){
		$result = $db->query($sql);
		$data = array();
		while($raw = $result->fetchArray(SQLITE3_ASSOC)){
			$data[] = $raw;
		}
		return $data;
	}
	
/////////////////////////////////
// Function
/////////////////////////////////
	
	public function getName(){
		return "sqlite";
	}
	
	public function PositionToString($pos){
		return $pos->x . "," . $pos->y . "," . $pos->z . "," .$pos->getLevel()->getName();
	}
	
	public function addBlockLog($player, $pos, $block, $type){
		$xyz = $this->PositionToString($pos);
		$this->query($this->dbb, "CREATE TABLE IF NOT EXISTS '" . $xyz . "'('id' integer primary key AUTOINCREMENT, 'user' TEXT, 'ip' TEXT, 'blockid' TEXT, 'nbt' BLOB, 'time' TEXT, 'type' TEXT)");
		$username = $player->getName();
		$userip = $player->getAddress();
		$id = $block->getID() . ":" . $block->getDamage();
		$nbt = "null";
		$time = time();
		$query = $this->dbb->prepare("INSERT INTO '" . $xyz . "'(user, ip, blockid, nbt, time, type) VALUES(:user, :ip, :id, :nbt, :time, :type)");
		$query->bindValue(":user", $username, SQLITE3_TEXT);
		$query->bindValue(":ip", $userip, SQLITE3_TEXT);
		$query->bindValue(":id", $id, SQLITE3_TEXT);
		$query->bindValue(":nbt", $nbt, SQLITE3_BLOB);
		$query->bindValue(":time", $time, SQLITE3_TEXT);
		$query->bindValue(":type", $type, SQLITE3_TEXT);
		$query->execute();
		return true;
	}
	
	public function addTileBlockLog($player, $block, $type, $nbt){
		$xyz = $this->PositionToString($block);
		$this->query($this->dbb, "CREATE TABLE IF NOT EXISTS '" . $xyz . "'('id' integer primary key AUTOINCREMENT, 'user' TEXT, 'ip' TEXT, 'blockid' TEXT, 'nbt' BLOB, 'time' TEXT, 'type' TEXT)");
		$username = $player->getName();
		$userip = $player->getAddress();
		$id = $block->getID() . ":" . $block->getDamage();
		//$nbt = base64_encode(serialize($nbt));
		$nbt = serialize($nbt);
		$time = time();
		echo $nbt . "\n";
		$query = $this->dbb->prepare("INSERT INTO '" . $xyz . "'(user, ip, blockid, nbt, time, type) VALUES(:user, :ip, :id, :nbt, :time, :type)");
		$query->bindValue(":user", $username, SQLITE3_TEXT);
		$query->bindValue(":ip", $userip, SQLITE3_TEXT);
		$query->bindValue(":id", $id, SQLITE3_TEXT);
		$query->bindValue(":nbt", $nbt, SQLITE3_BLOB);
		$query->bindValue(":time", $time, SQLITE3_TEXT);
		$query->bindValue(":type", $type, SQLITE3_TEXT);
		$query->execute();
		return true;
	}
	
	public function addPlayerLog($player, $type){
		$username = $player->getName();
		$userip = $player->getAddress();
		$time = time();
		$this->query($this->dbp, "CREATE TABLE IF NOT EXISTS 'player'('id' integer primary key AUTOINCREMENT, 'user' TEXT, 'ip' TEXT, 'time' TEXT, 'type' TEXT)");
		$query = $this->dbp->prepare("INSERT INTO 'player'(user, ip, time, type) VALUES(:user, :ip, :time, :type)");
		$query->bindValue(":user", $username, SQLITE3_TEXT);
		$query->bindValue(":ip", $userip, SQLITE3_TEXT);
		$query->bindValue(":time", $time, SQLITE3_TEXT);
		$query->bindValue(":type", $type, SQLITE3_TEXT);
		$query->execute();
		return true;
	}
	
	public function getBlockLog($pos, $n){
		$xyz = $this->PositionToString($pos);
		$result = $this->query($this->dbb, "select count(*) from sqlite_master where type='table' and name='" . $xyz . "'", true);
		if($result["count(*)"] > 0){
			$result2 = $this->query($this->dbb, "select count(*) from '" . $xyz . "'", true);
			if($n > $result2["count(*)"]){
				$n = 1;
			}
			$data = $this->query($this->dbb, "select * from '" . $xyz . "' where id = '" . $n . "'", true);
			$data["count"] = $result2["count(*)"];
			if(isset($data["blockid"])){
				//$data["nbt"] = base64_decode($data["nbt"]);
				return $data;
			}
		}
		return false;
	}
	
	public function getBlocklogCount($pos){
		$xyz = $this->PositionToString($pos);
		$result = $this->query($this->dbb, "select count(*) from sqlite_master where type='table' and name='" . $xyz . "'", true);
		if($result["count(*)"] > 0){
			return $this->query($this->dbb, "select count(*) from '" . $xyz . "'", true)["count(*)"];
		}
		return false;
	}
	
	public function getPlayerLog($keyword, $ipSearch = false){
		$result = $this->query($this->dbp, "select count(*) from sqlite_master where type='table' and name='player'", true);
		if($result["count(*)"] > 0){
			if($ipSearch){
				$data = $this->arrayQuery($this->dbp, "select * from 'player' where ip = '" . $keyword . "'");
			}else{
				$data = $this->arrayQuery($this->dbp, "select * from 'player' where user = '" . $keyword . "'");
			}
			return count($data) > 0 ? $data:false;
		}
		return false;
	}
}