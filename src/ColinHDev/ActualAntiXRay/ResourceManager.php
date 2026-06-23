<?php

namespace ColinHDev\ActualAntiXRay;

use pocketmine\block\BlockTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

class ResourceManager {
    use SingletonTrait;

    private const DEFAULT_REPLACEABLE_BLOCKS = [
        "minecraft:stone",
        "minecraft:dirt",
        "minecraft:gravel"
    ];
    private const DEFAULT_REPLACING_BLOCKS = [
        "minecraft:coal_ore",
        "minecraft:iron_ore",
        "minecraft:lapis_lazuli_ore",
        "minecraft:redstone_ore",
        "minecraft:gold_ore",
        "minecraft:diamond_ore",
        "minecraft:emerald_ore"
    ];

    private bool $default;
    private int $revertRadius = 2;
    /** @var int[] */
    private array $replaceableBlockStateIds = [];
    /** @var int[] */
    private array $replacingBlockStateIds = [];
    /** @var array<string, true> */
    private array $worlds = [];

    public function __construct() {
        ActualAntiXRay::getInstance()->saveResource("config.yml");
        $config = new Config(ActualAntiXRay::getInstance()->getDataFolder() . "config.yml", Config::YAML);
        $mode = $config->get("mode", "blacklist");
        if ($mode === "blacklist") {
            $this->default = true;
        } else {
            $this->default = false;
        }
        $worlds = $config->get("worlds", []);
        if (is_array($worlds)) {
            foreach($worlds as $worldName) {
                if (is_string($worldName)) {
                    $this->worlds[$worldName] = true;
                }
            }
        }
        $revertRadius = $config->get("revert-radius", 2);
        if (is_int($revertRadius) && $revertRadius >= 0) {
            $this->revertRadius = $revertRadius;
        }
        $this->replaceableBlockStateIds = $this->loadBlockStateIds($config, "replaceable-blocks", self::DEFAULT_REPLACEABLE_BLOCKS);
        $this->replacingBlockStateIds = $this->loadBlockStateIds($config, "replacing-blocks", self::DEFAULT_REPLACING_BLOCKS);
    }

    public function isEnabledForWorld(string $worldName) : bool {
        return
            ($this->default && !isset($this->worlds[$worldName])) ||
            (!$this->default && isset($this->worlds[$worldName]));
    }

    public function getRevertRadius() : int {
        return $this->revertRadius;
    }

    /**
     * @param string[] $default
     * @return int[]
     */
    private function loadBlockStateIds(Config $config, string $key, array $default) : array {
        $blocks = $config->get($key, $default);
        if (!is_array($blocks)) {
            $blocks = $default;
        }
        $stateIds = $this->convertBlockNamesToStateIds($blocks, $key);
        if ($stateIds === []) {
            $stateIds = $this->convertBlockNamesToStateIds($default, $key);
        }
        return $stateIds;
    }

    /**
     * @return int[]
     */
    private function convertBlockNamesToStateIds(array $blocks, string $key) : array {
        $stateIds = [];
        $parser = StringToItemParser::getInstance();
        foreach($blocks as $blockName) {
            if (!is_string($blockName)) {
                ActualAntiXRay::getInstance()->getLogger()->warning($key . " contains a non-string block entry");
                continue;
            }
            $item = $parser->parse($blockName);
            if ($item === null) {
                ActualAntiXRay::getInstance()->getLogger()->warning($key . " contains an unknown block: " . $blockName);
                continue;
            }
            $block = $item->getBlock();
            if ($block->getTypeId() === BlockTypeIds::AIR) {
                ActualAntiXRay::getInstance()->getLogger()->warning($key . " contains a non-block item: " . $blockName);
                continue;
            }
            $stateIds[$block->getStateId()] = $block->getStateId();
        }
        return array_values($stateIds);
    }

    /**
     * @return int[]
     */
    public function getReplaceableBlockStateIds() : array {
        return $this->replaceableBlockStateIds;
    }

    /**
     * @return int[]
     */
    public function getReplacingBlockStateIds() : array {
        return $this->replacingBlockStateIds;
    }
}