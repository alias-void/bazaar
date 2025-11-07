<?php

declare(strict_types=1);

namespace alias\bazaar\gui;
use pocketmine\player\Player;
use pocketmine\inventory\Inventory;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use alias\bazaar\gui\pages\ProductView;
use alias\bazaar\gui\pages\PlayerListings;
use alias\bazaar\libs\muqsit\invmenu\InvMenu;
use alias\bazaar\libs\muqsit\invmenu\transaction\InvMenuTransaction;
use alias\bazaar\libs\muqsit\invmenu\transaction\InvMenuTransactionResult;

class MainMenu
{
    public const MAIN_MENU = 0;
    public const PRODUCT_VIEW = 1;
    public const PLAYER_LISTINGS = 2;

    private Player $player;
    private InvMenu $menu;
    private int $CURRENT_MENU = self::MAIN_MENU;

    private string $currentCategory = "";
    private int $currentPage = 0;
    private array $categories = [];

    /** @var int[] */
    private array $itemSlots = [];

    public function __construct(Player $player, array $categories)
    {
        $this->player = $player;
        $this->categories = $categories;
        $this->currentCategory = (string)array_key_first($this->categories);

        $this->menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST, null);
        $this->menu->setName("Bazaar");
        $this->menu->setListener(fn(InvMenuTransaction $t) => $this->transactionListener($t));

        $this->Inventory($this->menu->getInventory());
        $this->display();
    }

    public function getInventory(): Inventory {
        return $this->menu->getInventory();
    }

    public function display(): void {
        $this->menu->send($this->player);
    }

    private function initSlots(): void {
        $this->itemSlots = [];
        for ($row = 1; $row <= 3; $row++) {
            for ($col = 1; $col <= 6; $col++) {
                $this->itemSlots[] = ($row * 9) + $col + 1;
            }
        }
    }

    private function transactionListener(InvMenuTransaction $transaction): InvMenuTransactionResult {
        $player = $transaction->getPlayer();
        $slot = $transaction->getAction()->getSlot();
        $item = $transaction->getItemClicked();
        $action = $transaction->getAction();
        $inv = $this->getInventory();

        if ($item->isNull() || $item->equals(VanillaBlocks::GLASS_PANE()->asItem(), false, false)) {
            return $transaction->discard();
        }

        switch ($this->CURRENT_MENU) {
            case self::MAIN_MENU:
                // Category switching
                $categorySlots = [0, 9, 18, 27, 36];
                if (in_array($slot, $categorySlots)) {
                    $categories = array_keys($this->categories);
                    $index = array_search($slot, $categorySlots);
                    if (isset($categories[$index])) {
                        $this->currentCategory = $categories[$index];
                        $this->currentPage = 0;
                        $this->Inventory($inv);
                    }
                    return $transaction->discard();
                }

                // Page navigation
                if ($slot === 45 || $slot === 53) {
                    if ($slot === 45 && $this->currentPage > 0) {
                        $this->currentPage--;
                        $this->Inventory($inv);
                    } else {
                        $categoryItems = $this->categories[$this->currentCategory]["items"];
                        $maxPage = (int)ceil(count($categoryItems) / 18) - 1;
                        if ($slot === 53 && $this->currentPage < $maxPage) {
                            $this->currentPage++;
                            $this->Inventory($inv);
                        }
                    }
                    return $transaction->discard(); // Explicitly stop here
                }

                // Player listings chest
                if ($slot === 47) {
                    $this->CURRENT_MENU = self::PLAYER_LISTINGS;
                    PlayerListings::Inventory($inv, $player, $this);
                    return $transaction->discard(); // Explicitly stop here
                }

                if (in_array($slot, $this->itemSlots)) {

                // Product clicked
                $this->CURRENT_MENU = self::PRODUCT_VIEW;
                ProductView::Inventory($item, $inv, $player, $this);
                }
                break;

            case self::PRODUCT_VIEW:
                ProductView::transactionListener($player, $item, $action, $transaction);
                if ($slot === 49) {
                    $this->CURRENT_MENU = self::MAIN_MENU;
                    $this->Inventory($inv);
                }
                break;

            case self::PLAYER_LISTINGS:
                PlayerListings::transactionListener($player, $item, $action, $transaction);
                if ($slot === 49) {
                    $this->CURRENT_MENU = self::MAIN_MENU;
                    $this->Inventory($inv);
                }
                break;
        }

        return $transaction->discard();
    }

    public function Inventory(Inventory $inv): void
    {
        $inv->clearAll();
        $this->initSlots();

        // Fillers
        $filler = VanillaBlocks::GLASS_PANE()->asItem();
        $inv->setContents(array_fill(0, 54, $filler));

        foreach($this->itemSlots as $slot) {
            $inv->setItem($slot, VanillaBlocks::AIR()->asItem());
        }

        // Category buttons
        $categorySlots = [0, 9, 18, 27, 36];
        $index = 0;
        foreach ($this->categories as $name => $data) {
            $icon = clone $data["icon"];
            $icon->setCustomName("§e" . $name);
            if ($name === $this->currentCategory) {
                $icon->setLore(["§a§lSelected"]);
            }
            if (isset($categorySlots[$index])) {
                $inv->setItem($categorySlots[$index], $icon);
            }
            $index++;
        }

        // Display items for current category
        if(!isset($this->categories[$this->currentCategory])) return;

        $category = $this->categories[$this->currentCategory]["items"];
        $perPage = 18; // 3 rows of 6
        $offset = $this->currentPage * $perPage;
        $pageItems = array_slice($category, $offset, $perPage);

        foreach ($pageItems as $i => $item) {
            if (isset($this->itemSlots[$i])) {
                $inv->setItem($this->itemSlots[$i], clone $item);
            }
        }

        // Page navigation arrows
        $inv->setItem(45, VanillaItems::ARROW()->setCustomName("§cPrevious Page"));
        $inv->setItem(53, VanillaItems::ARROW()->setCustomName("§aNext Page"));

        // Chest (player listings)
        $inv->setItem(47, VanillaBlocks::CHEST()->asItem()->setCustomName("§6Your Listings"));
    }

    public function setCurrentMenu(int $value): void {
        $this->CURRENT_MENU = $value;
    }

    public function getCurrentMenu(): int {
        return $this->CURRENT_MENU;
    }
}
