<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\tasks;

use ColinHDev\ActualAntiXRay\utils\SubChunkExplorer;
use pmmp\encoding\ByteBufferWriter;
use pmmp\thread\ThreadSafeArray;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\ChunkRequestTask as PMMPChunkRequestTask;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function assert;
use function chr;
use function is_array;
use function mt_rand;

class ChunkRequestTask extends PMMPChunkRequestTask {

    private ThreadSafeArray $replaceableBlocks;
    private ThreadSafeArray $replacingBlocks;

    private int $worldMinY;
    private int $worldMaxY;
    private int $dimensionId;

    private string $adjacentChunks;
    private string $tiles;

    public function __construct(World $world, int $chunkX, int $chunkZ, int $dimensionId, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor) {
        parent::__construct($chunkX, $chunkZ, $dimensionId, $chunk, $promise, $compressor);
        $this->replaceableBlocks = ThreadSafeArray::fromArray([
            VanillaBlocks::STONE()->getStateId() => true,
            VanillaBlocks::DIRT()->getStateId() => true,
            VanillaBlocks::GRAVEL()->getStateId() => true
        ]);
        $this->replacingBlocks = ThreadSafeArray::fromArray([
            VanillaBlocks::COAL_ORE()->getStateId(),
            VanillaBlocks::IRON_ORE()->getStateId(),
            VanillaBlocks::LAPIS_LAZULI_ORE()->getStateId(),
            VanillaBlocks::REDSTONE_ORE()->getStateId(),
            VanillaBlocks::GOLD_ORE()->getStateId(),
            VanillaBlocks::DIAMOND_ORE()->getStateId(),
            VanillaBlocks::EMERALD_ORE()->getStateId()
        ]);

        $this->worldMinY = $world->getMinY();
        $this->worldMaxY = $world->getMaxY();
        $this->dimensionId = $dimensionId;

        $this->tiles = ChunkSerializer::serializeTiles($chunk);

        $adjacentChunks = [];
        for ($x = -1; $x <= 1; $x++) {
            for ($z = -1; $z <= 1; $z++) {
                if ($x === 0 || $z === 0) {
                    if ($x === 0 && $z === 0) {
                        continue;
                    }
                    $cx = $chunkX + $x;
                    $cz = $chunkZ + $z;
                    $temporaryChunkLoader = new class implements ChunkLoader{};
                    $world->registerChunkLoader($temporaryChunkLoader, $cx, $cz);
                    $adjacentChunks[World::chunkHash($x, $z)] = $world->loadChunk($cx, $cz);
                    $world->unregisterChunkLoader($temporaryChunkLoader, $cx, $cz);
                }
            }
        }
        $this->adjacentChunks = igbinary_serialize(
            array_map(
                static fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
                $adjacentChunks
            )) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
    }

    public function onRun() : void {
        $adjacentChunks = igbinary_unserialize($this->adjacentChunks);
        assert(is_array($adjacentChunks));
        $chunks = array_map(
            static fn(?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
            [World::chunkHash(0, 0) => $this->chunk] + $adjacentChunks
        );
        $manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
        foreach($chunks as $relativeChunkHash => $chunk) {
            if (!($chunk instanceof Chunk)) {
                continue;
            }
            World::getXZ($relativeChunkHash, $relativeChunkX, $relativeChunkZ);
            $manager->setChunk($this->chunkX + $relativeChunkX, $this->chunkZ + $relativeChunkZ, $chunk);
        }

        $explorer = new SubChunkExplorer($manager);
        for ($subChunkY = Chunk::MIN_SUBCHUNK_INDEX; $subChunkY < Chunk::MAX_SUBCHUNK_INDEX; $subChunkY++) {
            $explorer->moveToChunk($this->chunkX, $subChunkY, $this->chunkZ);
            if ($explorer->currentSubChunk instanceof SubChunk && $explorer->currentSubChunk->isEmptyFast()) {
                continue;
            }

            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y < 16; $y++) {

                        if ($subChunkY === Chunk::MIN_SUBCHUNK_INDEX && $y === 0) continue;
                        if ($subChunkY + 1 === Chunk::MAX_SUBCHUNK_INDEX && $y === 15) continue;

                        if (!$this->isBlockReplaceable($explorer, $x, $y, $z, $subChunkY)) {
                            // If the current block is not replaceable, we can increment the y coordinate by one,
                            // as we can skip the following loop which would check that block again as block below.
                            if (($subChunkY << 4) + $y !== $this->worldMinY && ($subChunkY << 4) + $y !== 0) $y++;
                            continue;
                        }

                        // We could use the random_int() function instead but since mt_rand() is faster than random_int(),
                        // we use that as it is not important if our the returned values are cryptographically secure.
                        if (mt_rand(1, 100) > 75) {
                            continue;
                        }

                        if (!$this->isBlockReplaceable($explorer, $x, $y - 1, $z, $subChunkY)) {
                            if (!(($subChunkY << 4) + $y === $this->worldMinY + 1 || ($subChunkY << 4) + $y === 1)) continue;
                        }
                        if (!$this->isBlockReplaceable($explorer, $x, $y + 1, $z, $subChunkY)) {
                            // If the block above is not replaceable, we can increment the y coordinate by two,
                            // as we can skip the following two loops which would check that block again.
                            // First, as the "main" block, then as the block below.
                            $y += 2;
                            continue;
                        }
                        if (!$this->isBlockReplaceable($explorer, $x, $y, $z - 1, $subChunkY)) continue;
                        if (!$this->isBlockReplaceable($explorer, $x, $y, $z + 1, $subChunkY)) continue;
                        if (!$this->isBlockReplaceable($explorer, $x - 1, $y, $z, $subChunkY)) continue;
                        if (!$this->isBlockReplaceable($explorer, $x + 1, $y, $z, $subChunkY)) continue;

                        $randomBlockId = $this->replacingBlocks[mt_rand(0, count($this->replacingBlocks) - 1)];
                        $explorer->moveToChunk($this->chunkX, $subChunkY, $this->chunkZ);
                        assert($explorer->currentSubChunk instanceof SubChunk);
                        $explorer->currentSubChunk->setBlockStateId($x, $y, $z, $randomBlockId);
                    }
                }
            }
        }

        $chunk = $manager->getChunk($this->chunkX, $this->chunkZ);
        assert($chunk instanceof Chunk);
        $subCount = ChunkSerializer::getSubChunkCount($chunk, $this->dimensionId);
        $converter = TypeConverter::getInstance();
        $payload = ChunkSerializer::serializeFullChunk($chunk, $this->dimensionId, $converter->getBlockTranslator(), $this->tiles);

        $stream = new ByteBufferWriter();
        PacketBatch::encodePackets($stream, [LevelChunkPacket::create(new ChunkPosition($this->chunkX, $this->chunkZ), $this->dimensionId, $subCount, false, null, $payload)]);
        $compressor = $this->compressor->deserialize();
        $this->setResult(chr($compressor->getNetworkId()) . $compressor->compress($stream->getData()));
    }

    private function isBlockReplaceable(SubChunkExplorer $explorer, int $x, int $y, int $z, int $subChunkY) : bool {
        $chunkX = $this->chunkX;
        if ($x < 0) {
            $x = 15;
            $chunkX--;
        } else if ($x > 15) {
            $x = 0;
            $chunkX++;
        }

        $chunkZ = $this->chunkZ;
        if ($z < 0) {
            $z = 15;
            $chunkZ--;
        } else if ($z > 15) {
            $z = 0;
            $chunkZ++;
        }

        if ($y < 0) {
            $y = 15;
            $subChunkY--;
        } else if ($y > 15) {
            $y = 0;
            $subChunkY++;
        }

        $explorer->moveToChunk($chunkX, $subChunkY, $chunkZ);
        if ($explorer->currentSubChunk instanceof SubChunk) {
            return isset($this->replaceableBlocks[$explorer->currentSubChunk->getBlockStateId($x, $y, $z)]);
        }
        return false;
    }
}