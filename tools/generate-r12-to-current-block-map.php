<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\generate_r12_to_current_block_map;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Utils;
use Webmozart\PathUtil\Path;
use function dirname;
use function strlen;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return CompoundTag[]
 */
function readCanonicalBlockStates(PacketSerializer $reader) : array{
	$entries = [];
	while(!$reader->feof()){
		$entries[] = $reader->getNbtCompoundRoot();
	}
	return $entries;
}

/**
 * @param CompoundTag[] $blockStateEntries
 *
 * @return R12ToCurrentBlockMapEntry[]
 */
function getOldToCurrentBlockMapEntries(array $blockStateEntries, array $oldBlockStateEntries) : array{
	$entries = [];

	foreach($oldBlockStateEntries as $name => $states) {
		$name = "minecraft:$name";

		foreach($states as $meta) {
			if ($name === "minecraft:cocoa" && $meta >= 12) {
				continue;
			}

			$state = array_values(array_filter($blockStateEntries, function(CompoundTag $tag) use ($name) : bool{
				return $tag->getString("name") === $name;
			}));
			if(!isset($state[$meta])) {
				var_dump("$name:$meta");
				continue;
			}

			$entries[] = new R12ToCurrentBlockMapEntry($name, $meta, $state[$meta]);
		}
	}

	return $entries;
}

/**
 * @param R12ToCurrentBlockMapEntry[] $blockMapEntries
 */
function writeNewFormat(array $blockMapEntries) : string{
	$stream = new BinaryStream();
	$nbtWriter = new NetworkNbtSerializer();
	foreach($blockMapEntries as $entry){
		$stream->putUnsignedVarInt(strlen($entry->getId()));
		$stream->put($entry->getId());

		$stream->putLShort($entry->getMeta());
		$stream->put($nbtWriter->write(new TreeRoot($entry->getBlockState())));
	}

	return $stream->getBuffer();
}

/**
 * @param string[] $argv
 */
function main(array $argv) : int{
	if(!isset($argv[1]) && !isset($argv[2])){
		echo "Usage: " . PHP_BINARY . " " . __FILE__ . " <path to 'r12_block_states.json' file> <path to 'canonical_block_states.nbt' file>\n";
		return 1;
	}
	$legacyStatesResource = Utils::assumeNotFalse(file_get_contents($argv[1]), "Missing required resource file");
	$contents = json_decode($legacyStatesResource, true, flags: JSON_THROW_ON_ERROR);
	if(!is_array($contents) || !isset($contents["minecraft"])){
		throw new AssumptionFailedError("Invalid format of map");
	}

	$file = $argv[2];
	$reader = PacketSerializer::decoder(
		Utils::assumeNotFalse(file_get_contents($file), "Missing required resource file"),
		0,
		new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol(0)))
	);
	$reader->setProtocolId(0);
	$states = readCanonicalBlockStates($reader);

	$entries = getOldToCurrentBlockMapEntries($states, $contents["minecraft"]);

	file_put_contents(Path::join(Path::getDirectory($file), "r12_to_current_block_map.bin"), writeNewFormat($entries));
	return 0;
}

if(!defined('pocketmine\_PHPSTAN_ANALYSIS')){
	exit(main($argv));
}
