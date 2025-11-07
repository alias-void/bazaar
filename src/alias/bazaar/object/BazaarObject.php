<?php

namespace alias\bazaar\object;

use pocketmine\item\Item;
use pocketmine\item\ItemFactoryLegacy;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

class BazaarObject {

    private Item $item;
    private int $amount;
    private int $currentAmount;
    private int $price;
    private int $type;
    private string $uuid;
    private string $playerName;
    private string $playerXuid;
    private string $id;

    public function __construct(string $id, string $uuid, string $playerName, string $playerXuid, Item $item, int $amount, int $price, int $type, int $currentAmount = -1) {
        $this->item = $item;
        $this->id = $id;
        $this->playerName = $playerName;
        $this->playerXuid = $playerXuid;
        $this->amount = $amount;
        $this->currentAmount = $currentAmount === -1 ? $amount : $currentAmount;
        $this->price = $price;
        $this->type = $type;
        $this->uuid = $uuid;
    }

    // Getters
    public function getUUID(): string { return $this->uuid; }
    public function getPlayerName(): string { return $this->playerName; }
    public function getPlayerXuid(): string { return $this->playerXuid; }
    public function getId(): string { return $this->id; }
    public function getItem(): Item { return $this->item; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrentAmount(): int { return $this->currentAmount; }
    public function getPrice(): int { return $this->price; }
    public function getType(): int { return $this->type; }
    public function setCurrentAmount(int $currentAmount): void { $this->currentAmount = $currentAmount; }

    // Encode the entire item to Base64
    private static function encodeItem(Item $item): string {
        $root = new TreeRoot($item->nbtSerialize());
        return base64_encode((new BigEndianNbtSerializer())->write($root));
    }

    // Decode Base64 back to an item
    private static function decodeItem(string $b64): Item {
        $binary = base64_decode($b64);
        $tag = (new BigEndianNbtSerializer())->read($binary)->mustGetCompoundTag();
        return Item::nbtDeserialize($tag);
    }

    // Convert BazaarObject to array
    public function toArray(): array {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'playerName' => $this->playerName,
            'playerXuid' => $this->playerXuid,
            'item' => self::encodeItem($this->item),
            'amount' => $this->amount,
            'currentAmount' => $this->currentAmount,
            'price' => $this->price,
            'type' => $this->type
        ];
    }

    // Create BazaarObject from array
    public static function fromArray(array $data): self {
        $item = self::decodeItem($data['item']);
        return new self(
            $data['id'],
            $data['uuid'],
            $data['playerName'],
            $data['playerXuid'],
            $item,
            $data['amount'] ?? 1,
            $data['price'] ?? 0,
            $data['type'] ?? 0,
            $data['currentAmount'] ?? -1
        );
    }
}
