<?php

declare(strict_types = 1);

namespace BlockHorizons\BlockPets\pets\datastorage;

use BlockHorizons\BlockPets\Loader;
use BlockHorizons\BlockPets\pets\datastorage\types\PetData;
use BlockHorizons\BlockPets\pets\datastorage\types\PetOwnerData;

use pocketmine\nbt\BigEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\utils\UUID;

use poggit\libasynql\libasynql;

use SOFe\AwaitGenerator\Await;

abstract class SQLDataStorer extends BaseDataStorer {

	protected const INIT_PETS = "blockpets.init.pets";
	protected const INIT_PETS_PROPERTY = "blockpets.init.pets_property";

	protected const LOAD_PLAYER = "blockpets.player.load";

	protected const PET_CREATE = "blockpets.pet.create";
	protected const PET_DELETE = "blockpets.pet.delete";
	protected const PET_LEADERBOARDS = "blockpets.pet.leaderboards";
	protected const PET_INIT_PROPERTIES = "blockpets.pet.init_properties";
	protected const PET_UPDATE_NAME = "blockpets.pet.update.name";
	protected const PET_UPDATE_XP = "blockpets.pet.update.xp";
	protected const PET_UPDATE_NBT = "blockpets.pet.update.nbt";

	protected const VERSION_PATCH = "version.{VERSION}";

	/** @var BigEndianNBTStream|null */
	protected static $nbtSerializer = null;

	protected static function getNBTSerializer(): BigEndianNBTStream {
		return self::$nbtSerializer ?? self::$nbtSerializer = new BigEndianNBTStream();
	}

	/**
	 * Reads a binary string from the database.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected static function readBinaryString(string $string): string {
		return $string;
	}

	/**
	 * Writes a binary string safely to the database.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected static function writeBinaryString(string $string): string {
		return $string;
	}

	/**
	 * Decodes NBT from the given compressed buffer and returns it.
	 *
	 * @param string $buffer
	 *
	 * @return CompoundTag
	 */
	protected static function readNamedTag(string $buffer): CompoundTag {
		return static::getNBTSerializer()->readCompressed(static::readBinaryString($buffer));
	}

	/**
	 * @param CompoundTag $nbt
	 *
	 * @return string
	 */
	protected static function writeNamedTag(CompoundTag $nbt): string {
		return static::writeBinaryString(static::getNBTSerializer()->writeCompressed($nbt));
	}

	/** @var libasynql */
	protected $database;
	/** @var string */
	protected $type;

	public function createPet(PetData $data): void {
		Await::f2c(function() use($data){
			yield $this->asyncChange(static::PET_CREATE, [
				"uuid" => $data->getUUID()->toBinary(),
				"owner" => $data->getOwner(),
				"type" => $data->getType()
			]);

			yield $this->asyncChange(static::PET_INIT_PROPERTIES, [
				"uuid" => $data->getUUID()->toBinary(),
				"name" => $data->getName(),
				"xp" => $data->getXp(),
				"nbt" => static::writeNamedTag($data->getNamedTag())
			]);

			yield Await::ALL;
		});
	}

	public function deletePet(UUID $uuid): void {
		$this->database->executeChange(static::PET_DELETE, [
			"uuid" => $uuid
		]);
	}

	public function loadPlayer(Player $player, callable $on_load_player): void {
		$owner = $player->getName();
		$this->database->executeSelect(static::LOAD_PLAYER, [
			"owner" => $owner
		], function(array $entries) use($owner, $on_load_player): void {
			$data = new PetOwnerData($owner);

			foreach($entries as $entry) {
				$pet = new PetData(UUID::fromBinary($entry["uuid"]), $entry["name"], $entry["type"], $owner);
				$pet->setXp($entry["xp"]);
				$pet->setNamedTag(static::readNamedTag($entry["nbt"]));
				$data->setPet($pet);
			}

			$on_load_player($data);
		});
	}

	public function getPetsLeaderboard(int $offset = 0, int $length = 1, ?string $entityName = null, callable $callable): void {
		$this->database->executeSelect(static::PET_LEADERBOARDS, [
			"offset" => $offset,
			"length" => $length,
			"entityname" => $entityName ?? "%"
		], $callable);
	}

	public function updatePetName(UUID $uuid, string $new_name): void {
		$this->database->executeChange(static::PET_UPDATE_NAME, [
			"uuid" => $uuid->toBinary(),
			"name" => $new_name
		]);
	}

	public function updatePetXp(UUID $uuid, int $xp): void {
		$this->database->executeChange(static::PET_UPDATE_XP, [
			"uuid" => $uuid->toBinary(),
			"xp" => $xp
		]);
	}

	public function updatePetNBT(UUID $uuid, CompoundTag $nbt): void {
		$this->database->executeChange(static::PET_UPDATE_NBT, [
			"uuid" => $uuid->toBinary(),
			"nbt" => static::writeNamedTag($nbt)
		]);
	}

	protected function prepare(): void {
		$loader = $this->getLoader();

		$config = $loader->getBlockPetsConfig();
		$this->type = strtolower($config->getDatabase());
		$mc = $config->getMySQLInfo();

		$libasynql_friendly_config = [
			"type" => $this->type,
			"sqlite" => [
				"file" => $loader->getDataFolder() . "blockpets.sqlite3"
			],
			"mysql" => [
				"host" => $mc["Host"],
				"username" => $mc["User"],
				"password" => $mc["Password"],
				"schema" => $mc["Database"],
				"port" => $mc["Port"]
			],
			"worker-limit" => $config->getDatabaseWorkerLimit()
		];

		$this->database = libasynql::create($loader, $libasynql_friendly_config, [
			"mysql" => "database_stmts/mysql.sql",
			"sqlite" => "database_stmts/sqlite.sql"
		]);

		Await::f2c(function(){
			yield $this->asyncGeneric(static::INIT_PETS);
			yield $this->asyncGeneric(static::INIT_PETS_PROPERTY);
			yield Await::ALL;
		});

		$resource = $this->getLoader()->getResource("patches/" . $this->type . ".sql");
		if($resource !== null) {
			$this->database->loadQueryFile($resource);//calls fclose($resource)
		}
	}

	protected function asyncChange(string $query, array $args = []): \Generator {
		$this->database->executeChange($query, $args, yield, yield Await::REJECT);
		return yield Await::ONCE;
	}

	protected function asyncGeneric(string $query, array $args = []): \Generator {
		$this->database->executeGeneric($query, $args, yield, yield Await::REJECT);
		return yield Await::ONCE;
	}

	public function patch(string $version): void {
		switch($version) {
			case "1.1.2":
			case "2.0.0":
				$this->database->executeGeneric(str_replace("{VERSION}", $version, SQLDataStorer::VERSION_PATCH));
				break;
		}
	}

	protected function close(): void {
		$this->database->close();
	}
}