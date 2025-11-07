<?php

namespace alias\bazaar\gui\pages;

use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\inventory\Inventory;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use alias\bazaar\Bazaar;
use alias\bazaar\gui\MainMenu;
use alias\bazaar\api\BazaarAPI;
use pocketmine\scheduler\ClosureTask;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\form\Form;

class ProductView
{
    public static array $playerData = [];

    public static function Inventory(Item $item, Inventory $inv, Player $player, $mainMenu, bool $isBuyOrder = true): void {
    $inv->clearAll();

    $name = $item->getVanillaName();
    $playerName = $player->getName();
    $currentAmount = self::$playerData[$playerName]["amount"] ?? 1;

    self::$playerData[$playerName] = [
        "product" => $item,
        "amount" => $currentAmount,
        "mainMenu" => $mainMenu,
        "isBuyOrder" => $isBuyOrder
    ];

    // Header
    // $inv->setItem(4, VanillaBlocks::OAK_SIGN()->asItem()->setCustomName("§6§lProduct: §e{$name}"));

    // Product Display (Center)
    $displayItem = clone $item;
    $displayItem->setCustomName("§e{$name}");

    
    $amount = self::$playerData[$playerName]["amount"] ?? 1;

    $inv->setItem(13, $displayItem);

    // Amount Form Button (replaces increase/decrease)
    $inv->setItem(22, VanillaItems::PAPER()->setCustomName("§eSet Amount (§b{$amount}§e)"));

    // Action Buttons
    $inv->setItem(29, VanillaItems::EMERALD()->setCustomName("§aInstant Buy"));
    
    // Action Buttons
    $buyOrderButton = VanillaItems::BOOK()->setCustomName("§aCreate Buy Order");
    $sellOfferButton = VanillaItems::PAPER()->setCustomName("§cCreate Sell Offer");

    // Add lore for top offers
    $api = Bazaar::getInstance()->getBazaarAPI();

    $topSellOffers = $api->getTopSellOffers($item, 5); // top sell offers
    if (!empty($topSellOffers)) {
        $lore = ["§7Top Sell Offers:"];
        foreach ($topSellOffers as $offer) {
            [$price, $amt, $playerUUID] = $offer;
            $lore[] = "§b{$amt}x §e{$item->getVanillaName()} §7@ §a{$price} coins";
        }
        $buyOrderButton->setLore($lore);
    }

    $topBuyOrders = $api->getTopBuyOrders($item, 5); // top buy orders
    if (!empty($topBuyOrders)) {
        $lore = ["§7Top Buy Orders:"];
        foreach ($topBuyOrders as $order) {
            [$price, $amt, $playerUUID] = $order;
            $lore[] = "§b{$amt}x §e{$item->getVanillaName()} §7@ §a{$price} coins";
        }
        $sellOfferButton->setLore($lore);
    }

    $inv->setItem(30, $buyOrderButton);
    $inv->setItem(32, $sellOfferButton);

    // Other action buttons
    $inv->setItem(29, VanillaItems::EMERALD()->setCustomName("§aInstant Buy"));
    $inv->setItem(33, VanillaBlocks::REDSTONE()->asItem()->setCustomName("§cInstant Sell"));

    $inv->setItem(33, VanillaBlocks::REDSTONE()->asItem()->setCustomName("§cInstant Sell"));

    // Back Button
    $inv->setItem(49, VanillaBlocks::BARRIER()->asItem()->setCustomName("§cBack"));
    }

    public static function transactionListener(Player $player, Item $item, $action, InvMenuTransaction $transaction): InvMenuTransactionResult {
        $name = $player->getName();
        if (!isset(self::$playerData[$name])) return $transaction->discard();

        $data = &self::$playerData[$name];
        $clickedName = $item->getCustomName();

        if (str_starts_with($clickedName, "§eSet Amount")) {
            $player->removeCurrentWindow();
            Bazaar::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, &$data): void {
                self::openAmountForm($player, $data);
            }), 3);
            return $transaction->discard();
        }

        switch ($clickedName) {
            case "§aInstant Buy":
                self::handleCreateOrder($player, $data, true, -1);
                break;

            case "§cInstant Sell":
                self::handleCreateOrder($player, $data, false, -1);
                break;

            case "§aCreate Buy Order":
                $player->removeCurrentWindow();
                Bazaar::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, &$data): void {
                    self::openPriceForm($player, $data, true);
                }), 3);
                break;

            case "§cCreate Sell Offer":
                $player->removeCurrentWindow();
                Bazaar::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, &$data): void {
                    self::openPriceForm($player, $data, false);
                }), 3);
                break;

            case "§cBack":
                $data['mainMenu']->setCurrentMenu(MainMenu::MAIN_MENU);
                $data["mainMenu"]->Inventory($data["mainMenu"]->getInventory());
                break;
        }

        return $transaction->discard();
    }

    // Amount Form
    private static function openAmountForm(Player $player, array &$data): void {
        $form = new class($data) implements Form {
            private array $data;
            public function __construct(array &$data) { $this->data = &$data; }
            public function jsonSerialize(): array {
                return [
                    "type" => "custom_form",
                    "title" => "§6Set Amount",
                    "content" => [[
                        "type" => "input",
                        "text" => "Enter the amount:",
                        "placeholder" => "e.g. 64",
                        "default" => (string)$this->data["amount"]
                    ]]
                ];
            }
            public function handleResponse(Player $player, $response): void {
                $mainMenu = $this->data["mainMenu"];
                if ($response === null) {
                    $mainMenu->display();
                    ProductView::Inventory($this->data["product"], $mainMenu->getInventory(), $player, $mainMenu);
                    return;
                }
                $amount = max(1, (int)$response[0]);
                ProductView::$playerData[$player->getName()]["amount"] = $amount;
                $player->sendMessage("§bAmount set to §e{$amount}");

                // Re-open and re-draw the product view
                $mainMenu->display();
                $isBuy = ProductView::$playerData[$player->getName()]["isBuyOrder"] ?? true;
                ProductView::Inventory($this->data["product"], $mainMenu->getInventory(), $player, $mainMenu, $isBuy);
            }
        };
        $player->sendForm($form);
    }

    // Price Form
    private static function openPriceForm(Player $player, array &$data, bool $isBuy): void {
        $type = $isBuy ? "Buy Order" : "Sell Offer";
        $form = new class($data, $isBuy, $type) implements Form {
            private array $data; private bool $isBuy; private string $type;
            public function __construct(array &$data, bool $isBuy, string $type) {
                $this->data = &$data; $this->isBuy = $isBuy; $this->type = $type;
            }
            public function jsonSerialize(): array {
                return [
                    "type" => "custom_form",
                    "title" => "§6Create {$this->type}",
                    "content" => [[
                        "type" => "input",
                        "text" => "Enter price per item:",
                        "placeholder" => "e.g. 100",
                        "default" => "1"
                    ]]
                ];
            }
            public function handleResponse(Player $player, $response): void {
                if ($response === null) { $this->data["mainMenu"]->display(); return; }
                $price = (float)$response[0];
                ProductView::handleCreateOrder($player, $this->data, $this->isBuy, $price);
            }
        };
        $player->sendForm($form);
    }

    public static function handleCreateOrder(Player $player, array &$data, bool $isBuy, float $price): void {
        $product = clone $data["product"];
        $amount = (int)$data["amount"];
        $mainMenu = $data["mainMenu"];
        $api = Bazaar::getInstance()->getBazaarAPI();

        if (count($api->getPlayerListings($player)) >= 10) {
            $player->sendMessage("§cYou have reached the maximum of 10 listings.");
            $mainMenu->setCurrentMenu(MainMenu::MAIN_MENU);
            $mainMenu->Inventory($mainMenu->getInventory());
            return;
        }

        $product->setCount($amount);

        if ($isBuy) {
            $api->createBuyOrder($player, $product, $amount, (int)$price);
            $player->sendMessage($price === -1
                ? "§a[Instant Buy] §f{$amount}x §e{$product->getVanillaName()}§f purchased at best price!"
                : "§a[Buy Order] §f{$amount}x §e{$product->getVanillaName()}§f at §b{$price} coins each!");

            $mainMenu->setCurrentMenu(MainMenu::MAIN_MENU);
            $mainMenu->Inventory($mainMenu->getInventory());
        } else {
            // ✅ Check if player has enough items
            if (!$player->getInventory()->contains($product, $amount)) {
                $player->sendMessage("§cYou do not have §e{$amount}x §f{$product->getVanillaName()} §cto sell!");
            
                $mainMenu->setCurrentMenu(MainMenu::MAIN_MENU);
                $mainMenu->Inventory($mainMenu->getInventory());
                return;
            }

            // ✅ Remove items from inventory
            $player->getInventory()->removeItem($product);

            $api->createSellOffer($player, $product, $amount, (int)$price);
            $player->sendMessage($price === -1
                ? "§c[Instant Sell] §f{$amount}x §e{$product->getVanillaName()}§f sold at best price!"
                : "§c[Sell Offer] §f{$amount}x §e{$product->getVanillaName()}§f at §b{$price} coins each!");
        
            $mainMenu->setCurrentMenu(MainMenu::MAIN_MENU);
            $mainMenu->Inventory($mainMenu->getInventory());
        }
    }

}
