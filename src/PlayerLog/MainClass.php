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
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use PlayerLog\provider\sqlite;

class MainClass extends PluginBase implements Listener {

	public function onEnable() {//起動時の処理
		$this->saveDefaultConfig();
		$this->reloadConfig();
		@mkdir($this->getDataFolder(), 0755, true);
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getLogger()->info("ブロックの記録を開始します");
		$this->sqlite = new sqlite($this);
		$this->log = array();
	}
/////////////////////////////////
// イベント
/////////////////////////////////
	
	public function onJoin(PlayerJoinEvent $event){
		if($this->config->get("playerjoin") === true){
			$this->sqlite->addPlayerLog($event->getPlayer(), "join");
		}
	}
	
	public function onQuit(PlayerQuitEvent $event){
		if($this->config->get("playerjoin") === true){
			$this->sqlite->addPlayerLog($event->getPlayer(), "quit");
		}
		unset($this->log[$event->getPlayer()->getName()]);
	}
	
	public function onBreak(BlockBreakEvent $event) {//ブロックの破壊
		if($this->config->get("blockbreak") === true){
			$block = $event->getBlock();
			if(isset($this->log[$event->getPlayer()->getName()])){
				$event->setCancelled();
			}
			$this->sqlite->addLog($event->getPlayer(), $block, 'Break');
		}
	}

	public function onPlace(BlockPlaceEvent $event) {//ブロックの設置
		if($this->config->get("blockplace") === true){
			$block = $event->getBlock();
			if(isset($this->log[$event->getPlayer()->getName()])){
				$event->setCancelled();
			}
			$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();
			$this->signid[$xyz] = $this->sqlite->addLog($event->getPlayer(), $block, 'Place');
		}
	}

	public function onInteract(PlayerInteractEvent $event) {//ブロックタッチ
		$player = $event->getPlayer();
		if(isset($this->log[$player->getName()])){
			$block = $event->getBlock();
			$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();
			if(!isset($this->log[$player->getName()][$xyz])){
				$this->log[$player->getName()][$xyz] = 1;
			}
			$log = $this->sqlite->getLog($block,$this->log[$player->getName()][$xyz], $player);
			if($log !== false){
				//[id] ページ数, [idc] 最大ページ数, [blockid] ブロックのid, [user] ブロックを変更したプレーヤーの名前, [ip] 変更したプレーヤーのip, [time] 変更された時間
				$type = ($log['type'] == 'Break')? '破壊':'設置';
				$player->sendMessage("[Log] [" . $log['id'] . "/" . $log['idc'] . "][world:" . $block->getLevel()->getName() . "][x:" . $block->getX() . "][y:" . $block->getY() . "][x:" . $block->getZ() . "]");
				$player->sendMessage("[Log] BlockID: " . $log['blockid'] . " (" . $block->getName() . ")");
				$player->sendMessage("[Log] ブロックを" . $type . "したプレーヤー: " . $log['user'] . " ip:" . $log['ip']);
				$player->sendMessage("[Log] ブロックが" . $type . "された時間:".$this->dateConversion($log['time']));
				if(isset($log['sign'])){
					if($log['sign'] !== false){
						$player->sendMessage("[Log] 看板の文字: " . $log['sign']["text1"] . " | " . $log['sign']["text2"] . " | " . $log['sign']["text3"] . " | " . $log['sign']["text4"]);
					}
				}
				if($this->log[$player->getName()][$xyz] > $log['idc']){
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
				$this->rbpos[$player->getName()][1][0] = $block->getX();
				$this->rbpos[$player->getName()][1][1] = $block->getY();
				$this->rbpos[$player->getName()][1][2] = $block->getZ();
				$player->sendMessage("[RollBack] 始点を設定しました");
			}elseif($this->rollback[$player->getName()] == 2){
				$this->rbpos[$player->getName()][2][0] = $block->getX();
				$this->rbpos[$player->getName()][2][1] = $block->getY();
				$this->rbpos[$player->getName()][2][2] = $block->getZ();
				$player->sendMessage("[RollBack] 終点を設定しました");
			}
			unset($this->rollback[$player->getName()]);
			$event->setCancelled();
		}else{
			//後回し
		}
	}

	public function onSign(SignChangeEvent $event) {//看板設置
		$data = $event->getLines();
		$block = $event->getBlock();
		$xyz = $block->getLevel()->getName() . "," . $block->getX() . "," . $block->getY() . "," . $block->getZ();
		if(isset($this->signid[$xyz]) and ($data[0] . $data[1] . $data[2] . $data[3]) !== ""){
			$this->sqlite->addLogSign($block, $data, $this->signid[$xyz]);
		}
		$event->getPlayer()->sendMessage($xyz);
	}
	
	//コマンド
	public function onPlayerCommand(PlayerCommandPreprocessEvent $event){
		if($this->config->get("playercmd") == "true"){
			$player = $event->getPlayer();
			$m = $event->getMessage();
			if($m[0] == "/"){
				if(strpos($m, '/register') !== false or strpos($m, '/login') !== false){
					$this->getLogger()->info($player->getName() . "さんがコマンド" . $this->getCommandName($m) . "を使用しました");
				}else{
					$this->getLogger()->info($player->getName() . "さんがコマンドを使用しました コマンド: " . $m);
				}
			}
		}
	}

/////////////////////////////////
// コマンド
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
				if(isset($args[0])){
					$log = $this->sqlite->getPlayerLog($args[0]);
					if($log === false){
						$sender->sendMessage("指定されたプレーヤーのログが見つかりませんでした");
						return true;
					}
					$page = (isset($args[1])) ? $args[1] - 1:0;
					$count = $log["idc"];
					unset($log["idc"]);
					if($sender instanceof ConsoleCommandSender){
						$data = array($log);
					}else{
						$data = array_chunk($log, 4);
					}
					if(!isset($data[$page])){
						$page = 0;
					}
					if($sender instanceof ConsoleCommandSender){
						$message = "----- " . $args[0] . "の入室記録 -----\n";
					}else{
						$message = "----- " . $args[0] . "の入室記録 " . ($page + 1) . "/" . ($count / ceil(count($data[0]))) . " -----\n";
					}
					foreach($data[$page] as $value){
						$type = ($value["type"] == "join") ? "入室":"退出";
						$message .= $type . ":" . $this->dateConversion($value["time"]) . ":" . $value["ip"] . "\n";
					}
					$sender->sendMessage($message);
				}else{
					$sender->sendMessage("[PlayerLog] プレーヤーを指定して下さい");
				}
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
						if(isset($this->rbpos[$sender->getName()][1]) and isset($this->rbpos[$sender->getName()][2])){
							if(isset($args[1])){
								if($args[1] > 0){
									$generation = $args[1];
								}else{
									$sender->sendMessage("[RollBack] 世代は0以上で指定して下さい");
								}
							}else{
								$generation = 1;
							}
							if($this->rollback($sender, $generation)){
								$sender->sendMessage("[RollBack] ロールバックが完了しました");
							}else{
								$sender->sendMessage("[RollBack] ロールバックに失敗しました");
							}
						}else{
							$sender->sendMessage("[RollBack] 始点と終点を指定してください");
						}
						break;
					default:
						$sender->sendMessage("[RollBack] -------コマンド一覧-------");
						$sender->sendMessage("[RollBack] /rollback pos1:始点の指定");
						$sender->sendMessage("[RollBack] /rollback pos2:終点の指定");
						$sender->sendMessage("[RollBack] /rollback rollback [世代]:指定された範囲をロールバックします");
				}
				return true;
			break;
		}
	}

/////////////////////////////////
// ロールバックの処理
/////////////////////////////////
	
	public function rollback($player, $backtime = 1) {
		if(isset($this->rbpos[$player->getName()][1]) and isset($this->rbpos[$player->getName()][2])){
			$pos = $this->rbpos[$player->getName()];
			$level = $player->getLevel();
			$sx = min($pos[1][0], $pos[2][0]);
			$sy = min($pos[1][1], $pos[2][1]);
			$sz = min($pos[1][2], $pos[2][2]);
			$ex = max($pos[1][0], $pos[2][0]);
			$ey = max($pos[1][1], $pos[2][1]);
			$ez = max($pos[1][2], $pos[2][2]);
			for($x = $sx; $x <= $ex; ++$x){
				for($y = $sy; $y <= $ey; ++$y){
					for($z = $sz; $z <= $ez; ++$z){
						//[id] ページ数, [idc] 最大ページ数, [blockid] ブロックのid, [user] ブロックを変更したプレーヤーの名前, [ip] 変更したプレーヤーのip, [time] 変更された時間
						$posp = new Position($x, $y, $z, $level);
						$count = $this->sqlite->getLogCount($posp);
						if(($count - 1) > 0){
							if($count - $backtime <= 0){//指定された世代のブロックがない場合、一世代戻すように
								$backtime = 1;
							}
							$log = $this->sqlite->getLog($posp, $count - $backtime);
							if(isset($log['blockid'])){
								$id = $this->getIdMeta($log['blockid']);
								$data = Block::get($id['id'], $id['meta']);
								$this->getLogger()->info("data:id." . $id['id'] . " mata." . $id['meta']);
								$level->setBlock($posp, $data);
							}
							
						}
					}
				}
			}
			return true;
		}else{
			return false;
		}
	}
/////////////////////////////////
// 関数
/////////////////////////////////
	
	public function dateConversion($date) {
		return date('Y年n月j日G時i分s秒', $date);
	}
	
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
}