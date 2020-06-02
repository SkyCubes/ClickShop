<?php
namespace skycubes\clickshop;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\tile\Sign;
use pocketmine\tile\Chest;
use pocketmine\tile\Tile;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\event\player\PlayerQuitEvent;


class ClickShop extends PluginBase implements Listener{

	private $config;
	private $translator;
	private $definitions;
	private $skyforms;
	private $economy;

	protected $shopCreationQueue = [];

	protected $oneClicks = [];

	public function onLoad(){
		
		$this->definitions = new Definitions($this);
		
		@mkdir($this->getDataFolder());
		@mkdir($this->getDataFolder().$this->definitions->getDef('LANG_PATH'));
        foreach(array_keys($this->getResources()) as $resource){
			$this->saveResource($resource, false);
		}

	}

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);

		$this->translator = new Translate($this);

		$this->skyforms = $this->getServer()->getPluginManager()->getPlugin("SkyForms");
		$this->economy = $this->getServer()->getPluginManager()->getPlugin("Economy")->getEconomy();

		$this->getLogger()->info("§a".$this->translator->get('PLUGIN_SUCCESSFULLY_ENABLED', [$this->getFullName()]));
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch($command->getName()){
			case "shop":
			case "loja":

				if(isset($args[0])) switch ($args[0]){
					case 'criar':
						
						$item = $sender->getInventory()->getItemInHand();
						$this->showShopCreationForm($sender, $item);

						break;
					
					default:
						# code...
						break;
				}
				break;

			default:
				break;
		}

		return true;
	}


	/** 
    * Add shop creation to queue (to click on a sign to create shop)
    * @access public 
    * @param pocketmine\Player $player
    * @param pocketmine\item\Item $item
    * @return void
    */
	public function showShopCreationForm(Player $player, $item){

		$shopTypes = array(true, false);
		
		$labelTypes = array($this->translator->get('FORM_SHOP_TYPE_OPTION_SELL'), $this->translator->get('FORM_SHOP_TYPE_OPTION_BUY'));

		$formTitle = $this->translator->get('FORM_SHOP_CREATION_TITLE');
		$form = $this->skyforms->createCustomForm($formTitle);

		$form->addLabel($this->translator->get('FORM_SHOP_COMERCIALIZED_ITEM'));
		$form->addLabel("§b".$item->getName());

		$form->addDropdown($this->translator->get('FORM_SHOP_TYPE_LABEL'), $labelTypes);

		$form->addStepSlider($this->translator->get('FORM_SHOP_QTD_LABEL'), ["1", "16", "32", "64"]);

		$form->addInput($this->translator->get('FORM_SHOP_PRICE_LABEL'), $this->translator->get('FORM_SHOP_PRICE_PLACEHOLDER'));

		if($player->isOp()){
			$form->addToggle($this->translator->get('FORM_SHOP_IS_OFFICIAL'), false);
		}


		$form->sendTo($player, function($response) use (&$player, &$shopTypes, &$item){

			$qtdArray = [1, 16, 32, 64];
			$isOfficial = false;

			$type = $shopTypes[$response[$this->translator->get('FORM_SHOP_TYPE_LABEL')]];
			$qtd = $response[$this->translator->get('FORM_SHOP_QTD_LABEL')];
			$price = $response[$this->translator->get('FORM_SHOP_PRICE_LABEL')];
			if(isset($response[$this->translator->get('FORM_SHOP_IS_OFFICIAL')]) &&
				$response[$this->translator->get('FORM_SHOP_IS_OFFICIAL')] == true){
				$isOfficial = true;
			}

			$this->shopCreationQueue[$player->getName()] = array(
				"action" => "create",
				"type" => $type,
				"qtd" => $qtdArray[$qtd],
				"value" => $price,
				"itemID" => $item->getId(),
				"itemName" => $item->getName(),
				"official" => $isOfficial
			);

			$player->addTitle("\n", "§e".$this->translator->get('CLICK_SIGN_TO_CREATE_SHOP'), 20, 2*20, 20);
		});

	}


	/** 
    * add listener to a sign tap
    * @access public 
    * @param pocketmine\event\PlayerInteractEvent $event
    * @return mixed
    */
	public function onBlockTouch(PlayerInteractEvent $event){
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$item = $event->getItem();
		if($block->getId() == 63 || $block->getId() == 68){

			$position = new Vector3($block->getX(), $block->getY(), $block->getZ());
			$sign = $block->getLevel()->getTile($position);

			// if action is TAP with 'left click'
			if($event->getAction() === 0){

				if(isset($this->shopCreationQueue[$player->getName()])){ // CHECK IF EXISTS SHOP CREATION QUEUE

					$shopInfos = $this->shopCreationQueue[$player->getName()];
					if(!$shopInfos['official']){

						$chest = false;
						$sides = $block->getAllSides();
						foreach($sides as $side){
							if($side->getId() == 54){
								$chest = true;
							}
						}
						if(!$chest){
							$player->addTitle("\n", "§c".$this->translator->get('SIGN_NEEDS_TO_BE_CLOSE_CHEST'), 20, 2*20, 20);
							return false;
						}

					}

					$shopTypeString = $shopInfos['type'] ? "§cComprar" : "§aVender";
					if($shopInfos['official']){
						$line1 = "§d[".$this->translator->get('OFFICIAL_SHOP')."]";
					}else{
						$line1 = "§b[".$player->getName()."]";
					}
					$line2 = $shopTypeString." §7".$shopInfos['qtd'];
					$line3 = "§b".substr($shopInfos['itemName'], 0, 12);
					$line4 = "§fPor §e$".$shopInfos['value']." §8[".$shopInfos['itemID']."]";

					$sign->setText($line1, $line2, $line3, $line4);


					unset($this->shopCreationQueue[$player->getName()]);

					return true;

				}else{ // IF THERE ISN'T SHOP CREATION QUEUE, THEN USE THE SHOP
					
					if(preg_match('/(?:(?:§b|§d)\[([\w ]+)\])/i', $sign->getLine(0), $signData)){

						$isOfficial = false;
						$shopOwner = $signData[1];

						if($shopOwner != $this->translator->get('OFFICIAL_SHOP')){
							$defaultChest = false;
							$sides = $block->getAllSides();
							foreach($sides as $side){
								if($side->getId() == 54){
									$defaultChest = $side->getLevel()->getTile(new Vector3($side->getX(), $side->getY(), $side->getZ()));
									break;
								}
							}

							if(!$defaultChest || !($defaultChest instanceof Chest)){
								$player->addTitle("\n", "§c".$this->translator->get('NO_CHEST_FOUND'), 20, 2*20, 20);
								return false;
							}

							$chestContent = $defaultChest->getInventory();

						}else{
							$isOfficial = true;
						}
						
						$playerContent = $player->getInventory();


						if(strtolower($shopOwner) == strtolower($player->getName())){
							return false;
						}

						$buyString = $this->translator->get('BUY');
						$sellString = $this->translator->get('SELL');
						$forString = $this->translator->get('FOR');

						// DECODE SIGN LINES WITH REGEX

						if(preg_match("/(?:".$buyString.")[ ](?:§7)([\d]+)/", $sign->getLine(1), $signData)){
							$qtd = $signData[1];
							$shopType = true;
						}elseif(preg_match("/(?:".$sellString.")[ ](?:§7)([\d]+)/", $sign->getLine(1), $signData)){
							$qtd = $signData[1];
							$shopType = false;
						}else{
							return false;
						}

						if(preg_match('/(?:'.$forString.')[ ](?:§e\$)([\d]+([.][\d]+)?)/', $sign->getLine(3), $signData)){
							$price = $signData[1];
						}else{
							return false;
						}

						if(preg_match("/(?:§8\[)((?:[\d]+)(?:[:][\w]+)?)/", $sign->getLine(3), $signData)){
							$itemID = intval($signData[1]);
						}else{
							return false;
						}

						$item = Item::get($itemID, 0, $qtd);
						$itemName = $item->getName();
						
						$priceString = "$".$price;


						// CHECK IF 'ONECLICK' FEATURE IS ENABLED FOR THIS SHOP SIGN AND SESSION
						// ALSO CHECK IF ITEM IN THE SHOP IS THE SAME, TO AVOID FRAUDS
						if($this->isEnabledOneClick($player->getName(), $block, $itemID)){
							
							if(!$isOfficial){
								if($this->checkInventories($player, $playerContent, $chestContent, $item, $shopType)){

									if($this->checkWallets($player, $player->getName(), $shopOwner, $price, $shopType)){

										// $shopType:
										// True = Buying Shop | False = Selling Shop
										if($shopType){
											$this->economy->transferMoney($player->getName(), $shopOwner, 'SkyCoins', $price);
											$chestContent->removeItem($item);
											$playerContent->addItem($item);
											$player->addTitle("\n", "§a".$this->translator->get('YOU_BOUGHT', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
										}else{
											$this->economy->transferMoney($shopOwner, $player->getName(), 'SkyCoins', $price);
											$playerContent->removeItem($item);
											$chestContent->addItem($item);
											$player->addTitle("\n", "§a".$this->translator->get('YOU_SOLD', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
										}
									}
								}
							}else{

								// $shopType:
								// True = Buying Shop | False = Selling Shop
								if($shopType){
									if($playerContent->canAddItem($item)){
										if($this->economy->getWallet($player->getName(), 'SkyCoins') >= $price){
											$this->economy->removeMoney($player->getName(), 'SkyCoins', $price);
											$playerContent->addItem($item);
											$player->addTitle("\n", "§a".$this->translator->get('YOU_BOUGHT', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
										}
									}else{
										$player->addTitle("\n", "§c".$this->translator->get('FULL_INVENTORY'), 20, 2*20, 20);
									}
								}else{
									if($playerContent->contains($item)){
										if($this->economy->getWallet($player->getName(), 'SkyCoins') >= $price){
											$this->economy->giveMoney($player->getName(), 'SkyCoins', $price);
											$playerContent->removeItem($item);
											$player->addTitle("\n", "§a".$this->translator->get('YOU_SOLD', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
										}
									}else{
										$player->addTitle("\n", "§c".$this->translator->get('YOU_DO_NOT_HAVE_ITEM', ["§7".$qtd."x", "§b".$itemName."§c"]), 20, 2*20, 20);
									}
								}
							}

						}else{

							if($isOfficial){
								$formTitle = "§d".$this->translator->get('OFFICIAL_SHOP');
							}else{
								$formTitle = $this->translator->get('FORM_SHOP_USING_TITLE', ["§b".$shopOwner]);
							}
							$form = $this->skyforms->createCustomForm($formTitle);

							$actionLabel = $shopType ? $this->translator->get('FORM_SHOP_BUYING_LABEL') : $this->translator->get('FORM_SHOP_SELLING_LABEL');
							$form->addLabel($actionLabel);
							$form->addLabel("§7".$qtd."x §b".$itemName);
							$form->addLabel("§7".$forString.": §e $".$price);

							$form->addLabel("\n§7".$this->translator->get('FORM_SHOP_ONECLICK_DESCRIPTION', ["§aOneClick§7"]));
							$form->addToggle($this->translator->get('FORM_SHOP_ENABLE_ONECLICK', ["§aOneClick§r"]), false);
							$form->addLabel("§8".$this->translator->get('FORM_SHOP_PRESS_ESC_OR_X'));

							$form->sendTo($player, function($response) use (&$player, &$shopOwner, &$block, &$item, &$shopType, &$playerContent, &$chestContent, &$price){
								$qtd = $item->getCount();
								$itemName = $item->getName();
								$priceString = "$".$price;
								
								// CHECK IF SHOP ISN'T OFFICIAL (COMPARING NAMES BECAUSE ITS INSIDE CLOSURE)
								if($shopOwner != $this->translator->get('OFFICIAL_SHOP')){
									if($this->checkInventories($player, $playerContent, $chestContent, $item, $shopType)){

										if($this->checkWallets($player, $player->getName(), $shopOwner, $price, $shopType)){

											// $shopType:
											// True = Buying Shop | False = Selling Shop
											if($shopType){
												$this->economy->transferMoney($player->getName(), $shopOwner, 'SkyCoins', $price);
												$chestContent->removeItem($item);
												$playerContent->addItem($item);
												$player->addTitle("\n", "§a".$this->translator->get('YOU_BOUGHT', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
											}else{
												$this->economy->transferMoney($shopOwner, $player->getName(), 'SkyCoins', $price);
												$playerContent->removeItem($item);
												$chestContent->addItem($item);
												$player->addTitle("\n", "§a".$this->translator->get('YOU_SOLD', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
											}
											

											if($response[$this->translator->get('FORM_SHOP_ENABLE_ONECLICK', ["§aOneClick§r"])]){
												$player->sendTip($this->translator->get('YOU_ENABLED_ONECLICK', ["§aOneClick§r"]));
												$this->enableOneClick($player->getName(), $block, $item->getId());
											}
										}
										
									}
								}else{

									// IF SHOP IS OFFICIAL THEN THERES NO CHEST VERIFICATION AND NO MONEY TRANSFERS, ONLY MONEY GIVES AND REMOVES

									// $shopType:
									// True = Buying Shop | False = Selling Shop
									if($shopType){
										if($playerContent->canAddItem($item)){
											if($this->economy->getWallet($player->getName(), 'SkyCoins') >= $price){
												$this->economy->removeMoney($player->getName(), 'SkyCoins', $price);
												$playerContent->addItem($item);
												$player->addTitle("\n", "§a".$this->translator->get('YOU_BOUGHT', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
											}
										}else{
											$player->addTitle("\n", "§c".$this->translator->get('FULL_INVENTORY'), 20, 2*20, 20);
										}
									}else{
										if($playerContent->contains($item)){
											if($this->economy->getWallet($player->getName(), 'SkyCoins') >= $price){
												$this->economy->giveMoney($player->getName(), 'SkyCoins', $price);
												$playerContent->removeItem($item);
												$player->addTitle("\n", "§a".$this->translator->get('YOU_SOLD', ["§7".$qtd."x", "§b".$itemName."§a", "§e".$priceString."§a"]), 20, 2*20, 20);
											}
										}else{
											$player->addTitle("\n", "§c".$this->translator->get('YOU_DO_NOT_HAVE_ITEM', ["§7".$qtd."x", "§b".$itemName."§c"]), 20, 2*20, 20);
										}
									}

									if($response[$this->translator->get('FORM_SHOP_ENABLE_ONECLICK', ["§aOneClick§r"])]){
										$player->sendTip($this->translator->get('YOU_ENABLED_ONECLICK', ["§aOneClick§r"]));
										$this->enableOneClick($player->getName(), $block, $item->getId());
									}

								}
								
								
							});

						}
					}

				}
			}

		}
	}


	/** 
    * Check shop owner's and recipient's inventory deppending of shop type (selling or buying)
    * TODO: Add support to check item with meta
    * @access public 
    * @param pocketmine\Player $player
    * @param pocketmine\inventory\Inventory $playerInv
    * @param pocketmine\inventory\Inventory $chestInv
    * @param pocketmine\item\Item $item
    * @param Bool $shopType
    * @return Bool
    */
	public function checkInventories(Player $player, $playerInv, $chestInv, $item, $shopType){
		$qtd = $item->getCount();
		$itemName = $item->getName();

		// $shopType:
		// True = Buying Shop | False = Selling Shop
		if($shopType){
			if(!$chestInv->contains($item)){
				$player->addTitle("\n", "§c".$this->translator->get('OUT_OF_STOCK'), 20, 2*20, 20);
				return false;
			}

		    if(!$playerInv->canAddItem($item)){
				$player->addTitle("\n", "§c".$this->translator->get('FULL_INVENTORY'), 20, 2*20, 20);
				return false;
		    }
		    return true;
		}else{
			if(!$playerInv->contains($item)){
				$player->addTitle("\n", "§c".$this->translator->get('YOU_DO_NOT_HAVE_ITEM', ["§7".$qtd."x", "§b".$itemName."§c"]), 20, 2*20, 20);
				return false;
			}

		    if(!$chestInv->canAddItem($item)){
				$player->addTitle("\n", "§c".$this->translator->get('FULL_CHEST'), 20, 2*20, 20);
				return false;
		    }
		    return true;
		}
	}


	/** 
    * Check shop owner's and recipient's inventory deppending of shop type (selling or buying)
    * TODO: remove String $playerName and use $player->getName() instead
    * @access public 
    * @param pocketmine\Player $player
    * @param String $playerName
    * @param String $ownerName
    * @param Float $price
    * @param Bool $shopType
    * @return Bool
    */
	public function checkWallets(Player $player, $playerName, $ownerName, $price, $shopType){
		if($shopType){
			if($this->economy->getWallet($playerName, 'SkyCoins') < $price){
				$player->addTitle("\n", "§c".$this->translator->get('YOU_DONT_HAVE_MONEY_ENOUGH'), 20, 2*20, 20);
				return false;
			}
			return true;
		}else{
			if($this->economy->getWallet($ownerName, 'SkyCoins') < $price){
				$player->addTitle("\n", "§c".$this->translator->get('OWNER_DONT_HAVE_MONEY_ENOUGH', [$ownerName]), 20, 2*20, 20);
				return false;
			}
			return true;
		}
	}


	/** 
    * Enabling OneClick Feature
    * TODO: Add support to item with meta
    * @access public
    * @param String $playerName
    * @param pocketmine\block\Block $block
    * @param Int $itemID
    * @return void
    */
	public function enableOneClick($playerName, $block, $itemID){
		$posString = $block->getX().";".$block->getX().";".$block->getZ();

		$this->oneClicks[$playerName][] = array(
			'pos' => $posString,
			'itemID' => $itemID
		);
	}


	/** 
    * Check if OneClick Feature is enabled and if the shop is the same since OneClick was enabled
    * @access public
    * @param String $playerName
    * @param pocketmine\block\Block $block
    * @param Int $itemID
    * @return Bool
    */
	public function isEnabledOneClick($playerName, $block, $itemID){
		$posString = $block->getX().";".$block->getX().";".$block->getZ();

		if(isset($this->oneClicks[$playerName])){
			foreach ($this->oneClicks[$playerName] as $instance){
				if($instance['pos'] == $posString && $instance['itemID'] == $itemID){
					return true;
				}
			}
			return false;
		}else{
			return false;
		}
	}

	/** 
    * Check if OneClick Feature is enabled and if the shop is the same since OneClick was enabled
    * @access public
    * @param String $playerName
    * @param pocketmine\block\Block $block
    * @param Int $itemID
    * @return Bool
    */
	public function disableOneClick($playerName){
		if(isset($this->oneClicks[$playerName])) unset($this->oneClicks[$playerName]);
	}


	/** 
    * Disable any OneClick if player has enabled it (end of session)
    * @access public
    * @param pocketmine\event\player\PlayerQuitEvent $event
    * @return void
    */
	public function onQuit(PlayerQuitEvent $event){
		$player = $event->getPlayer();

		$this->disableOneClick($player->getName());

	}

	/** 
    * Returns selected language in config.yml
    * @access public
    * @return String
    */
	public function getLanguage(){
		return $this->config->get('Language');
	}

}