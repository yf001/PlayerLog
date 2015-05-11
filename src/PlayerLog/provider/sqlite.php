<?php
/*
 * PlayerLog plugin for PocketMine-MP
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
use pocketmine\tile\Tile;
use pocketmine\math\Vector3;
use PlayerLog\MainClass;

class sqlite {
	
	public function __construct($plugin){
		$this->plugin = $plugin;
		if(!file_exists($this->plugin->getDataFolder() . "Block.sqlite3")){
			$this->dbb = new \SQLite3($this->plugin->getDataFolder() . "Block.sqlite3", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}else{
			$this->dbb = new \SQLite3($this->plugin->getDataFolder() . "Block.sqlite3", SQLITE3_OPEN_READWRITE);
		}
		if(!file_exists($this->plugin->getDataFolder() . "Sign.sqlite3")){
			$this->dbs = new \SQLite3($this->plugin->getDataFolder() . "Sign.sqlite3", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}else{
			$this->dbs = new \SQLite3($this->plugin->getDataFolder() . "Sign.sqlite3", SQLITE3_OPEN_READWRITE);
		}
		if(!file_exists($this->plugin->getDataFolder() . "Player.sqlite3")){
			$this->dbp = new \SQLite3($this->plugin->getDataFolder() . "Player.sqlite3", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}else{
			$this->dbp = new \SQLite3($this->plugin->getDataFolder() . "Player.sqlite3", SQLITE3_OPEN_READWRITE);
		}
	}
	
	public function addLogSign($block, $mozi, $id){
		$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();//座標
		$time = time() + 9*3600;
		$level = $block->getLevel()->getName();
		$sql = "CREATE TABLE IF NOT EXISTS '{$xyz}'('id' integer primary key AUTOINCREMENT, 'logid' TEXT, 'text1' TEXT, 'text2' TEXT, 'text3' TEXT, 'text4' TEXT)";
		$stmt = $this->dbs->prepare($sql);
		$stmt->execute();
		$stmt = $this->dbs->prepare("INSERT INTO '{$xyz}'(logid, text1, text2, text3, text4) VALUES(?,?,?,?,?)");
		$stmt->bindParam(1, $id);
		$stmt->bindParam(2, $mozi[0]);
		$stmt->bindParam(3, $mozi[1]);
		$stmt->bindParam(4, $mozi[2]);
		$stmt->bindParam(5, $mozi[3]);
		$stmt->execute();
		return true;
	}

	public function getSign($block, $logid){
		$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();//座標
		$result = $this->dbs->query("select count(*) from sqlite_master where type='table' and name='" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
		if($result["count(*)"] > 0){
			$result2 = $this->dbs->query("select count(*) from '" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
			$result3 = $this->dbs->query("select * from '" . $xyz . "' where logid='" . $logid . "'")->fetchArray(SQLITE3_ASSOC);
			return $result3;
		}else{
			return false;
		}
	}
	
	public function getLogCount($pos){
		$xyz = $pos->getLevel()->getName() . "," . $pos->getX() . "," . $pos->getY() . "," . $pos->getZ();//座標
		$result = $this->dbb->query("select count(*) from sqlite_master where type='table' and name='" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
		if($result["count(*)"] > 0){
			$result2 = $this->dbb->query("select count(*) from '" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
			return $result2["count(*)"];
		}else{
			return false;
		}
	}

	public function getLog($block, $page){
		$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();//座標
		$result = $this->dbb->query("select count(*) from sqlite_master where type='table' and name='" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
		if($result["count(*)"] > 0){
			$result2 = $this->dbb->query("select count(*) from '" . $xyz . "'")->fetchArray(SQLITE3_ASSOC);
			if($result2["count(*)"] < $page){
				$page = 1;
			}
			$result3 = $this->dbb->query("select * from '" . $xyz . "' where id = '" . $page . "'")->fetchArray(SQLITE3_ASSOC);
			$result3['idc'] = $result2["count(*)"];
			if(isset($result3['blockid'])){
				if($result3["blockid"] == Block::WALL_SIGN or $result3["blockid"] == Block::SIGN_POST){
					$result3["sign"] = $this->getSign($block, $page);
				}
			}
			return $result3;
		}else{
			return false;
		}
	}
	
	public function getPlayerLog($user){
		$result = $this->dbp->query("select count(*) from sqlite_master where type='table' and name='" . $user . "'")->fetchArray(SQLITE3_ASSOC);
		if($result["count(*)"] > 0){
			$result2 = $this->dbp->query("select count(*) from '" . $user . "'")->fetchArray();
			$result3 = $this->dbp->query("select * from '" . $user . "'");
			while($data = $result3->fetchArray()){
				$result4[] = $data;
			}
			$result4['idc'] = $result2["count(*)"];
			return $result4;
		}else{
			return false;
		}
	}
	
	public function addLog($player, $block, $type){
		$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();//座標
		$username = $player->getName();
		$userip = $player->getAddress();
		$id = $block->getID().":".$block->getDamage();
		$time = time() + 9*3600;
		$sql = "CREATE TABLE IF NOT EXISTS '" . $xyz . "'('id' integer primary key AUTOINCREMENT, 'user' TEXT, 'blockid' TEXT, 'ip' TEXT, 'time' TEXT, 'type' TEXT)";
		$stmt = $this->dbb->query($sql);
		$stmt = $this->dbb->prepare("INSERT INTO '" . $xyz . "'(user, blockid, ip, time, type) VALUES(?,?,?,?,?)");
		$stmt->bindParam(1, $username);
		$stmt->bindParam(2, $id);
		$stmt->bindParam(3, $userip);
		$stmt->bindParam(4, $time);
		$stmt->bindParam(5, $type);
		$stmt->execute();
		return $this->getLogCount($block);
	}
	
	public function addPlayerLog($player, $type){
		$username = $player->getName();
		$userip = $player->getAddress();
		$time = time() + 9*3600;
		$sql = "CREATE TABLE IF NOT EXISTS '" . $username . "'('id' integer primary key AUTOINCREMENT, 'user' TEXT, 'ip' TEXT, 'time' TEXT, 'type' TEXT)";
		$stmt = $this->dbp->query($sql);
		$stmt = $this->dbp->prepare("INSERT INTO '" . $username . "'(user, ip, time, type) VALUES(?,?,?,?)");
		$stmt->bindParam(1, $username);
		$stmt->bindParam(2, $userip);
		$stmt->bindParam(3, $time);
		$stmt->bindParam(4, $type);
		$stmt->execute();
		return true;
	}
	
	/*public function query($sql){
		$result = $this->dbb->query($sql);
		if($result instanceof \SQLite3Result){
			$result2 = $result->fetchArray(SQLITE3_ASSOC);
		}
		return $result2;
	}*/
}