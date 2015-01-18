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
		//$this->getLogger()->info("ブロックの記録を開始します");
		$this->sqlite = new sqlite($this);
		$this->log = array();
	}
/////////////////////////////////
// イベント
/////////////////////////////////

	public function onBreak(BlockBreakEvent $event) {//ブロックの破壊
		if($this->config->get("blockbreak") == "true"){
			$block = $event->getBlock();
			$this->sqlite->addLog($event->getPlayer(), $block,'Break');
		}
	}

	public function onPlace(BlockPlaceEvent $event) {//ブロックの設置
		if($this->config->get("blockplace") == "true"){
			$block = $event->getBlock();
			if(!isset($this->log[$event->getPlayer()->getName()])){
				$this->sqlite->addLog($event->getPlayer(), $block,'Place');
			}
		}
	}

	public function onInteract(PlayerInteractEvent $event) {//ブロックタッチ
		$player = $event->getPlayer();
		if(isset($this->log[$player->getName()])){
			$block = $event->getBlock();
			$log = $this->sqlite->getLog($block,$this->log[$player->getName()],$player);
			if($log !== null){
				//[id] ページ数, [idc] 最大ページ数, [blockid] ブロックのid, [user] ブロックを変更したプレーヤーの名前, [ip] 変更したプレーヤーのip, [time] 変更された時間
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
		if($this->config->get("playercmd") == "true"){
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
					$this->log[$sender->getName()] = 1;
					$sender->sendMessage("[Log] ブロックタッチ!");
				}
				return true;
			break;
			case "rollback":
				if(!isset($args[0])){return false;}//例外回避
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
							$this->rb($sender);
							$sender->sendMessage("[RollBack] ロールバックが完了しました");
						}else{
							$sender->sendMessage("[RollBack] 始点と終点を指定してください");
						}
						
						break;
					default:
						$sender->sendMessage("[RollBack] -------コマンド一覧-------");
						$sender->sendMessage("[RollBack] /rollback pos1 始点を指定");
						$sender->sendMessage("[RollBack] /rollback pos2 終点を指定");
						$sender->sendMessage("[RollBack] /rollback rollback <数値> ロールバック");
						$sender->sendMessage("[RollBack] -----------------------");
				}
				return true;
			break;
			case "test":
				$testdata = $this->idProcessing($args[0]);
				$sender->sendMessage("結果." . $testdata['id']);
				$sender->sendMessage("結果2." . $testdata['meta']);
				return true;
			break;
		}
	}

/////////////////////////////////
// ロールバックの処理
/////////////////////////////////
	//指定された範囲をロールバック!
	public function rb($player) {
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
						if($count !== null or $count - 1 >= 1){
							$log = $this->sqlite->getLog($posp, $count - 1);
							if(isset($log['blockid'])){
								$logs = $this->idProcessing($log['blockid']);
								$data = Block::get($logs['id'], $logs['meta']);
								$this->getLogger()->info("data:id." . $logs['id'] . " mata." . $logs['meta']);
								$level->setBlock($posp, $data);
							}
							
						}
					}
				}
			}
			
		}else{
			return false;
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
	
	//ブロックidとメタ値を分ける
	public function idProcessing($id) {
		if(strpos($id, ':') !== false){
			$ids['meta'] = str_replace(":", "",strstr($id,":"));
			$ids['id'] = strstr($id, ":", true);
			return $ids;
		}else{
			return false;
		}
	}
}