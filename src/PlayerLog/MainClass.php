<?php

namespace PlayerLog;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\level\level;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
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

	public function onBreak(BlockBreakEvent $event) {//ブロックの破壊
		$block = $event->getBlock();
		$this->sqlite->addLog($event->getPlayer(), $block,'Break');
	}

	public function onPlace(BlockPlaceEvent $event) {//ブロックの設置
		$block = $event->getBlock();
		if(!isset($this->log[$event->getPlayer()->getName()])){
			$this->sqlite->addLog($event->getPlayer(), $block,'Place');
		}
	}

	public function onInteract(PlayerInteractEvent $event) {//ブロックタッチ
		$player = $event->getPlayer();
		if(isset($this->log[$event->getPlayer()->getName()])){
			$block = $event->getBlock();
			$log = $this->sqlite->getLog($block,$this->log[$player->getName()],$player);
			if($log !== null){
				var_dump($log);
				$data = $log;
				$henkou = ($data['type'] == 'Break')? '破壊':'設置';
				$event->getPlayer()->sendMessage('[Log] ['.$data['id'].'/'.$data['idc']."][world:" . $block->getLevel()->getName() . "][x:".$block->getX()."][y:".$block->getY()."][x:".$block->getZ()."]");
				$event->getPlayer()->sendMessage('[Log] BlockID: '.$data['blockid'].' (' . $block->getName(). ')');
				$event->getPlayer()->sendMessage('[Log] ブロックを'.$henkou.'したプレーヤー: '.$data['user'] . ' ip:xxx.xxx.xxx.xxx'/* . $data['ip']*/);
				$event->getPlayer()->sendMessage('[Log] ブロックが'.$henkou.'された時間:'.$this->dateConversion($data['time']));
				/*if(){
					$event->getPlayer()->sendMessage('[Log] 看板が設置された記録があります\n[Log] 確認したい場合は/signlogで確認できます。');
				}*/
				++$this->log[$player->getName()];
			}else{
				$event->getPlayer()->sendMessage("[Log] ログがありません");
				$this->log[$player->getName()] = 1;
			}
		}else{
			//後回し
		}
	}

	/*public function onSign(SignChangeEvent $event) {//看板設置
		$data = $event->getLines();
		$block = $event->getBlock();
		if($mozi !== ""){
			$this->sqlite->setSign($event->getPlayer(), $block,$data);
		}
	}*/
	
	//コマンド
	public function onPlayerCommand(PlayerCommandPreprocessEvent $event){
		$player = $event->getPlayer();
		$m = $event->getMessage();
		$s = strpos($m, '/');
		if($s == 0 and $s !== false){
			if(strpos($m, '/register') !== false or strpos($m, '/login') !== false){
				$this->getLogger()->info($player->getName() . "さんがコマンド" . $this->commandProcessing($m) . "を使用しました");
			}else{
				$this->getLogger()->info($player->getName() . "さんがコマンドを使用しました コマンド:" . $m);
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
				}else{
					$this->log[$sender->getName()] = 1;
					$sender->sendMessage("[Log] ブロックタッチ!");
				}
				return true;
			break;
		}
	}

/////////////////////////////////
// その他処理
/////////////////////////////////
	//日付を日本語に変換
	public function dateConversion($date) {
		$date = str_replace("m", "月", $date);
		$date = str_replace("d", "日", $date);
		$date = str_replace("h", "時", $date);
		$date = str_replace("s", "分", $date);
		return $date;
	}
	
	//コマンドのパラメーターを隠す//パスワードなどの表示を避けるため
	public function commandProcessing($cmd) {
		if(strpos($cmd, ' ') !== false){
			$cmd = "/" . substr(strstr($cmd," ",true), true);
		}
		return $cmd;
	}
}