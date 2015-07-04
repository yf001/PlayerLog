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

namespace PlayerLog;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\level\level;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\level\Position;
use pocketmine\event\Listener;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\permission\ServerOperator;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\Int;
use pocketmine\nbt\tag\String;

use PlayerLog\provider\ProviderManager;
use PlayerLog\provider\Sqlite;

class MainClass extends PluginBase implements Listener {
	
	private $config, $log, $providerManager;
	
	public function onEnable() {//起動時の処理
		$this->saveDefaultConfig();
		$this->reloadConfig();
		@mkdir($this->getDataFolder(), 0755, true);
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
		
		ProviderManager::addProvider(new Sqlite($this));
		//ProviderManager::addProvider(new Mysql($this));//todo...
		if(($this->provider = ProviderManager::getProvider($this->config->get("provider"))) === false){
			$this->provider = ProviderManager::getProvider("sqlite");
		}
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		$this->log = array();
		
		$this->getLogger()->info("プレーヤーの行動の記録を 保存形式:" . $this->provider->getName());
	}
/////////////////////////////////
// Event
/////////////////////////////////
	
	public function onJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		if($this->config->get("playerjoin") === true){
			$this->provider->addPlayerLog($player, "join");
		}
	}
	
	public function onQuit(PlayerQuitEvent $event){
		$player = $event->getPlayer();
		if($this->config->get("playerjoin") === true){
			$this->provider->addPlayerLog($player, "quit");
		}
		unset($this->log[$player->getName()]);
	}
	
	public function onBreak(BlockBreakEvent $event) {//ブロックの破壊
		if($this->config->get("blockbreak") === true){
			$player = $event->getPlayer();
			$block = $event->getBlock();
			if(isset($this->log[$player->getName()])){
				$event->setCancelled();
				return true;
			}
			$id = $block->getId();
			if($id === Block::DOOR_BLOCK or $id === Block::IRON_DOOR_BLOCK){
				if($block->getDamage() === 0x08 or $block->getDamage() === 0x09){//上部のドアブロック
					$this->provider->addBlockLog($player, $block->getSide(Vector3::SIDE_DOWN), $block->getSide(Vector3::SIDE_DOWN), 'Break');
				}else{//下部のドアブロック
					$this->provider->addBlockLog($player, $block->getSide(Vector3::SIDE_UP), $block->getSide(Vector3::SIDE_UP), 'Break');
				}
			}
			$this->provider->addBlockLog($player, $block, $block, 'Break');
		}
	}

	public function onPlace(BlockPlaceEvent $event) {//ブロックの設置
		$player = $event->getPlayer();
		$block = clone $event->getBlock();
		if(isset($this->log[$player->getName()])){
			$event->setCancelled();
			return true;
		}
		if($this->config->get("blockplace") === true and !$event->isCancelled()){
			$id = $block->getId();
			if(!($id === Block::SIGN_POST or $id === Block::WALL_SIGN)){
				if($id === Block::LADDER or $id === Block::FURNACE or $id === Block::BURNING_FURNACE or $id === Block::CHEST){
					$block->setDamage($player->getDirection());
				}elseif($id === Block::DOOR_BLOCK or $id === Block::IRON_DOOR_BLOCK){
					$d = $player->getDirection();
					$face = array(
						0 => 3,//south
						1 => 4,//west
						2 => 2,//north
						3 => 5,//east
					);
					$next = $block->getSide($face[(($d + 2) % 4)]);//方位を反転//蝶番(ちょうつがい)の方の隣
					$next2 = $block->getSide($face[$d]);//開く方の隣
					$meta = 0x08;
					//隣接ブロックがドアブロックだった場合 または 蝶番の方が透明でかつ開くほうが不透明の場合 は蝶番の方を逆に。
					if($next->getId() === $block->getId() or ($next2->isTransparent() === false and $next->isTransparent() === true)){
						$meta |= 0x01;
					}
					$this->provider->addBlockLog($player, $block->getSide(Vector3::SIDE_UP), Block::get($block->getId(), $meta), 'Place');
					$block->setDamage($d);
				}
				$this->provider->addBlockLog($player, $block, $block, 'Place');
			}
		}
	}
	
	public function onSignChange(SignChangeEvent $event){//看板の内容変更
		if($this->config->get("blockplace") === true){
			$block = $event->getBlock();
			$player = $event->getPlayer();
			$text = $event->getLines();
			if(($tile = $block->getLevel()->getTile($block)) instanceof Sign){
				$nbt = $tile->getSpawnCompound();
				$nbt->Text1 = $text[0];
				$nbt->Text2 = $text[1];
				$nbt->Text3 = $text[2];
				$nbt->Text4 = $text[3];
				$this->provider->addTileBlockLog($player, $block, 'Place', $nbt);
			}
		}
	}

	public function onInteract(PlayerInteractEvent $event) {//ブロックタッチ
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$player->sendMessage("id:" . $block->getId() . " meta:" . $block->getDamage());
		if(isset($this->log[$player->getName()])){
			$block = $event->getBlock();
			$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();
			if(!isset($this->log[$player->getName()][$xyz])){
				$this->log[$player->getName()][$xyz] = 1;
			}
			$log = $this->provider->getBlockLog($block, $this->log[$player->getName()][$xyz], $player);
			if($log !== false){
				$exp = explode(":", $log['blockid']);
				$blockid = (int) $exp[0];
				$type = ($log['type'] == 'Break')? '破壊':'設置';
				$blockName = ($this->config->get("blockNameJP") === true) ? $this->getItemName($blockid, $exp[1]):$block->getName();
				$player->sendMessage("[Log] [" . $log['id'] . "/" . $log['count'] . "][ワールド:" . $block->getLevel()->getName() . "][x:" . $block->getX() . "][y:" . $block->getY() . "][x:" . $block->getZ() . "]");
				$player->sendMessage("[Log] BlockID: " . $log['blockid'] . " (" . $blockName . ")");
				$player->sendMessage("[Log] ブロックを" . $type . "したプレーヤー: " . $log['user'] . " ip:" . $log['ip']);
				$player->sendMessage("[Log] ブロックが" . $type . "された時間:" . gmdate("Y年n月j日G時i分s秒", $log['time'] + $this->config->get("GMT") * 3600));
				if(($blockid === Block::SIGN_POST or $blockid === Block::WALL_SIGN) and $log['nbt'] !== null){
					$nbt = @unserialize($log['nbt']);
					if($nbt === false){
						$player->sendMessage("[Log] 看板の文章: NBTデータの復元に失敗しました");
					}else{
						$player->sendMessage("[Log] 看板の文章: " . $nbt->Text1 . ", " . $nbt->Text2 . ", " . $nbt->Text3 . ", " . $nbt->Text4);
					}
				}
				if($this->log[$player->getName()][$xyz] > $log['count']){
					$this->log[$player->getName()][$xyz] = 1;
				}else{
					++$this->log[$player->getName()][$xyz];
				}
			}else{
				$player->sendMessage("[Log] ログがありません");
				$this->log[$player->getName()][$xyz] = 1;
			}
			$event->setCancelled();
		}elseif(isset($this->rollback[$player->getName()])){
			$block = $event->getBlock();
			if($this->rollback[$player->getName()] == 1){
				$this->rbpos[$player->getName()][0] = $block;
				$player->sendMessage("[RollBack] 始点を設定しました");
			}elseif($this->rollback[$player->getName()] == 2){
				$this->rbpos[$player->getName()][1] = $block;
				$player->sendMessage("[RollBack] 終点を設定しました");
			}
			unset($this->rollback[$player->getName()]);
			$event->setCancelled();
		}else{
			//後回し
		}
	}
	
	//コマンド
	public function onPlayerCommand(PlayerCommandPreprocessEvent $event){
		if($this->config->get("playercmd") == "true"){
			$player = $event->getPlayer();
			$m = $event->getMessage();
			if($m[0] === "/"){
				if(strpos($m, '/register') !== false or strpos($m, '/login') !== false){
					$this->getLogger()->info($player->getName() . "さんがコマンド" . $this->getCommandName($m) . "を使用しました");
				}else{
					$this->getLogger()->info($player->getName() . "さんがコマンドを使用しました コマンド: " . $m);
				}
			}
		}
	}

/////////////////////////////////
// Commands
/////////////////////////////////
	
	//コマンド処理
	public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
		switch (strtolower($command->getName())) {
			case "log":
				if(isset($this->log[$sender->getName()])){
					unset($this->log[$sender->getName()]);
					$sender->sendMessage("[Log] 解除しました");
				}else{
					$this->log[$sender->getName()] = array();
					$sender->sendMessage("[Log] ブロックタッチ!");
				}
				return true;
			break;
			case "plog":
				if(!isset($args[0])){
					$sender->sendMessage("[PLog] プレーヤーを指定して下さい");
					return false;
				}
				if(isset($args[1]) and $args[0] === "ip"){
					$log = $this->provider->getPlayerLog($args[1], true);
					if($log === false){
						$sender->sendMessage("[PLog] 指定されたIPアドレスのログが見つかりませんでした");
						return true;
					}
				}else{
					$log = $this->provider->getPlayerLog($args[0]);
					if($log === false){
						$sender->sendMessage("[PLog] 指定されたプレーヤーのログが見つかりませんでした");
						return true;
					}
				}
				$keyword = $args[0];
				$ip = false;
				if(isset($args[1]) and $args[0] === "ip"){
					$keyword = $args[1];
					$ip = true;
				}
				$log = $this->provider->getPlayerLog($keyword, $ip);
				if($log === false){
					$sender->sendMessage("[PLog] 指定された" . (($ip) ? "IPアドレス":"プレーヤー") . "のログが見つかりませんでした");
					return true;
				}
				$page = (isset($args[1])) ? $args[1] - 1:0;
				$count = count($log);
				if($sender instanceof ConsoleCommandSender){
					$data = array($log);
				}else{
					$data = array_chunk($log, 4);
				}
				if(!isset($data[$page])){
					$page = 0;
				}
				if($sender instanceof ConsoleCommandSender){
					$message = "----- " . (($ip) ? $args[1]:$args[0]) . "の入室記録 -----\n";
				}else{
					$message = "----- " . (($ip) ? $args[1]:$args[0]) . "の入室記録 " . ($page + 1) . "/" . ($count / ceil(count($data[0]))) . " -----\n";
				}
				$gmt = $this->config->get("GMT");
				foreach($data[$page] as $value){
					$type = ($value["type"] == "join") ? "入室":"退出";
					$message .= $value['user'] . ":" . $type . ":" . gmdate("Y年n月j日G時i分s秒", $value['time'] + ($gmt * 3600)) . ":" . $value["ip"] . "\n";
				}
				$sender->sendMessage($message);
				return true;
			break;
			case "rollback":
				if(!isset($args[0])){return false;}
				switch ($args[0]) {
					case "pos1":
					case "p1":
						$this->rollback[$sender->getName()] = 1;
						$sender->sendMessage("[RollBack] ブロックに触れて始点を指定してください");
						break;
					case "pos2":
					case "p2":
						$this->rollback[$sender->getName()] = 2;
						$sender->sendMessage("[RollBack] ブロックに触れて終点を指定してください");
						break;
					case "rollback":
					case "rb":
						if(isset($this->rbpos[$sender->getName()][0]) and isset($this->rbpos[$sender->getName()][1])){
							if(isset($args[1])){
								if($args[1] > 0){
									$generation = $args[1];
								}else{
									$sender->sendMessage("[RollBack] 世代は0以上で指定して下さい");
								}
							}else{
								$generation = 1;
							}
							if($this->rollback($this->rbpos[$sender->getName()][0], $this->rbpos[$sender->getName()][1], $sender->getLevel(), $generation)){
								$sender->sendMessage("[RollBack] ロールバックが完了しました");
							}else{
								$sender->sendMessage("[RollBack] ロールバックに失敗しました");
							}
						}else{
							$sender->sendMessage("[RollBack] 始点と終点を指定してください");
						}
						break;
					/*case "PlayerRollBack":
					case "prollback":
					case "prb":
						if(isset($this->rbpos[$sender->getName()][1]) and isset($this->rbpos[$sender->getName()][2])){
							if(isset($args[1])){
								if(isset($args[2])){
									if($args[2] > 0){
										$generation = $args[2];
									}else{
										$sender->sendMessage("[RollBack] 世代は0以上で指定して下さい");
									}
								}
								if($this->playerRollback($player, $generation)){
									$sender->sendMessage("[RollBack] ロールバックが完了しました");
								}else{
									$sender->sendMessage("[RollBack] ロールバックに失敗しました");
								}
							}else{
								$sender->sendMessage("[RollBack] プレーヤーを指定して下さい");
							}
						}else{
							$sender->sendMessage("[RollBack] 始点と終点を指定してください");
						}
						break;*/
					default:
						$sender->sendMessage("[RollBack] -------コマンド一覧-------");
						$sender->sendMessage("[RollBack] /rollback pos1:始点の指定");
						$sender->sendMessage("[RollBack] /rollback pos2:終点の指定");
						$sender->sendMessage("[RollBack] /rollback rb [世代]:指定された範囲をロールバックします");
						//sender->sendMessage("[RollBack] /rollback prb <プレーヤー名> [世代]:指定された範囲をプレーヤの世代でロールバックします");
				}
				return true;
			break;
		}
	}

/////////////////////////////////
// Rollback
/////////////////////////////////
	
	public function rollback(Position $start, Position $end, Level $level, $generation = 1){
		$sx = min($start->x, $end->x);
		$sy = min($start->y, $end->y);
		$sz = min($start->z, $end->z);
		$ex = max($start->x, $end->x);
		$ey = max($start->y, $end->y);
		$ez = max($start->z, $end->z);
		for($x = $sx; $x <= $ex; ++$x){
			for($y = $sy; $y <= $ey; ++$y){
				for($z = $sz; $z <= $ez; ++$z){
					$p = new Position($x, $y, $z, $level);
					$count = $this->provider->getBlockLogCount($p);
					if($count === 0){
						continue;
					}
					$gen = $count - max(1, min($generation, $count));
					$log = $this->provider->getBlockLog($p, $gen);
					if($log === false){
						continue;
					}
					$id = explode(":", $log["blockid"]);
					$block = Block::get($id[0], $id[1]);
					$level->setBlock($p, $block);
					if($log['nbt'] !== "null"){//NBTを使用しての看板の復元処理
						$nbt = @unserialize($log['nbt']);
						if($nbt === false){
							continue;
						}
						$blockid = (int) $id[0];
						if($blockid === Block::SIGN_POST or $blockid === Block::WALL_SIGN){
							if(($tile = $level->getTile($p)) instanceof Sign){
								$tile->setText($nbt->Text1, $nbt->Text2, $nbt->Text3, $nbt->Text4);
							}else{
								$tile = Tile::createTile(Tile::SIGN, $level->getChunk($x >> 4, $z >> 4), new Compound("", [
									"id" => new String("id", Tile::SIGN),
									"x" => new Int("x", $x),
									"y" => new Int("y", $y),
									"z" => new Int("z", $z),
									"Text1" => new String("Text1", $nbt->Text1),
									"Text2" => new String("Text2", $nbt->Text2),
									"Text3" => new String("Text3", $nbt->Text3),
									"Text4" => new String("Text4", $nbt->Text4)
								]));
							}
							$level->sendBlocks($level->getChunkPlayers($x >> 4, $z >> 4), array($block), UpdateBlockPacket::FLAG_ALL_PRIORITY);
							$tile->spawnToAll();
						}
					}
				}
			}
		}
		return true;
	}
	
	//todo 特定のプレーヤーによる変更だけをロールバックできるようにする機能
	public function playerRollback(Player $player, Position $start, Position $end, Level $level, $g = 1) {
		$sx = min($pos[1][0], $pos[2][0]);
		$sy = min($pos[1][1], $pos[2][1]);
		$sz = min($pos[1][2], $pos[2][2]);
		$ex = max($pos[1][0], $pos[2][0]);
		$ey = max($pos[1][1], $pos[2][1]);
		$ez = max($pos[1][2], $pos[2][2]);
		for($x = $sx; $x <= $ex; ++$x){
			for($y = $sy; $y <= $ey; ++$y){
				for($z = $sz; $z <= $ez; ++$z){
					
				}
			}
		}
		return true;
	}
	
/////////////////////////////////
// Function
/////////////////////////////////
	
	public function getCommandName($cmd) {
		$exp = explode(" ", $cmd);
		$cmd = "/" . $exp[0];
		return $cmd;
	}
	
	public function getIdMeta($id) {
		$exp = explode(":", $id);
		$ids['id'] = $exp[0];
		$ids['meta'] = (isset($exp[1])) ? $exp[1]:0;
		return $ids;
	}
	
	public function getItemName($id, $meta){
		$idmeta = $id . ":" . $meta;
		if(isset(ItemName::$itemName[$id]) or isset(ItemName::$itemName[$idmeta])){
			return (isset(ItemName::$itemName[$idmeta])) ? ItemName::$itemName[$idmeta]:ItemName::$itemName[$id];
		}else{
			return $item->getName();
		}
	}
}