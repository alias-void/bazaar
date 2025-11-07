<?php

declare(strict_types=1);

namespace alias\bazaar;

use alias\bazaar\api\BazaarAPI;
use alias\bazaar\gui\MainMenu;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use alias\bazaar\libs\muqsit\invmenu\InvMenuHandler;

class Bazaar extends PluginBase {

    private BazaarAPI $bazaarAPI;
    /** @var array<string, array{icon: Item, items: Item[]}> */
    private array $categories = [];
    private static self $instance;

    public static function getInstance(): self {
        return self::$instance;
    }

    public function onEnable(): void {
        self::$instance = $this;

        $this->initConfig();
        $this->bazaarAPI = new BazaarAPI();

        $economyPluginName = $this->getConfig()->get("economy", "BedrockEconomy");
        $economyPlugin = $this->getServer()->getPluginManager()->getPlugin($economyPluginName);

        if ($economyPlugin === null || !$economyPlugin->isEnabled()) {
            $this->getLogger()->warning("Economy plugin '{$economyPluginName}' not found or is not enabled. Disabling Bazaar.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        $this->getLogger()->info("Successfully hooked into '{$economyPluginName}' for economy features.");

        if(!InvMenuHandler::isRegistered()){
		    InvMenuHandler::register($this);
	    }

        $this->bazaarAPI->loadBazaar();
        $this->loadCategories();
    }

    public function onDisable(): void {
        $this->bazaarAPI->saveBazaar();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        $cmd = strtolower($command->getName());

        switch ($cmd) {
            case "bazaar":
            case "market":
                new MainMenu($sender, $this->categories);
                break;
        }
        return true;
    }

    public function initConfig(): void {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();

        if ($this->getConfig()->get("config-version") === 2) return;

        $this->getLogger()->notice("Updating bazaar config file");
        rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.yml.old");
        $this->saveDefaultConfig();
        $this->getConfig()->reload();
        $this->getLogger()->notice("Updated config file!");
    }

    private function loadCategories(): void {
        $categoriesConfig = $this->getConfig()->get("categories", []);

        if(empty($categoriesConfig)){
            $this->getLogger()->warning("Bazaar categories are not defined in config.yml. The menu will be empty.");
            return;
        }

        foreach ($categoriesConfig as $categoryName => $categoryData) {
            $iconItem = $this->stringToItem($categoryData["icon"] ?? "barrier");
            if ($iconItem === null) {
                $this->getLogger()->warning("Invalid icon '{$categoryData["icon"]}' for category '{$categoryName}'. Using barrier as fallback.");
                $iconItem = VanillaBlocks::BARRIER()->asItem();
            }

            $items = [];
            foreach ($categoryData["items"] as $itemName) {
                $item = $this->stringToItem($itemName);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            $this->categories[$categoryName] = [
                "icon" => $iconItem,
                "items" => $items
            ];
        }
    }

    private function stringToItem(string $name): ?Item {
        $constName = strtoupper($name);
        try {
            return VanillaItems::$constName();
        } catch (\Error $e) {
            try {
                return VanillaBlocks::$constName()->asItem();
            } catch (\Error $e2) {
                $this->getLogger()->warning("Invalid item/block name in config: '$name'");
                return null;
            }
        }
    }

    public function getBazaarAPI(): BazaarAPI {
        return $this->bazaarAPI;
    }
}
