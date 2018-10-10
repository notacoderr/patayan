<?php
namespace Cody\Murder;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\tile\Sign;
use pocketmine\level\Level;
use pocketmine\item\Item;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\entity\EntityShootBowEvent;

use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\event\entity\ProjectileHitBlockEvent;
use pocketmine\event\entity\ProjectileHitEvent;

use pocketmine\event\inventory\InventoryPickupArrowEvent;
use onebone\economyapi\EconomyAPI;

use Cody\Murder\Resetmap;
use Cody\Murder\RefreshArena;

class Core extends PluginBase implements Listener {

	public $prefix = TextFormat::BOLD . TextFormat::GRAY . "[" . TextFormat::AQUA . "Robin" . TextFormat::GREEN . "Hood" . TextFormat::RESET . TextFormat::GRAY . "]";
	public $arrow;
	public $mode = 0;
    	public $currentLevel = "";
	public $playtime = 900; //300 = 5mins
	public $isplayingmdr = [], $iswaitingmdr = [], $murd = null, $det = null, $inn = [], $mdrarenas = [];
	
	public function onEnable()
	{
	 $this->getLogger()->info($this->prefix);
         $this->getServer()->getPluginManager()->registerEvents($this ,$this);
	 $this->economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if(!empty($this->economy))
        {
            $this->api = EconomyAPI::getInstance();
        }
		
		@mkdir($this->getDataFolder());
		
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		
		if($config->get("mdrarenas")!=null)
		{
			$this->mdrarenas = $config->get("mdrarenas");
		}
                foreach($this->mdrarenas as $lev)
		{
			$this->getServer()->loadLevel($lev);
		}
		
		$config->save();
		$this->getScheduler()->scheduleRepeatingTask(new GameSender($this), 20);
		$this->getScheduler()->scheduleRepeatingTask(new RefreshSigns($this), 10);
		
	}

public function getArrow() : Item
{
	return Item::get(Item::ARROW, 0, 1)->setCustomName("");
}
	
public function onJoin(PlayerJoinEvent $event) : void
{
	$player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->mdrarenas))
	{
		$this->leaveArena($player);
	}
}
	
public function onQuit(PlayerQuitEvent $event) : void
{
        $player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->mdrarenas))
	{
		$this->leaveArena($player);
	}
}
	
public function onHit(ProjectileHitEvent $event)
{
	$level = $event->getEntity()->getLevel()->getFolderName();
	if(in_array($level, $this->mdrarenas))
	{
		if($event instanceof ProjectileHitEntityEvent)
		{
			$shooter = $event->getEntity()->getOwningEntity();//shooter
			$noob = $event->getEntityHit();//todo
			if($noob instanceof Player && $shooter instanceof Player)
			{
				$noob->setHealth(20);
				$this->addKill($shooter->getName()); $this->notifyPlayer($shooter, 1);
				$this->addDeath($noob->getName()); $this->notifyPlayer($noob, 2);
				$this->randSpawn($noob, $noob->getLevel()->getFolderName());
				$shooter->getInventory()->addItem( $this->getArrow() );
			}
		}
		if($event instanceof ProjectileHitBlockEvent)
		{
			$event->getEntity()->kill();
		}
	}
}

public function onBlockBreak(BlockBreakEvent $event)
{
	$player = $event->getPlayer();
	$level = $player->getLevel()->getFolderName(); 
	if(in_array($level, $this->mdrarenas))
	{
		$event->setCancelled();
	}
}
	
public function onBlockPlace(BlockPlaceEvent $event)
{
	$player = $event->getPlayer();
	$level = $player->getLevel()->getFolderName(); 
	if(in_array($level, $this->mdrarenas))
	{
		$event->setCancelled();
	}
}
	
public function onDamage(EntityDamageEvent $event)
{
	if($event instanceof EntityDamageByEntityEvent)
	{
		if($event->getEntity() instanceof Player && $event->getDamager() instanceof Player)
		{
			$a = $event->getEntity()->getName(); $b = $event->getDamager()->getName();
			if(array_key_exists($a, $this->iswaitingmdr) || array_key_exists($b, $this->iswaitingmdr))
			{
				$event->setCancelled();
				return true;
			}
			if(in_array($a, $this->isplayingmdr) && in_array($event->getEntity()->getLevel()->getFolderName(), $this->mdrarenas))
			{
				$event->setCancelled(false); //for other plugin's cancelling damage event
				if($event->getFinalDamage() >= $event->getEntity()->getHealth())
				{
					$event->setDamage(0.0); //hack, to avoid players from getting killed
					$event->setCancelled();
					$this->addKill($event->getDamager()->getName());
					$event->getDamager()->addTitle("", "§Good Kill");
				}
			}	
			return true;
		}
	} else {
		$a = $event->getEntity()->getName();
		if(in_array($a, $this->isplayingmdr) || array_key_exists($a, $this->iswaitingmdr))
		{
			return $event->setCancelled();
		}
	}
}

public function onCommand(CommandSender $player, Command $cmd, $label, array $args) : bool
{
	if($player instanceof Player)
	{
		switch($cmd->getName())
		{
			case "mdr":
				if(!empty($args[0]))
				{
					if($args[0]=='make' or $args[0]=='create')
					{
						if($player->isOp())
						{
								if(!empty($args[1]))
								{
									if(file_exists($this->getServer()->getDataPath() . "/worlds/" . $args[1]))
									{
										$this->getServer()->loadLevel($args[1]);
										$this->getServer()->getLevelByName($args[1])->loadChunk($this->getServer()->getLevelByName($args[1])->getSafeSpawn()->getFloorX(), $this->getServer()->getLevelByName($args[1])->getSafeSpawn()->getFloorZ());
										array_push($this->mdrarenas,$args[1]);
										$this->currentLevel = $args[1];
										$this->mode = 1;
										$player->sendMessage($this->prefix . " •> " . "Touch the player lobby spawn");
										$player->setGamemode(1);
										$player->teleport($this->getServer()->getLevelByName($args[1])->getSafeSpawn(),0,0);
										return true;
									} else {
										$player->sendMessage($this->prefix . " •> ERROR missing world.");
										return true;
									}
								}
								else
								{
									$player->sendMessage($this->prefix . " •> " . "ERROR missing parameters.");
									return true;
								}
						} else {
							$player->sendMessage($this->prefix . " •> " . "Oh no! You are not OP.");
							return true;
						}
					}
					else if($args[0] == "leave" or $args[0] == "quit" )
					{
						$level = $player->getLevel()->getFolderName();
						if(in_array($level, $this->mdrarenas))
						{
							$this->leaveArena($player); 
							return true;
						}
					} else {
						$player->sendMessage($this->prefix . " •> " . "Invalid command.");
						return true;
					}
				} else {
					$player->sendMessage($this->prefix . " •> " . "/mdr <make-leave> : Create Arena | Leave the game");
					$player->sendMessage($this->prefix . " •> " . "/mdrstart : Start the game in 10 seconds");
				}
			break;
			
			case "mdrstart":
			if($player->isOp())
			{
				$player->sendMessage($this->prefix . " •> " . "§aStarting in 10 seconds...");
				$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
				$config->set("mdrarenas",$this->mdrarenas);
				foreach($this->mdrarenas as $arena)
				{
					$config->set($arena . "PlayTime", $this->playtime);
					$config->set($arena . "StartTime", 10);
				}
				$config->save();
			}
			break;
			default:
				return true;
		}
		return true;
	} 
}

public function announceWinner(String $arena, int $team) : void
{
	switch ($team)
	{
		case 1: $this->getServer()->broadcastMessage($this->prefix . " • §l§bMuderer §f won in ".$arena); break;
		case 2: $this->getServer()->broadcastMessage($this->prefix . " • §l§bDetective §f won in ".$arena); break;
		case 3: $this->getServer()->broadcastMessage($this->prefix . " • §l§bInnocents §f won in ".$arena); break;
	}
}
	
	
private function addDeath(string $name) : void
{
	$death = $this->deaths[ $name ];
	$this->deaths[ $name ] = (int) $death + 1;
}
	
public function leaveArena(Player $player, $arena = null) : void
{
	$spawn = $this->getServer()->getDefaultLevel()->getSafeSpawn();
	$this->getServer()->getDefaultLevel()->loadChunk($spawn->getFloorX(), $spawn->getFloorZ());
	$player->teleport($spawn , 0, 0);		
	$player->setFood(20);
	$player->setHealth(20);
	$this->removefromplaying($player->getName());
	$this->removefromwaiting($player->getName());
	$this->cleanPlayer($player);
}

function onTeleport(EntityLevelChangeEvent $event) : void
{
        if ($event->getEntity() instanceof Player) 
	{
		$player = $event->getEntity();
		$from = $event->getOrigin()->getFolderName();
		$to = $event->getTarget()->getFolderName();
		if(in_array($from, $this->mdrarenas) && !in_array($to, $this->mdrarenas))
		{	
			$this->removefromplaying($player->getName());
			$this->removefromwaiting($player->getName());
			$this->cleanPlayer($player);
		}
		if(in_array($to, $this->mdrarenas))
		{
			if (!array_key_exists($player->getName(), $this->iswaitingmdr)){
				$player->sendMessage($this->prefix . " •> §cPlease use the sign to join");
				$event->setCancelled();
			}
		}
        }
}

public function removefromplaying(string $playername) : void
{
	if (in_array($playername, $this->isplayingmdr)){
		unset($this->isplayingmdr[ $playername ]);
	}
}
	
public function removefromwaiting(string $playername) : void
{
	if (array_key_exists($playername, $this->iswaitingmdr)){
		unset($this->iswaitingmdr[ $playername ]);
	}
}
	
private function cleanPlayer(Player $player) : void
{
	$player->getInventory()->clearAll();
	$player->getArmorInventory()->clearAll();
	$player->getArmorInventory()->sendContents($player);
	$player->setNameTag( $this->getServer()->getPluginManager()->getPlugin('PureChat')->getNametag($player) );
}

private function randSpawn(Player $player, string $arena) : void
{
	$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
	$level = $this->getServer()->getLevelByName($arena);
	switch(mt_rand(0,3))
	{
		case 0: $thespawn = $config->get($arena . "Spawn1"); break;
		case 1: $thespawn = $config->get($arena . "Spawn2"); break;
		case 2: $thespawn = $config->get($arena . "Spawn3"); break;
		case 3: $thespawn = $config->get($arena . "Spawn4"); break;
	}
	$spawn = new Position($thespawn[0], $thespawn[1]+0.5, $thespawn[2], $level);
	$player->teleport($spawn, 0, 0);
	$player->setFood(20);
	$player->setHealth(20);
}
	
public function assignSpawn($arena) : void
{
	foreach($this->iswaitingmdr as $name => $ar)
	{
		if(strtolower($ar) === strtolower($arena))
		{
			$player = $this->getServer()->getPlayer($name);
			$this->randSpawn($player, $arena);
			$this->playGame($player);
			//set( $this->iswaitingmdr[$name] );
		}
	}
}
	
private function playGame(Player $player)
{
	$player->addTitle("§lPCP : §fRobin§aHood", "§l§fAim for the highest");
	array_push($this->isplayingmdr, $player->getName()); //finally, set as playing
}
	
private function assignRole(string $name, int $role) : void
{
	switch($role)
	{
		case 1: $this->murd = $name;
		case 2: $this->det = $name;
		case 3: array_push($this->inn, $name);
	}
	unset( $this->iswaitingmdr[$name] );
}

public function insertMurdererKit(Player $player)
{
	$player->getInventory()->setItem(1, Item::get(Item::STONE_AXE, 0, 1)->setCustomName('§l§fHatchet'));
}

public function insertDetectiveKit(Player $player)
{
	$player->getInventory()->setItem(1, Item::get(Item::STICK, 0, 1)->setCustomName('§l§fNight Stick'));
}

public function onInteract(PlayerInteractEvent $event)
{
	$player = $event->getPlayer();
	$block = $event->getBlock();
	$tile = $player->getLevel()->getTile($block);
	if($tile instanceof Sign) 
	{
		if($this->mode == 26 )
		{
			$tile->setText(TextFormat::AQUA . "[Join]", TextFormat::YELLOW  . "0 / 12", "§f".$this->currentLevel, $this->prefix);
			$this->refreshmdrarenas();
			$this->currentLevel = "";
			$this->mode = 0;
			$player->sendMessage($this->prefix . " •> " . "Arena Registered!");
		} else {
			$text = $tile->getText();
			if($text[3] == $this->prefix)
			{
				if($text[0] == TextFormat::AQUA . "[Join]")
				{
					$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
					$namemap = str_replace("§f", "", $text[2]);
					
					$this->iswaitingmdr[ $player->getName() ] = $namemap;//beta, set to waiting to be able to tp
					$this->kills[ $player->getName() ] = 0; //create kill points
					$this->deaths[ $player->getName() ] = 0; //create death points
					
					$level = $this->getServer()->getLevelByName($namemap);
					$thespawn = $config->get($namemap . "Lobby");
					$spawn = new Position($thespawn[0]+0.5 , $thespawn[1] ,$thespawn[2]+0.5 ,$level);
					$level->loadChunk($spawn->getFloorX(), $spawn->getFloorZ());
					
					$player->teleport($spawn, 0, 0);
					$player->getInventory()->clearAll();
					$player->removeAllEffects();
					$player->setHealth(20);
					$player->setGameMode(2);

					return true;
				} else {
					$player->sendMessage($this->prefix . " •> " . "Please try to join later...");
					return true;
				}
			}
		}
	}
	if($this->mode >= 1 && $this->mode <= 4 )
	{
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set($this->currentLevel . "Spawn" . $this->mode, array($block->getX(),$block->getY()+1,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Spawn " . $this->mode . " has been registered!");
		$this->mode++;
		if($this->mode == 5)
		{
			$player->sendMessage($this->prefix . " •> " . "Tap to set the lobby spawn");
		}
		$config->save();
		return true;
	}
	if($this->mode == 5)
	{
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set($this->currentLevel . "Lobby", array($block->getX(),$block->getY()+1,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Lobby has been registered!");
		$this->mode++;
		if($this->mode == 6)
		{
			$player->sendMessage($this->prefix . " •> " . "Tap anywhere to continue");
		}
		$config->save();
		return true;
	}
	
	if($this->mode == 6)
	{
		$level = $this->getServer()->getLevelByName($this->currentLevel);
		$level->setSpawn = (new Vector3($block->getX(),$block->getY()+2,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Touch a sign to register Arena!");
		$spawn = $this->getServer()->getDefaultLevel()->getSafeSpawn();
		$this->getServer()->getDefaultLevel()->loadChunk($spawn->getFloorX(), $spawn->getFloorZ());
		$player->teleport($spawn,0,0);
		
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set("mdrarenas", $this->mdrarenas);
		$config->save();
		$this->mode= 26;
		return true;
	}
}

	
public function refreshmdrarenas()
{
	$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
	$config->set("mdrarenas",$this->mdrarenas);
	foreach($this->mdrarenas as $arena)
	{
		$config->set($arena . "PlayTime", $this->playtime);
		$config->set($arena . "StartTime", 90);
	}
	$config->save();
}

public function dropitem(PlayerDropItemEvent $event)
{
	$player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->mdrarenas))
	{
		$event->setCancelled(true);
		return true;
	}
}
	

	
}

class RefreshSigns extends PluginTask
{
	
public function __construct($plugin)
{
	$this->plugin = $plugin;
	parent::__construct($plugin);
}
  
public function onRun($tick)
{
	
	$level = $this->plugin->getServer()->getDefaultLevel();
	$tiles = $level->getTiles();
	foreach($tiles as $t) {
		if($t instanceof Sign) {	
			$text = $t->getText();
			if($text[3] == $this->plugin->prefix)
			{
				$namemap = str_replace("§f", "", $text[2]);
				$arenalevel = $this->plugin->getServer()->getLevelByName( $namemap );
				$playercount = count($arenalevel->getPlayers());
				$ingame = TextFormat::AQUA . "[Join]";
				$config = new Config($this->plugin->getDataFolder() . "/config.yml", Config::YAML);
				if($config->get($namemap . "PlayTime") <> $this->plugin->playtime)
				{
					$ingame = TextFormat::DARK_PURPLE . "[Running]";
				}
				if( $playercount >= 12)
				{
					$ingame = TextFormat::GOLD . "[Full]";
				}
				$t->setText($ingame, TextFormat::YELLOW  . $playercount . " / 12", $text[2], $this->plugin->prefix);
			}
		}
	}
}

}

class GameSender extends PluginTask
{
	public function __construct($plugin) {
		$this->plugin = $plugin;
		parent::__construct($plugin);
	}
	public function onRun($tick)
	{
		$config = new Config($this->plugin->getDataFolder() . "/config.yml", Config::YAML);
		$mdrarenas = $config->get("mdrarenas");
		if(!empty($mdrarenas))
		{
			foreach($mdrarenas as $arena)
			{
				$time = $config->get($arena . "PlayTime");
				$mins = floor($time / 60 % 60);
				$secs = floor($time % 60);
				if($secs < 10){ $secs = "0".$secs; }
				$timeToStart = $config->get($arena . "StartTime");
				$levelArena = $this->plugin->getServer()->getLevelByName($arena);
				if($levelArena instanceof Level)
				{
					$playersArena = $levelArena->getPlayers();
					if( count($playersArena) == 0)
					{
						$config->set($arena . "PlayTime", $this->plugin->playtime);
						$config->set($arena . "StartTime", 90);
					} else {
						if(count($playersArena) >= 2)
						{
							if($timeToStart > 0) //TO DO fix player count and timer
							{
								$timeToStart--;
								
								switch($timeToStart)
								{
									case 10:
										foreach($playersArena as $pl)
										{
											$pl->sendPopup($this->plugin->prefix . " §7•>§c Attention!§f your inventory will be wiped..");
											$pl->getInventory()->clearAll();
										}
									break;
									
									case 7: //assign murderer
										shuffle($this->iswaitingmdr);
										$this->assignRole($this->isiwaitingmdr[0], 1);
									break;
									
									case 5: //assign detective
										shuffle($this->iswaitingmdr);
										$this->assignRole($this->isiwaitingmdr[0], 2);
									break;
									
									case 3: //rest are innocents
										foreach($this->iswaitingmdr as $name)
										{
											$this->assignRole($this->isiwaitingmdr[0], 3);
										}
									break;
										
									default:
										foreach($playersArena as $pl)
										{
											$pl->sendPopup("§l§7[ §f". $timeToStart ." seconds to start §7]");
										}
								}
								
								$config->set($arena . "StartTime", $timeToStart);
							} else {
								$aop = count($levelArena->getPlayers());
								if($aop >= 2)
								{
									foreach($playersArena as $pla)
									{
										$pla->sendTip("§l§fInnocents Left: ". count($this->inn));
										$pla->sendPopup("§l§7Game ends in: §b".$mins. "§f:§b" .$secs);
									}
								}
								
								$time--;
								switch($time)
								{
									case 299:
										$this->plugin->assignSpawn($arena);
										foreach($playersArena as $pl)
										{
											$pl->addTitle("§l§fRo§7b§fin §aHood","§l§fYou are playing on: §a" . $arena);
										}
									break;
									
									case 239:
										foreach($playersArena as $pl)
										{
											$pl->addTitle("§l§7Countdown", "§b§l".$mins. "§f:§b" .$secs. "§f remaining");
										}
									break;
									
									case 179:
										foreach($playersArena as $pl)
										{
											$pl->addTitle("§l§7Countdown", "§b§l".$mins. "§f:§b" .$secs. "§f remaining");
										}
									break;
									
									default:
									if($time <= 0)
									{
										$this->plugin->announceWinner($arena);
										if(count($this->inn) > 0)
										{
										}
										$spawn = $this->plugin->getServer()->getDefaultLevel()->getSafeSpawn();
										$this->plugin->getServer()->getDefaultLevel()->loadChunk($spawn->getX(), $spawn->getZ());
										foreach($playersArena as $pl)
										{
											$pl->addTitle("§lGame Over","§cYou have played on: §a" . $arena);
											$pl->setHealth(20);
											$this->plugin->leaveArena($pl);
										}
										
										$time = $this->plugin->playtime;
									}
								}
								$config->set($arena . "PlayTime", $time);
							}
						} else {
							if($timeToStart <= 0)
							{
								foreach($playersArena as $pl)
								{
									$this->plugin->announceWinner($arena, $pl->getName());
									$pl->setHealth(20);
									$this->plugin->leaveArena($pl);
									//$this->plugin->api->addMoney($pl->getName(), mt_rand(390, 408));//bullshit
									$this->plugin->givePrize($pl);
									//$this->getResetmap()->reload($levelArena);
								}
								$config->set($arena . "PlayTime", $this->plugin->playtime);
								$config->set($arena . "StartTime", 90);
							} else {
								foreach($playersArena as $pl)
								{
									$pl->sendPopup("§e§l< §7need more player(s) to start§e >");
								}
								$config->set($arena . "PlayTime", $this->plugin->playtime);
								$config->set($arena . "StartTime", 90);
							}
						}
					}
				}
			}
		}
		$config->save();
	}
}
