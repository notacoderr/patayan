<?php
namespace LittleBigMC\RBH;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\PluginTask;
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

use LittleBigMC\RBH\Resetmap;
use LittleBigMC\RBH\RefreshArena;

class RBH extends PluginBase implements Listener {

	public $prefix = TextFormat::BOLD . TextFormat::GRAY . "[" . TextFormat::AQUA . "Robin" . TextFormat::GREEN . "Hood" . TextFormat::RESET . TextFormat::GRAY . "]";
	public $arrow;
	public $mode = 0;
    public $currentLevel = "";
	public $playtime = 300;
	public $isplayingrbh = [], $iswaitingrbh = [], $deaths = [], $kills = [], $rbharenas = [];
	
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
		
		if($config->get("rbharenas")!=null)
		{
			$this->rbharenas = $config->get("rbharenas");
		}
                foreach($this->rbharenas as $lev)
		{
			$this->getServer()->loadLevel($lev);
		}
		
		$config->save();
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new GameSender($this), 20);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new RefreshSigns($this), 10);
		
	}

public function getArrow() : Item
{
	return Item::get(Item::ARROW, 0, 1)->setCustomName("");
}
	
public function onJoin(PlayerJoinEvent $event) : void
{
	$player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->rbharenas))
	{
		$this->leaveArena($player);
	}
}
	
public function onQuit(PlayerQuitEvent $event) : void
{
        $player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->rbharenas))
	{
		$this->leaveArena($player);
	}
}
	
public function onHit(ProjectileHitEvent $event)
{
	$level = $event->getEntity()->getLevel()->getFolderName();
	if(in_array($level, $this->rbharenas))
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
	if(in_array($level, $this->rbharenas))
	{
		$event->setCancelled();
	}
}
	
public function onBlockPlace(BlockPlaceEvent $event)
{
	$player = $event->getPlayer();
	$level = $player->getLevel()->getFolderName(); 
	if(in_array($level, $this->rbharenas))
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
			if(array_key_exists($a, $this->iswaitingrbh) || array_key_exists($b, $this->iswaitingrbh))
			{
				$event->setCancelled();
				return true;
			}
			if(in_array($a, $this->isplayingrbh) && in_array($event->getEntity()->getLevel()->getFolderName(), $this->rbharenas))
			{
				$event->setCancelled(false); //for other plugin's cancelling damage event
				
				if($event->getCause() == 2)
				{
					$event->setDamage(0.0); //hack, to remove damage on projectile hit entity event
				}
				
				if($event->getDamage() >= $event->getEntity()->getHealth())
				{
					$event->setDamage(0.0); //hack, to avoid players from getting killed
					$event->setCancelled();
					$inv = $event->getDamager()->getInventory();
					if(!$inv->contains( Item::get(Item::ARROW) ))
					{
						$inv->addItem( $this->getArrow() );
					}
					$this->addKill($event->getDamager()->getName()); $this->notifyPlayer($event->getDamager(), 1);
					$this->addDeath($event->getEntity()->getName()); $this->notifyPlayer($event->getEntity(), 2);
					$this->randSpawn($event->getEntity(), $event->getEntity()->getLevel()->getFolderName());
				}
			}	
			return true;
		}
	} else {
		$a = $event->getEntity()->getName();
		if(in_array($a, $this->isplayingrbh) || array_key_exists($a, $this->iswaitingrbh))
		{
			return $event->setCancelled();
		}
	}
}
	
private function notifyPlayer(Player $player, int $type): void
{
	switch($type)
	{
		case 1:
			$player->addTitle("", "§b+1 Kill");
		break;

		case 2:
			$player->addTitle("", "§c+1 Death");
		break;
	}
}

public function onCommand(CommandSender $player, Command $cmd, $label, array $args) : bool
{
	if($player instanceof Player)
	{
		switch($cmd->getName())
		{
			case "rbh":
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
										array_push($this->rbharenas,$args[1]);
										$this->currentLevel = $args[1];
										$this->mode = 1;
										$player->sendMessage($this->prefix . " •> " . "Touch 1st player spawn");
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
					else if($args[0] == "leave" or $args[0]=="quit" )
					{
						$level = $player->getLevel()->getFolderName();
						if(in_array($level, $this->rbharenas))
						{
							$this->leaveArena($player); 
							return true;
						}
					} else {
						$player->sendMessage($this->prefix . " •> " . "Invalid command.");
						return true;
					}
				} else {
					$player->sendMessage($this->prefix . " •> " . "/rbh <make-leave> : Create Arena | Leave the game");
					$player->sendMessage($this->prefix . " •> " . "/rbhstart : Start the game in 10 seconds");
				}
			break;
			
			case "rbhstart":
			if($player->isOp())
			{
				$player->sendMessage($this->prefix . " •> " . "§aStarting in 10 seconds...");
				$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
				$config->set("rbharenas",$this->rbharenas);
				foreach($this->rbharenas as $arena)
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

public function announceWinner(String $arena, $name = null)
{
	if(is_null($name))
	{
		$levelArena = $this->getServer()->getLevelByName($arena);
		$plrs = $levelArena->getPlayers();
		arsort($this->kills);
		foreach($this->kills as $pln => $k)
		{
			if($this->getServer()->getPlayer($pln)->getLevel()->getFolderName() == $arena)
			{
				$this->api->addMoney($pln , mt_rand(390, 408));
				$this->givePrize( $this->getServer()->getPlayer($pln) );
				foreach($this->getServer()->getOnlinePlayers() as $ppl)
				{
					$ppl->sendMessage($this->prefix . " • §l§b".$pln."§f won in ".$arena.", with §b".$k." §fkills");
				}
				return true; //stops at first highest player
			}
		}
	} else {
		foreach($this->getServer()->getOnlinePlayers() as $ppl)
		{
			$ppl->sendMessage($this->prefix . " • §l§b".$name."§f won in ".$arena);
		}
		return true;
	}
}
	
private function addKill(string $name) : void
{
	$kill = $this->kills[ $name ];
	$this->kills[ $name ] = (int) $kill + 1;
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
	$player->setGameMode(2);
	$player->setFood(20);
	$player->setHealth(20);
	
	$this->removefromplaying($player->getName());
	$this->removefromwaiting($player->getName());
	$this->removedatas($player->getName());
	
	$this->cleanPlayer($player);
}

function onTeleport(EntityLevelChangeEvent $event)
{
        if ($event->getEntity() instanceof Player) 
	{
		$player = $event->getEntity();
		$from = $event->getOrigin()->getFolderName();
		$to = $event->getTarget()->getFolderName();
		if(in_array($from, $this->rbharenas) && !in_array($to, $this->rbharenas))
		{
			$event->getEntity()->setGameMode(2);	
			$this->removefromplaying($player->getName());
			$this->removefromwaiting($player->getName());
			$this->removedatas($player->getName());
			$this->cleanPlayer($player);
			return true;
		}
		if(in_array($to, $this->rbharenas))
		{
			if (!array_key_exists($player->getName(), $this->iswaitingrbh)){
				$player->sendMessage($this->prefix . " •> §cPlease use the sign to join");
				return $event->setCancelled();
			}
		}
        }
}

public function removefromplaying(string $playername)
{
	if (in_array($playername, $this->isplayingrbh)){
		unset($this->isplayingrbh[ $playername ]);
	}
}
	
public function removefromwaiting(string $playername)
{
	if (array_key_exists($playername, $this->iswaitingrbh)){
		unset($this->iswaitingrbh[ $playername ]);
	}
}

public function removedatas(string $playername)
{
	if (array_key_exists($playername, $this->deaths)){
		unset($this->deaths[ $playername ]);
	}
	if (array_key_exists($playername, $this->kills)){
		unset($this->kills[ $playername ]);
	}
}
	
private function cleanPlayer(Player $player)
{
	$player->getInventory()->clearAll();
	//$i = Item::get(0);
	//$player->getArmorInventory()->setHelmet($i);
	//$player->getArmorInventory()->setChestplate($i);
	//$player->getArmorInventory()->setLeggings($i);
	//$player->getArmorInventory()->setBoots($i);
	$player->getArmorInventory()->clearAll();
	$player->getArmorInventory()->sendContents($player);
	$player->setNameTag( $this->getServer()->getPluginManager()->getPlugin('PureChat')->getNametag($player) );
}

private function randSpawn(Player $player, string $arena)
{
	$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
	$i = mt_rand(0, 11);
	$level = $this->getServer()->getLevelByName($arena);
	switch($i)
	{
		case 0: $thespawn = $config->get($arena . "Spawn1"); break;
		case 1: $thespawn = $config->get($arena . "Spawn2"); break;
		case 2: $thespawn = $config->get($arena . "Spawn3"); break;
		case 3: $thespawn = $config->get($arena . "Spawn4"); break;
		case 4: $thespawn = $config->get($arena . "Spawn5"); break;
		case 5: $thespawn = $config->get($arena . "Spawn6"); break;
		case 6: $thespawn = $config->get($arena . "Spawn7"); break;
		case 7: $thespawn = $config->get($arena . "Spawn8"); break;
		case 8: $thespawn = $config->get($arena . "Spawn9"); break;
		case 9: $thespawn = $config->get($arena . "Spawn10"); break;
		case 10: $thespawn = $config->get($arena . "Spawn11"); break;
		case 11: $thespawn = $config->get($arena . "Spawn12"); break;
	}
	$spawn = new Position($thespawn[0]+0.5 , $thespawn[1] ,$thespawn[2]+0.5 ,$level);
	$player->teleport($spawn, 0, 0);
	$this->giveKit($player);
	$player->setFood(20);
	$player->setHealth(20);
}
	
public function assignSpawn($arena)
{
	$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
	$i = 0;
	foreach($this->iswaitingrbh as $name => $ar)
	{
		if(strtolower($ar) === strtolower($arena))
		{
			$player = $this->getServer()->getPlayer($name);
			$level = $this->getServer()->getLevelByName($arena);
			switch($i)
			{
				case 0: $thespawn = $config->get($arena . "Spawn1"); break;
				case 1: $thespawn = $config->get($arena . "Spawn2"); break;
				case 2: $thespawn = $config->get($arena . "Spawn3"); break;
				case 3: $thespawn = $config->get($arena . "Spawn4"); break;
				case 4: $thespawn = $config->get($arena . "Spawn5"); break;
				case 5: $thespawn = $config->get($arena . "Spawn6"); break;
				case 6: $thespawn = $config->get($arena . "Spawn7"); break;
				case 7: $thespawn = $config->get($arena . "Spawn8"); break;
				case 8: $thespawn = $config->get($arena . "Spawn9"); break;
				case 9: $thespawn = $config->get($arena . "Spawn10"); break;
				case 10: $thespawn = $config->get($arena . "Spawn11"); break;
				case 11: $thespawn = $config->get($arena . "Spawn12"); break;
			}
			$spawn = new Position($thespawn[0]+0.5 , $thespawn[1] ,$thespawn[2]+0.5 ,$level);
			$level->loadChunk($spawn->getFloorX(), $spawn->getFloorZ());
			$player->teleport($spawn, 0, 0);
			$player->setHealth(20);
			//$player->setGameMode(2);
			
			$this->playGame($player);
			unset( $this->iswaitingrbh[$name] );
			$i += 1;
		}
	}
}
	
private function playGame(Player $player)
{
	$player->addTitle("§lPCP : §fRobin§aHood", "§l§fAim for the highest");
	$this->insertArrow($player);
	array_push($this->isplayingrbh, $player->getName()); //finally, set as playing
}

public function insertBow(Player $player)
{
	$player->getInventory()->setItem(0, Item::get(Item::BOW, 0, 1)->setCustomName('§l§fAncient Long Bow'));
}

public function insertAxe(Player $player)
{
	$player->getInventory()->setItem(1, Item::get(Item::STONE_AXE, 0, 1)->setCustomName('§l§fHatchet'));
}

public function insertArrow(Player $player)
{
	$player->getInventory()->setItem(2, $this->getArrow() );
}

private function giveKit(Player $player)
{
	$player->getInventory()->clearAll();
	$player->getInventory()->setItem(0, Item::get(Item::BOW, 0, 1)->setCustomName('§l§fAncient Long Bow'));
	$player->getInventory()->setItem(1, Item::get(Item::STONE_AXE, 0, 1)->setCustomName('§l§fHatchet'));
	$player->getInventory()->setItem(2, $this->getArrow() );
	
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
			$this->refreshrbharenas();
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
					
					$this->iswaitingrbh[ $player->getName() ] = $namemap;//beta, set to waiting to be able to tp
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
	if($this->mode >= 1 && $this->mode <= 12 )
	{
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set($this->currentLevel . "Spawn" . $this->mode, array($block->getX(),$block->getY()+1,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Spawn " . $this->mode . " has been registered!");
		$this->mode++;
		if($this->mode == 13)
		{
			$player->sendMessage($this->prefix . " •> " . "Tap to set the lobby spawn");
		}
		$config->save();
		return true;
	}
	if($this->mode == 13)
	{
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set($this->currentLevel . "Lobby", array($block->getX(),$block->getY()+1,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Lobby has been registered!");
		$this->mode++;
		if($this->mode == 14)
		{
			$player->sendMessage($this->prefix . " •> " . "Tap anywhere to continue");
		}
		$config->save();
		return true;
	}
	
	if($this->mode == 14)
	{
		$level = $this->getServer()->getLevelByName($this->currentLevel);
		$level->setSpawn = (new Vector3($block->getX(),$block->getY()+2,$block->getZ()));
		$player->sendMessage($this->prefix . " •> " . "Touch a sign to register Arena!");
		$spawn = $this->getServer()->getDefaultLevel()->getSafeSpawn();
		$this->getServer()->getDefaultLevel()->loadChunk($spawn->getFloorX(), $spawn->getFloorZ());
		$player->teleport($spawn,0,0);
		
		$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
		$config->set("rbharenas", $this->rbharenas);
		$config->save();
		$this->mode=26;
		return true;
	}
}

	
public function refreshrbharenas()
{
	$config = new Config($this->getDataFolder() . "/config.yml", Config::YAML);
	$config->set("rbharenas",$this->rbharenas);
	foreach($this->rbharenas as $arena)
	{
		$config->set($arena . "PlayTime", $this->playtime);
		$config->set($arena . "StartTime", 90);
	}
	$config->save();
}

public function dropitem(PlayerDropItemEvent $event)
{
	$player = $event->getPlayer();
	if(in_array($player->getLevel()->getFolderName(), $this->rbharenas))
	{
		$event->setCancelled(true);
		return true;
	}
}
	
public function givePrize(Player $player)
{
	$name = $player->getLowerCaseName();
	$levelapi = $this->getServer()->getPluginManager()->getPlugin('LevelAPI');
	$xp = mt_rand(15, 21);
	$levelapi->addVal($name, "exp", $xp);
	$crate = $this->getServer()->getPluginManager()->getPlugin("CoolCrates");
	
	if(!is_null($crate))
	{
		$crate->getSessionManager()->getSession($player)->addCrateKey("common.crate", 2);
	}
	
	$form = $this->getServer()->getPluginManager()->getPlugin("FormAPI")->createSimpleForm(function (Player $player, array $data)
	{
           if (isset($data[0]))
		{
               $button = $data[0];
               switch ($button)
			{
				case 0: $this->getServer()->dispatchCommand($player, "top");
					break;	
				default: 
					return true;
			}
			return true;
           }
       });
	
	$form->setTitle($this->prefix. " : §l§fP§bC§fP");
	$rank = $levelapi->getVal($name, "rank");
	$div = $levelapi->getVal($name, "div");
	$resp = $levelapi->getVal($name, "respect");
	
	$s = "";
	$s .= "§l§f• Experience points: +§a".$xp."§r\n";
	$s .= "§l§f• Bonus: +§e2§f common crate keys§r\n";
	$s .= "§l§f• Current ELO: §b".$rank." ".$div." §f| RP: §7[§c".$resp."§7] §f•§r\n";
	$s .= "§r\n";
	$form->setContent($s);
	
	$form->addButton("§lCheck Rankings", 1, "https://cdn4.iconfinder.com/data/icons/we-re-the-best/512/best-badge-cup-gold-medal-game-win-winner-gamification-first-award-acknowledge-acknowledgement-prize-victory-reward-conquest-premium-rank-ranking-gold-hero-star-quality-challenge-trophy-praise-victory-success-128.png");
	$form->addButton("Confirm", 1, "https://cdn1.iconfinder.com/data/icons/materia-arrows-symbols-vol-8/24/018_317_door_exit_logout-128.png");
	$form->sendToPlayer($player);
	
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
		$rbharenas = $config->get("rbharenas");
		if(!empty($rbharenas))
		{
			foreach($rbharenas as $arena)
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
										}
									break;
									
									case 7: //wipes inventory
										foreach($playersArena as $pl)
										{
											$pl->getInventory()->clearAll();
										}
									break;
									
									case 5: //inserts bow
										foreach($playersArena as $pl)
										{
											$this->plugin->insertBow($pl);
										}
									break;
									
									case 3: //inserts axe
										foreach($playersArena as $pl)
										{
											$this->plugin->insertAxe($pl);
										}
									break;
											//insert arrow is in playGame() function
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
										$pla->sendTip("§l§fK ".$this->plugin->kills[ $pla->getName() ]." : D ".$this->plugin->deaths[ $pla->getName() ]);
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
									$this->plugin->api->addMoney($pl->getName(), mt_rand(390, 408));//bullshit
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
