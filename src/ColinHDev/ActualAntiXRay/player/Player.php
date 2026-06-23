<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\player;

use ColinHDev\ActualAntiXRay\ResourceManager;
use ColinHDev\ActualAntiXRay\tasks\ChunkRequestTask;
use ColinHDev\ActualAntiXRay\utils\ReflectionCache;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\player\Player as PMMP_PLAYER;
use pocketmine\player\UsedChunkStatus;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use function is_string;

class Player extends PMMP_PLAYER {


    /**
     * Requests chunks from the world to be sent, up to a set limit every tick. This operates on the results of the most recent chunk
     * order.
     */
    protected function requestChunks() : void{
        if(!$this->isConnected()){
            return;
        }

        if (!ResourceManager::getInstance()->isEnabledForWorld($this->getWorld()->getFolderName())) {
            parent::requestChunks();
            return;
        }

        Timings::$playerChunkSend->startTiming();

        $count = 0;
        $world = $this->getWorld();

        $activeChunkGenerationRequestsProperty = ReflectionCache::get(PMMP_PLAYER::class, "activeChunkGenerationRequests");
        $activeChunkGenerationRequests = $activeChunkGenerationRequestsProperty->getValue($this);
        $tickingChunksProperty = ReflectionCache::get(PMMP_PLAYER::class, "tickingChunks");
        $tickingChunks = $tickingChunksProperty->getValue($this);

        $limit = $this->chunksPerTick - count($activeChunkGenerationRequests);
        foreach($this->loadQueue as $index => $distance){
            if($count >= $limit){
                break;
            }

            $X = null;
            $Z = null;
            World::getXZ($index, $X, $Z);
            assert(is_int($X) && is_int($Z));

            ++$count;

            $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_GENERATION;
            $activeChunkGenerationRequests[$index] = true;
            $activeChunkGenerationRequestsProperty->setValue($this, $activeChunkGenerationRequests);
            unset($this->loadQueue[$index]);
            $this->getWorld()->registerChunkLoader($this->chunkLoader, $X, $Z, true);
            $this->getWorld()->registerChunkListener($this, $X, $Z);
            if(isset($tickingChunks[$index])){
                $world->registerTickingChunk($this->chunkTicker, $X, $Z);
            }

            $this->getWorld()->requestChunkPopulation($X, $Z, $this->chunkLoader)->onCompletion(
                function() use ($X, $Z, $index, $world, $activeChunkGenerationRequestsProperty) : void{
                    if(!$this->isConnected() || !isset($this->usedChunks[$index]) || $world !== $this->getWorld()){
                        return;
                    }
                    if($this->usedChunks[$index] !== UsedChunkStatus::REQUESTED_GENERATION){
                        //We may have previously requested this, decided we didn't want it, and then decided we did want
                        //it again, all before the generation request got executed. In that case, the promise would have
                        //multiple callbacks for this player. In that case, only the first one matters.
                        return;
                    }
                    $activeChunkGenerationRequests = $activeChunkGenerationRequestsProperty->getValue($this);
                    unset($activeChunkGenerationRequests[$index]);
                    $activeChunkGenerationRequestsProperty->setValue($this, $activeChunkGenerationRequests);
                    $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_SENDING;

                    $this->startUsingChunk($X, $Z, function() use ($X, $Z, $index) : void{
                        $this->usedChunks[$index] = UsedChunkStatus::SENT;
                        if($this->spawnChunkLoadCount === -1){
                            $this->spawnEntitiesOnChunk($X, $Z);
                        }elseif($this->spawnChunkLoadCount++ === $this->spawnThreshold){
                            $this->spawnChunkLoadCount = -1;

                            $this->spawnEntitiesOnAllChunks();

                            $this->getNetworkSession()->notifyTerrainReady();
                        }
                        (new PlayerPostChunkSendEvent($this, $X, $Z))->call();
                    });
                },
                static function() : void{
                    //NOOP: we'll re-request this if it fails anyway
                }
            );
        }

        Timings::$playerChunkSend->stopTiming();
    }

    /**
     * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
     * @param \Closure $onCompletion To be called when chunk sending has completed.
     * @phpstan-param \Closure() : void $onCompletion
     */
    public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
        Utils::validateCallableSignature(function() : void{}, $onCompletion);

        $world = $this->getLocation()->getWorld();
        $promiseOrPacket = $this->request(ChunkCache::getInstance($world, $this->getNetworkSession()->getCompressor()), $chunkX, $chunkZ);
        if(is_string($promiseOrPacket)){
            $world->timings->syncChunkSend->startTiming();
            try{
                $this->getNetworkSession()->queueCompressed($promiseOrPacket);
                $onCompletion();
            }finally{
                $world->timings->syncChunkSend->stopTiming();
            }
            return;
        }
        $promiseOrPacket->onResolve(

        //this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
            function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
                if(!$this->isConnected()){
                    return;
                }
                $currentWorld = $this->getLocation()->getWorld();
                if($world !== $currentWorld || ($status = $this->getUsedChunkStatus($chunkX, $chunkZ)) === null){
                    $this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
                    return;
                }
                if($status !== UsedChunkStatus::REQUESTED_SENDING){
                    //TODO: make this an error
                    //this could be triggered due to the shitty way that chunk resends are handled
                    //right now - not because of the spammy re-requesting, but because the chunk status reverts
                    //to NEEDED if they want to be resent.
                    return;
                }
                $world->timings->syncChunkSend->startTiming();
                try{
                    $this->getNetworkSession()->queueCompressed($promise->getResult());
                    $onCompletion();
                }finally{
                    $world->timings->syncChunkSend->stopTiming();
                }
            }
        );
    }

    /**
     * Requests asynchronous preparation of the chunk at the given coordinates.
     *
     * @return CompressBatchPromise|string a promise of resolution which will contain a compressed chunk packet, or the compressed chunk packet.
     */
    public function request(ChunkCache $chunkCache, int $chunkX, int $chunkZ) : CompressBatchPromise|string{
        $property = ReflectionCache::get(ChunkCache::class, "world");
        /** @var World $world */
        $world = $property->getValue($chunkCache);

        $world->registerChunkListener($chunkCache, $chunkX, $chunkZ);
        $chunk = $world->getChunk($chunkX, $chunkZ);
        if($chunk === null){
            throw new \InvalidArgumentException("Cannot request an unloaded chunk");
        }
        $chunkHash = World::chunkHash($chunkX, $chunkZ);

        $cacheProperty = ReflectionCache::get(ChunkCache::class, "caches");
        /** @var array<int, CompressBatchPromise|string> $caches */
        $caches = $cacheProperty->getValue($chunkCache);

        if(isset($caches[$chunkHash])){
            $property = ReflectionCache::get(ChunkCache::class, "hits");
            /** @var int $hits */
            $hits = $property->getValue($chunkCache);
            $property->setValue($chunkCache, $hits + 1);

            return $caches[$chunkHash];
        }

        $property = ReflectionCache::get(ChunkCache::class, "misses");
        /** @var int $misses */
        $misses = $property->getValue($chunkCache);
        $property->setValue($chunkCache, $misses + 1);

        $world->timings->syncChunkSendPrepare->startTiming();
        try{
            $caches[$chunkHash] = new CompressBatchPromise();
            $cacheProperty->setValue($chunkCache, $caches);

            $property = ReflectionCache::get(ChunkCache::class, "compressor");
            /** @var Compressor $compressor */
            $compressor = $property->getValue($chunkCache);

            $property = ReflectionCache::get(ChunkCache::class, "dimensionId");
            /** @var int $dimensionId */
            $dimensionId = $property->getValue($chunkCache);

            $world->getServer()->getAsyncPool()->submitTask(
                new ChunkRequestTask(
                    $world,
                    $chunkX,
                    $chunkZ,
                    $dimensionId,
                    $chunk,
                    $caches[$chunkHash],
                    $compressor
                )
            );
            $caches[$chunkHash]->onResolve(function(CompressBatchPromise $promise) use ($chunkCache, $chunkHash) : void{
                $property = ReflectionCache::get(ChunkCache::class, "caches");
                /** @var array<int, CompressBatchPromise|string> $caches */
                $caches = $property->getValue($chunkCache);
                if(($caches[$chunkHash] ?? null) === $promise){
                    $caches[$chunkHash] = $promise->getResult();
                    $property->setValue($chunkCache, $caches);
                }
            });

            return $caches[$chunkHash];
        }finally{
            $world->timings->syncChunkSendPrepare->stopTiming();
        }
    }

}