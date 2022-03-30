<?php

declare(strict_types = 1);

namespace JavierLeon9966\BlockLagFix;

use muqsit\simplepackethandler\SimplePacketHandler;

use pocketmine\block\{Block, BlockFactory};
use pocketmine\block\tile\Spawnable;
use pocketmine\block\WoodenDoor;
use pocketmine\block\WoodenTrapdoor;
use pocketmine\event\EventPriority;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use pocketmine\utils\AssumptionFailedError;

final class BlockLagFix extends PluginBase{

	public function onEnable(): void{
		$handler = SimplePacketHandler::createInterceptor($this, EventPriority::HIGHEST);

		/**
		 * @var Block[] $lastBlocks
		 * @phpstan-var array<int, Block> $lastBlocks
		 */
		$lastBlocks = [];
		$lastNetworkSession = null;
		$handleUpdateBlock = static function(UpdateBlockPacket $packet, NetworkSession $target) use(&$lastBlocks, &$lastNetworkSession, $server): bool{
            /** @var NetworkSession $lastNetworkSession */
            if($target !== $lastNetworkSession){
                return true;
            }
            $blockHash = World::blockHash($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
            $blockPos = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());

            if(!self::canBuildHere($blockPos, $target->getPlayer() ?? null)){
                return true;
            }
		$handler->interceptIncoming(static function(InventoryTransactionPacket $packet, NetworkSession $target) use($handler, $handleUpdateBlock, &$lastBlocks, &$lastNetworkSession): bool{
			if(!$packet->trData instanceof UseItemTransactionData || $packet->trData->getActionType() !== UseItemTransactionData::ACTION_CLICK_BLOCK){
				return true;
			}
			$blockPos = $packet->trData->getBlockPosition();
			$clickedPos = new Vector3($blockPos->getX(), $blockPos->getY(), $blockPos->getZ());
			$replacePos = $clickedPos->getSide($packet->trData->getFace());
			$player = $target->getPlayer() ?? throw new AssumptionFailedError;
			$world = $player->getWorld();
			$oldBlocks = [];
			foreach($clickedPos->sides() as $side){
				$oldBlocks[World::blockHash($side->x, $side->y, $side->z)] = $world->getBlockAt($side->x, $side->y, $side->z);
			}
			foreach($replacePos->sides() as $side){
				$oldBlocks[World::blockHash($side->x, $side->y, $side->z)] = $world->getBlockAt($side->x, $side->y, $side->z);
			}
			$lastBlocks = $oldBlocks;
			$lastNetworkSession = $target;
			$handler->interceptOutgoing($handleUpdateBlock);
			/** @noinspection PhpExpressionResultUnusedInspection */
			$target->getHandler()?->handleInventoryTransaction($packet);
			$handler->unregisterOutgoingInterceptor($handleUpdateBlock);

			$blockMapping = RuntimeBlockMapping::getInstance();
			$packets = [];
			foreach($lastBlocks as $index => $block){
				World::getBlockXYZ($index, $x, $y, $z);
				$blockPosition = new Vector3($x, $y, $z);
				$oldBlock = $oldBlocks[$index];
				if(!$oldBlock->isSameState($block) || $blockPosition->equals($replacePos) || (($oldBlock instanceof WoodenDoor || $oldBlock instanceof WoodenTrapdoor) && $blockPosition->equals($clickedPos))){
					$blockPosition = BlockPosition::fromVector3($blockPosition);
					$packets[] = UpdateBlockPacket::create(
						$blockPosition,
						$blockMapping->toRuntimeId($block->getFullId()),
						UpdateBlockPacket::FLAG_NETWORK,
						UpdateBlockPacket::DATA_LAYER_NORMAL
					);
					$tile = $world->getTileAt($blockPosition->getX(), $blockPosition->getY(), $blockPosition->getZ());
					if($tile instanceof Spawnable){
						$packets[] = BlockActorDataPacket::create($blockPosition, $tile->getSerializedSpawnCompound());
					}
				}
			}
			foreach($packets as $blockPacket){
				$target->sendDataPacket($blockPacket);
			}
			return false;
		});
	}
}