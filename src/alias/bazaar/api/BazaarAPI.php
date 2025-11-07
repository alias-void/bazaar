<?php

declare(strict_types=1);

namespace alias\bazaar\api;

use alias\bazaar\Bazaar;
use alias\bazaar\object\BazaarObject;
use pocketmine\player\Player;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use alias\bazaar\libs\SOFe\AwaitGenerator\Await;
use pocketmine\scheduler\ClosureTask;
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use onebone\economyapi\EconomyAPI;

class BazaarAPI {

    public const SELL_OFFER = 0;
    public const BUY_ORDER = 1;

    /** @var array<string, array<string, Bazaa rObject>> */
    private array $listings = [];
    private static array $balances = [];

    public function __construct() {}

    public function loadBazaar(): void {
        $folderPath = rtrim(Bazaar::getInstance()->getDataFolder(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'players' . DIRECTORY_SEPARATOR;
        @mkdir($folderPath, 0777, true);

        $playerFiles = glob($folderPath . '*.yml');
        Bazaar::getInstance()->getLogger()->info("Loading bazaar data from: {$folderPath}");
        Bazaar::getInstance()->getLogger()->info("Found " . count($playerFiles) . " player bazaar files.");

        if ($playerFiles === false || count($playerFiles) === 0) return;

        foreach ($playerFiles as $file) {
            try {
                $config = new Config($file, Config::YAML);
            } catch (\Throwable $e) {
                Bazaar::getInstance()->getLogger()->warning("Failed to open bazaar file {$file}: " . $e->getMessage());
                continue;
            }

            foreach ($config->getAll() as $key => $productJson) {
                if (is_array($productJson)) {
                    $arr = $productJson;
                } else {
                    $arr = json_decode((string)$productJson, true);
                }

                if (!is_array($arr)) {
                    Bazaar::getInstance()->getLogger()->warning("Invalid listing data in {$file} (key={$key}).");
                    continue;
                }

                try {
                    $obj = BazaarObject::fromArray($arr);
                } catch (\Throwable $e) {
                    Bazaar::getInstance()->getLogger()->warning("Failed to parse BazaarObject from {$file} (key={$key}): " . $e->getMessage());
                    continue;
                }

                $uuid = basename($file, ".yml");
                $this->listings[$uuid][$key] = $obj;
                Bazaar::getInstance()->getLogger()->info("Loaded {$key} for player {$uuid}");
            }
        }

        Bazaar::getInstance()->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updateBazaar();
        }), 160);
    }


    public function saveBazaar(): void {
        $folderPath = rtrim(Bazaar::getInstance()->getDataFolder(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'players' . DIRECTORY_SEPARATOR;
        @mkdir($folderPath, 0777, true);

        Bazaar::getInstance()->getLogger()->info("Saving Bazaar listings... count: " . count($this->listings));

        // Collect all players who have or had listings
        $allPlayerFiles = glob($folderPath . '*.yml') ?: [];

        $playersSaved = [];

        foreach ($this->listings as $uuid => $listing) {
            $playersSaved[] = $uuid;

            $data = [];
            foreach ($listing as $id => $product) {
                if (!($product instanceof BazaarObject)) {
                    Bazaar::getInstance()->getLogger()->warning("Invalid product type for $uuid:$id");
                    continue;
                }

                $data[$id] = json_encode($product->toArray());
            }

            $config = new Config($folderPath . $uuid . '.yml', Config::YAML);
            $config->setAll($data); // will be empty array if no listings
            $config->save();
        }

        // Clear files of players who no longer have any listings
        foreach ($allPlayerFiles as $file) {
            $uuid = basename($file, '.yml');
            if (!in_array($uuid, $playersSaved, true)) {
                $config = new Config($file, Config::YAML);
                $config->setAll([]); // overwrite with empty
                $config->save();
            }
        }
    }    

    public function getListings(): array {
        return $this->listings;
    }

    public function getPlayerListings(Player $player): array {
        $uuid = $player->getUniqueId()->toString();
        return $this->listings[$uuid] ?? [];
    }

    public function createSellOffer(Player $player, Item $item, int $amount, int $price = -1): bool {
        $uuid = $player->getUniqueId()->toString();
        $playerName = $player->getName();
        $playerXuid = $player->getXuid();
        $id = bin2hex(random_bytes(16));
        $offer = new BazaarObject($id, $uuid, $playerName, $playerXuid, $item, $amount, $price, self::SELL_OFFER);

        $this->listings[$uuid][$id] = $offer;

        return true;
    }

    public function createBuyOrder(Player $player, Item $item, int $amount, int $price = -1): bool {
        $uuid = $player->getUniqueId()->toString();
        $playerName = $player->getName();
        $playerXuid = $player->getXuid();
        $id = bin2hex(random_bytes(16));
        $order = new BazaarObject($id, $uuid, $playerName, $playerXuid, $item, $amount, $price, self::BUY_ORDER);

        $this->listings[$uuid][$id] = $order;

        return true;
    }

    public function createListing(Player $player, Item $item, int $amount, float $price, string $type): bool {
        return $type === "buy"
            ? $this->createBuyOrder($player, $item, $amount, (int)$price)
            : $this->createSellOffer($player, $item, $amount, (int)$price);
    }

    public function removeListing(string $id): void {
        foreach ($this->listings as $uuid => &$listing) {
            foreach ($listing as $key => $product) {
                if ($product->getId() === $id) {
                    unset($listing[$key]);
                    // if player has no listings, drop the array
                    if (empty($listing)) unset($this->listings[$uuid]);
                    return;
                }
            }
        }
    }

    public function getBasicPrice(Item $item): int {
        $config = Bazaar::getInstance()->getConfig();
        $itemNameKey = strtolower(str_replace(' ', '_', $item->getVanillaName()));
        $price = $config->getNested("base-prices." . $itemNameKey, 1);

        return (int)$price;
    }
    
    public function getAllSellOffers(?string $excludeUUID = null): array {
        $offers = [];

        foreach ($this->listings as $uuid => $playerListings) {
            if ($excludeUUID !== null && $uuid === $excludeUUID) continue;

            foreach ($playerListings as $listing) {
                if ($listing->getType() !== self::SELL_OFFER) continue;
                if ($listing->getCurrentAmount() <= 0) continue;

                $offers[] = $listing;
            }
        }

        return $offers;
    }

    public function getAllBuyOrders(?string $excludeUUID = null): array {
    $orders = [];

        foreach ($this->listings as $uuid => $playerListings) {
            if ($excludeUUID !== null && $uuid === $excludeUUID) continue;

            foreach ($playerListings as $listing) {
                if ($listing->getType() !== self::BUY_ORDER) continue;
                if ($listing->getCurrentAmount() <= 0) continue;

                $orders[] = $listing;
            }
        }

        return $orders;
    }


    public function getBestSellOfferPrice(Item $item, ?string $uuid = null): int {
        $best = -1;
        foreach ($this->listings as $owner => $listing) {
            if ($owner === $uuid) continue;
            foreach ($listing as $product) {
                if ($product->getType() !== self::SELL_OFFER) continue;
                if (!$product->getItem()->equals($item, false, false)) continue;
                if ($product->getCurrentAmount() <= 0) continue;
                $p = $product->getPrice();
                if ($p === -1) continue; // skip instant placeholders
                if ($best === -1 || $p < $best) $best = $p;
            }
        }
        return $best;
    }

    public function getBestBuyOrderPrice(Item $item, ?string $uuid = null): int {
        $best = -1;
        foreach ($this->listings as $owner => $listing) {
            if ($owner === $uuid) continue;
            foreach ($listing as $product) {
                if ($product->getType() !== self::BUY_ORDER) continue;
                if (!$product->getItem()->equals($item, false, false)) continue;
                if ($product->getCurrentAmount() <= 0) continue;
                $p = $product->getPrice();
                if ($p === -1) continue;
                if ($best === -1 || $p > $best) $best = $p;
            }
        }
        return $best;
    }

    public function getTopSellOffers(Item $item, int $limit = 5, ?string $excludeUUID = null): array {
        $offers = [];

        foreach ($this->listings as $uuid => $playerListings) {
            if ($excludeUUID !== null && $uuid === $excludeUUID) continue;

            foreach ($playerListings as $listing) {
                if ($listing->getType() !== self::SELL_OFFER) continue;
                if (!$listing->getItem()->equals($item, false, false)) continue;
                if ($listing->getCurrentAmount() <= 0) continue;

                $price = $listing->getPrice() === -1 ? $this->getBasicPrice($item) : $listing->getPrice();
                $offers[] = [
                    $price,
                    $listing->getCurrentAmount(),
                    $uuid
                ];
            }
        }

        // Sort by price ascending (cheapest first)
        usort($offers, fn($a, $b) => $a[0] <=> $b[0]);

        return array_slice($offers, 0, $limit);
    }

    public function getTopBuyOrders(Item $item, int $limit = 5, ?string $excludeUUID = null): array {
        $orders = [];

        foreach ($this->listings as $uuid => $playerListings) {
            if ($excludeUUID !== null && $uuid === $excludeUUID) continue;

            foreach ($playerListings as $listing) {
                if ($listing->getType() !== self::BUY_ORDER) continue;
                if (!$listing->getItem()->equals($item, false, false)) continue;
                if ($listing->getCurrentAmount() <= 0) continue;

                $price = $listing->getPrice() === -1 ? $this->getBasicPrice($item) : $listing->getPrice();
                $orders[] = [
                    $price,
                    $listing->getCurrentAmount(),
                    $uuid
                ];
            }
        }

        // Sort by price descending (highest first)
        usort($orders, fn($a, $b) => $b[0] <=> $a[0]);

        return array_slice($orders, 0, $limit);
    }

    private function processOrderWithBalance(BazaarObject $order, int $balance): void {
        $orderItem = $order->getItem();
        $orderPrice = $order->getPrice();
        $orderUUID = $order->getUUID();

        foreach ($this->listings as $uuid => $playerListings) {
            if ($orderUUID === $uuid) continue;

            foreach ($playerListings as $product) {
                $productItem = $product->getItem();
                $productPrice = $product->getPrice();
                $productUUID = $product->getUUID();
                $productType = $product->getType();
                $productAmount = $product->getCurrentAmount();
                $orderAmount = $order->getCurrentAmount();

                if ($orderAmount <= 0) return; // Order is already filled

                if (!$orderItem->equals($productItem, false, false) || $productType !== self::SELL_OFFER) continue;

                $pricesMatch = $orderPrice === $productPrice ||
                    ($productPrice === -1 && $orderPrice === $this->getBestBuyOrderPrice($orderItem, $productUUID)) ||
                    ($orderPrice === -1 && $productPrice === $this->getBestSellOfferPrice($orderItem, $orderUUID));

                if ($pricesMatch) {
                    $amount = $productAmount >= $orderAmount ? $orderAmount : $productAmount;
                    $cost = $productPrice;
                    $cost = $orderPrice !== -1 && $productPrice === -1 ? $orderPrice : $cost;
                    $cost = $orderPrice === -1 && $productPrice === -1 ? $this->getBasicPrice($productItem) : $cost;

                    $totalCost = $cost;
                    $buyableAmount = $amount;
                    if ($balance < $cost) {
                        continue;
                    } elseif ($balance / $cost < $amount) {
                        $buyableAmount = (int)($balance / $cost);
                        $totalCost = $buyableAmount * $cost;
                    }else{
                        $totalCost = $amount * $cost;
                    }

                    $this->addMoney($product, $totalCost);
                    $this->deductMoney($order, $totalCost);

                    $balance -= $totalCost; // Update balance for next potential transaction in this loop

                    $OAmount = $productAmount >= $buyableAmount ? $orderAmount - $buyableAmount : $buyableAmount - $productAmount;
                    $PAmount = $productAmount >= $buyableAmount ? $productAmount - $buyableAmount : 0;

                    $order->setCurrentAmount($OAmount);
                    $product->setCurrentAmount($PAmount);

                    if ($PAmount === 0) $this->removeListing($product->getId());
                }
            }
        }
    }

    public function updateSellOffers(BazaarObject $order): void {
        self::checkBalance($order, fn(int $balance) => $this->processOrderWithBalance($order, $balance));
    }

    public function updateBazaar(): void {
        $orders = $this->getAllBuyOrders();

        foreach($orders as $order) {
            $this->updateSellOffers($order);
        }
    }

    // economy integration placeholders (implement according to your server economy)
    public function deductMoney(BazaarObject $object, int $amount): void {
        $config = Bazaar::getInstance()->getConfig();
        
        switch($config->get("economy")) {
            case "Bedrock-Economy":
                BedrockEconomyAPI::CLOSURE()->subtract(
                    xuid: "{$object->getPlayerXuid()}",
                    username: "{$object->getPlayerName()}",
                    amount: $amount,
                    decimals: 00,
                    onSuccess: static function (): void {
                        echo 'Balance updated successfully.';
                    },
                    onError: static function (SQLException $exception): void {
                        // if ($exception instanceof RecordNotFoundException) {
                        //     echo 'Account not found';
                        //     return;
                        // }

                        // if ($exception instanceof InsufficientFundsException) {
                        //     echo 'Insufficient funds';
                        //     return;
                        // }

                        // echo 'An error occurred while updating the balance.';
                    }
                );
                break;
            case "EconomyAPI":
                EconomyAPI::getInstance()->reduceMoney($object->getPlayerName(), $amount);
                break;
        }
    }

    public function addMoney(BazaarObject $object, int $amount): void {
        $config = Bazaar::getInstance()->getConfig();
        
        switch($config->get("economy")) {
            case "Bedrock-Economy":
                BedrockEconomyAPI::CLOSURE()->add(
                    xuid: "{$object->getPlayerXuid()}",
                    username: "{$object->getPlayerName()}",
                    amount: $amount,
                    decimals: 00,
                    onSuccess: static function (): void {
                        // echo 'Balance updated successfully.';
                    },
                    onError: static function (SQLException $exception): void {
                        // if ($exception instanceof RecordNotFoundException) {
                            //     // echo 'Account not found';
                            //     return;
                            // }
                            
                            // echo 'An error occurred while updating the balance.';
                        }
                    );
                    break;
            case "EconomyAPI":
                EconomyAPI::getInstance()->addMoney($object->getPlayerName(), $amount);
                break;
                }
            }
            
    public static function checkBalance(BazaarObject $object, callable $callback): void {
        $config = Bazaar::getInstance()->getConfig();
                
        switch($config->get("economy")) {
            case "Bedrock-Economy":
                BedrockEconomyAPI::CLOSURE()->get(
                    xuid: $object->getPlayerXuid(),
                    username: $object->getPlayerName(),
                    onSuccess: function(array $result) use ($callback): void {
                        $callback((int)$result["amount"]);
                    },
                    onError: function() use ($callback): void {
                        $callback(0);
                    }
                );
                break;
            case "EconomyAPI":
                $balance = EconomyAPI::getInstance()->myMoney($object->getPlayerName());
                $callback($balance !== false ? (int)$balance : 0);
                break;
        }
    }

}
