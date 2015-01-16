<?php

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
	}

	public function setSign($player,$block,$mozi,$type){
		$xyz = $block->getLevel()->getName().",".$block->getX().",".$block->getY().",".$block->getZ();//座標
		$username = $player->getName();
		$userip = $player->getAddress();
		$time = gmdate("n\md\d H\hi\s", time() + 9*3600);
		$level = $block->getLevel()->getName();
		$sql = "CREATE TABLE IF NOT EXISTS '{$xyz}'('id' integer primary key AUTOINCREMENT,'user' TEXT, 'text1' TEXT, 'text2' TEXT, 'text3' TEXT, 'text4' TEXT, 'ip' TEXT,'time' TEXT, 'type' TEXT)";
		$stmt = $this->dbs->prepare($sql);
		$stmt->execute();
		$stmt = $this->dbs->prepare("INSERT INTO '{$block->getX()},{$block->getY()},{$block->getZ()}'(user,text1,text2,text3,text4,ip,time,type) VALUES(?,?,?,?,?,?,?,?)");
		$stmt->bindParam(1, $username);
		$stmt->bindParam(2, $mozi[0]);
		$stmt->bindParam(3, $mozi[1]);
		$stmt->bindParam(4, $mozi[2]);
		$stmt->bindParam(5, $mozi[3]);
		$stmt->bindParam(6, $userip);
		$stmt->bindParam(7, $time);
		$stmt->bindParam(8, $type);
		$stmt->execute();
	}

	public function getSign(){
		$stmt = $db->prepare("select count(*) from sqlite_master where type='table' and name=?");
		$stmt->bindParam(1, $name);
		$stmt->execute();
		//後回し
	}

	public function getLog($block,$page,$player){
		$xyz = $block->getLevel()->getName().",".$block->getX().",".$block->getY().",".$block->getZ();//座標
		$result = $this->dbb->query("select count(*) from sqlite_master where type='table' and name='{$xyz}'")->fetchArray(SQLITE3_ASSOC);
		if($result["count(*)"] == 1){
			$result2 = $this->dbb->query("select count(*) from '{$xyz}'")->fetchArray(SQLITE3_ASSOC);
			if($result2["count(*)"] < $page){
				$page = 1;
			}
			$result3 = $this->dbb->query("select * from '{$xyz}' where id = '{$page}'")->fetchArray(SQLITE3_ASSOC);
			//var_dump($result3);
			$result3['idc'] = $result2["count(*)"];
			/*if($result3["id"] == Block::WALL_SIGN or $result3["id"] == Block::SIGN_POST){
				$result3['sign'] = true;
			}*/
			return $result3;
		}else{
			return null;//ない場合はnullを返す
		}
	}

	public function addLog($player, $block,$type){
		if(!isset($this->plugin->log[$player->getName()])){
			$xyz = $block->getLevel()->getName().",".$block->getX().",".$block->getY().",".$block->getZ();//座標
			$username = $player->getName();
			$userip = $player->getAddress();
			$id = $block->getID().":".$block->getDamage();
			$time = gmdate("n\md\d H\hi\s", time() + 9*3600);
			$sql = "CREATE TABLE IF NOT EXISTS '$xyz'('id' integer primary key AUTOINCREMENT,'user' TEXT, 'blockid' TEXT,'ip' TEXT,'time' TEXT,'type' TEXT)";
			$stmt = $this->dbb->query($sql);
			$stmt = $this->dbb->prepare("INSERT INTO '$xyz'(user,blockid,ip,time,type) VALUES(?,?,?,?,?)");
			$stmt->bindParam(1, $username);
			$stmt->bindParam(2, $id);
			$stmt->bindParam(3, $userip);
			$stmt->bindParam(4, $time);
			$stmt->bindParam(5, $type);
			$stmt->execute();
			if($block->getID() == Block::WALL_SIGN or $block->getID() == Block::SIGN_POST){
				$pos = new Vector3($block->getX(), $block->getY(), $block->getZ());//座標をセット
				$sign = $player->getLevel()->getTile($pos);//看板のTileを取得
				/*if($sign instanceof Tile){//Tileオブジェクトかの判定
					$text = $sign->getText();//テキストを取得
					$this->setSign($player, $block, $text, $type);
				}*/
			}else{
				
			}
		}
	}

	public function query($sql){
		$result = $this->dbb->query($sql);
		if($result instanceof \SQLite3Result){
			$result2 = $result->fetchArray(SQLITE3_ASSOC);
		}
		return $result2;
	}
}