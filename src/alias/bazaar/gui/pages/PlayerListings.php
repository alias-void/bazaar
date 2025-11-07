<?php

declare(strict_types=1);

namespace alias\bazaar\gui\pages;

use pocketmine\player\Player;
use pocketmine\inventory\Inventory;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use alias\bazaar\libs\muqsit\invmenu\transaction\InvMenuTransaction;
use alias\bazaar\libs\muqsit\invmenu\transaction\InvMenuTransactionResult;
use alias\bazaar\gui\MainMenu;
use alias\bazaar\Bazaar;
use alias\bazaar\api\BazaarAPI;
use alias\bazaar\object\BazaarObject;
use pocketmine\scheduler\ClosureTask;

class PlayerListings
{
    private static BazaarAPI $api;

    /** slot map: playerName => [ slot => listingId ] */
    private static array $slotMap = [];

    /** cache main menus: playerName => MainMenu */
    private static array $playerMenus = [];

    public static function init(): void {
        self::$api = Bazaar::getInstance()->getBazaarAPI();
    }

    public static function Inventory(Inventory $inv, Player $player, MainMenu $main, bool $isAction = false): void {
        self::init();
        $inv->clearAll();

        $playerName = $player->getName();
        self::$playerMenus[$playerName] = $main;
        self::$slotMap[$playerName] = [];

        $filler = VanillaBlocks::GLASS_PANE()->asItem();
        $inv->setContents(array_fill(0, 54, $filler));

        $uuid = $player->getUniqueId()->toString();
        $listings = self::$api->getListings()[$uuid] ?? [];
        $listings = array_slice($listings, 0, 10, true);

        $slots = [
            11, 12, 13, 14, 15,
            29, 30, 31, 32, 33
        ];

        foreach($slots as $slot) {
            $inv->setItem($slot, VanillaBlocks::AIR()->asItem());
        }

        $i = 0;
        foreach ($listings as $id => $listing) {
            if (!isset($slots[$i])) break;

            /** @var BazaarObject $listing */
            $item = clone $listing->getItem();

            $meta = $listing->getType() === BazaarAPI::SELL_OFFER ? "Sell Offer" : "Buy Order";
            $currentAmount = $listing->getCurrentAmount();
            $total = $listing->getAmount();

            $status = "Active";
            if ($currentAmount === 0) $status = "Complete — Claimable";
            elseif ($currentAmount === $total) $status = "Cancelable"; 

            $progress = $total - $currentAmount;
            $price = $listing->getPrice() === -1 ? "-" : $listing->getPrice();

            $lore = [
                "§7Type: §f$meta",
                "§7Price: §f" . $price,
                "§7Amount: §f{$progress}/{$total}",
                "§7Status: §f$status",
                "",
                "§eClick to interact"
            ];

            $displayName = $item->getVanillaName() . " (§e#" . substr($id, 0, 6) . "§r)";
            $item->setCustomName($displayName);
            $item->setLore($lore);

            $slot = $slots[$i];
            $inv->setItem($slot, $item);

            self::$slotMap[$playerName][$slot] = $id;
            $i++;
        }

        // Back button
        $inv->setItem(49, VanillaItems::ARROW()->setCustomName("§cBack"));

        if(!$isAction) self::scheduleDelayedRefresh($player);
    }

    public static function transactionListener(Player $player, Item $item, $action, InvMenuTransaction $transaction): InvMenuTransactionResult {
        self::init();

        $playerName = $player->getName();
        $slot = $transaction->getAction()->getSlot();

        // Back button
        if ($item->getCustomName() === "§cBack") {
            if (isset(self::$playerMenus[$playerName])) {
                $main = self::$playerMenus[$playerName];
                $main->setCurrentMenu(MainMenu::MAIN_MENU);
                $main->Inventory($main->getInventory());
            }
            return $transaction->discard();
        }

        // Find listing id by slot
        $listingId = self::$slotMap[$playerName][$slot] ?? null;
        if ($listingId === null) return $transaction->discard();

        $uuid = $player->getUniqueId()->toString();
        $listing = self::$api->getListings()[$uuid][$listingId] ?? null;
        if ($listing === null) {
            $player->sendMessage("§cListing not found.");
            if (isset(self::$playerMenus[$playerName])) {
                $main = self::$playerMenus[$playerName];
                self::Inventory($main->getInventory(), $player, $main, true);
            }
            return $transaction->discard();
        }

        self::handleListingClick($player, $listing);
        return $transaction->discard();
    }

    private static function handleListingClick(Player $player, BazaarObject $listing): void {
        self::init();
        $uuid = $player->getUniqueId()->toString();
        $current = $listing->getCurrentAmount();
        $total = $listing->getAmount();
        $playerName = $player->getName();
        $api = self::$api;

        if ($current === 0) {
            if ($listing->getType() === BazaarAPI::SELL_OFFER) {
                // $amountCoins = $listing->getPrice() * $total;
                // $api->addMoney($uuid, $amountCoins);
                // $player->sendMessage("§aYou claimed §f{$amountCoins} §acoins for your sale.");
            } else {
                $item = clone $listing->getItem();
                $item->setCount($total);
                $player->getInventory()->addItem($item);
                $player->sendMessage("§aYou claimed your bought items.");
            }

            $api->removeListing($listing->getId());
        } elseif ($current === $total) {
            if ($listing->getType() === BazaarAPI::SELL_OFFER) {
                $item = clone $listing->getItem();
                $item->setCount($total);
                $player->getInventory()->addItem($item);
                $player->sendMessage("§cListing canceled, item returned.");
            } else {
                // $refund = $listing->getPrice() * $total;
                // $api->addMoney($uuid, $refund);
                $player->sendMessage("§cBuy order canceled.");
            }

            $api->removeListing($listing->getId());
        } else {
            $player->sendMessage("§7This listing is still active.");
        }

        // Schedule a delayed refresh after interaction
        $main = self::$playerMenus[$playerName];
        self::Inventory($main->getInventory(), $player, $main, true);
    }

    private static function scheduleDelayedRefresh(Player $player): void {
        $playerName = $player->getName();

        $main = self::$playerMenus[$playerName] ?? null;
        if ($main === null) return;

        Bazaar::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, $main): void {
            if ($main->getCurrentMenu() === MainMenu::PLAYER_LISTINGS) {
                self::Inventory($main->getInventory(), $player, $main);
            }
        }), 20); // 1 second delay
    }
}
