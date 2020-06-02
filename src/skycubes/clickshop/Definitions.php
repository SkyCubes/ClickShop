<?php

namespace skycubes\clickshop;
use skycubes\clickshop\ClickShop;
use \Exception;

class Definitions{

	protected const LANG_PATH = 'lang';

	private $plugin;

	public function __construct(ClickShop $plugin){

		$this->plugin = $plugin;

	}

	public function getLangPath(string $language){

		return $this->plugin->getDataFolder().self::LANG_PATH.'/'.$language.'.json';

	}
	public function getSQLitePath(string $sqlitedb){

		return $this->plugin->getDataFolder().'/'.$sqlitedb;

	}

	public function getDef($def){

		return constant('self::'.$def);

	}

}

?>