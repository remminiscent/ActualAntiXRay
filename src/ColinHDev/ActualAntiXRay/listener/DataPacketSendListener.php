<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\listener;

use ColinHDev\ActualAntiXRay\ResourceManager;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\World;
use function abs;
use function array_filter;
use function array_key_exists;
use function count;

class DataPacketSendListener implements Listener {

    public function onDataPacketSend(DataPacketSendEvent $event) : void {
        $applyableTargets = [];
        $applyableWorlds = [];
        foreach ($event->getTargets() as $target) {
            $world = $target->getPlayer()?->getWorld();
            if (!($world instanceof World)) {
                continue;
            }
            if (!ResourceManager::getInstance()->isEnabledForWorld($world->getFolderName())) {
                continue;
            }
            $applyableTargets[] = $target;
            $applyableWorlds[$world->getFolderName()] = $world;
        }
        if (count($applyableTargets) === 0) {
            return;
        }
        $blockMapping = TypeConverter::getInstance()->getBlockTranslator();
        /** @var array<string, array<int, Vector3|null>> $positionsToUpdatePerWorld */
        $positionsToUpdatePerWorld = [];
        $packets = $event->getPackets();
        foreach ($packets as $packet) {
            if (!$packet instanceof UpdateBlockPacket) {
                continue;
            }
            $x = $packet->blockPosition->getX();
            $y = $packet->blockPosition->getY();
            $z = $packet->blockPosition->getZ();
            foreach($applyableWorlds as $world) {
                // If the sent block does not match the existing block at that position, then someone uses this packet
                // to send fake blocks to the client, for example through the InvMenu virion.
                // Since we don't want to undermine his efforts, we will ignore this and don't send any block updates.
                if ($blockMapping->internalIdToNetworkId($world->getBlockAt($x, $y, $z)->getStateId()) !== $packet->blockRuntimeId) {
                    continue;
                }
                $worldName = $world->getFolderName();
                if (!isset($positionsToUpdatePerWorld[$worldName])) {
                    $positionsToUpdatePerWorld[$worldName] = [];
                }
                $positionsToUpdatePerWorld[$worldName][World::blockHash($x, $y, $z)] = null;
                $revertRadius = ResourceManager::getInstance()->getRevertRadius();
                for ($xOffset = -$revertRadius; $xOffset <= $revertRadius; $xOffset++) {
                    for ($yOffset = -$revertRadius; $yOffset <= $revertRadius; $yOffset++) {
                        for ($zOffset = -$revertRadius; $zOffset <= $revertRadius; $zOffset++) {
                            if (abs($xOffset) + abs($yOffset) + abs($zOffset) > $revertRadius) continue;
                            $point = new Vector3($x + $xOffset, $y + $yOffset, $z + $zOffset);
                            $hash = World::blockHash($point->getFloorX(), $point->getFloorY(), $point->getFloorZ());
                            if (!array_key_exists($hash, $positionsToUpdatePerWorld[$worldName])) {
                                $positionsToUpdatePerWorld[$worldName][$hash] = $point;
                            }
                        }
                    }
                }
            }
        }
        if (count($positionsToUpdatePerWorld) === 0) {
            return;
        }
        foreach($positionsToUpdatePerWorld as $worldName => $positionsToUpdate) {
            /** @var NetworkSession[] $worldTargets */
            $worldTargets = array_filter(
                $applyableTargets,
                static function(NetworkSession $target) use($worldName) : bool {
                    return $target->getPlayer()?->getWorld()->getFolderName() === $worldName;
                }
            );
            $positionsToUpdate = array_filter(
                $positionsToUpdate,
                static function(Vector3|null $value) : bool {
                    return $value !== null;
                }
            );
            $world = $applyableWorlds[$worldName];
            foreach ($world->createBlockUpdatePackets($positionsToUpdate) as $packet) {
                foreach($worldTargets as $target) {
                    $target->addToSendBuffer(NetworkSession::encodePacketTimed(new ByteBufferWriter(), $packet));
                }
            }
        }
    }
}