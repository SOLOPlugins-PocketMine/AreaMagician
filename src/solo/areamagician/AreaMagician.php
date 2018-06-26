<?php

namespace solo\areamagician;

use pocketmine\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\BinaryStream;

class AreaMagician extends PluginBase implements Listener{

	public static $prefix = "§b§l[AreaMagician] §r§7";

	private $sessions = [];

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task{
			private $owner;

			public function __construct(AreaMagician $owner){
				$this->owner = $owner;
			}

			public function onRun(int $currentTick){
				foreach($this->owner->getSessions() as $session){
					$session->sendPositionInfo();
				}
			}
		}, 20);
	}

	public function onInteract(PlayerInteractEvent $event){
		if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
			$session = $this->getSession($event->getPlayer());
			if($session instanceof Session){
				$session->onTouch($event->getBlock());
				$event->setCancelled(true);
			}
		}
	}

	public function onChat(PlayerChatEvent $event){
		$session = $this->getSession($event->getPlayer());
		if($session instanceof Session){
			$session->onMessage($event->getMessage());
			$event->setCancelled(true);
		}
	}

	public function onQuit(PlayerQuitEvent $event){
		$session = $this->getSession($event->getPlayer());
		if($session instanceof Session){
			$this->removeSession($session);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage(self::$prefix . "인게임에서만 사용가능합니다.");
			return true;
		}
		$session = new Session($this, $sender);

		$this->sessions[$session->getId()] = $session;
		return true;
	}

	public function getSessions(){
		return $this->sessions;
	}

	public function getSession(Player $player){
		$id = strtolower($player->getName());
		return isset($this->sessions[$id]) ? $this->sessions[$id] : null;
	}

	public function removeSession(Session $session){
		unset($this->sessions[$session->getId()]);
	}
}

class Session{
	private $owner;
	private $player;

	private $pos1 = null;
	private $pos2 = null;
	private $xAmount = null;
	private $zAmount = null;
	private $xInterval = null;
	private $zInterval = null;
	private $fenceSet = null;
	private $price = null;
	private $copy = null;
	private $done = null;

	private $xLength;
	private $zLength;

	private $deserializer;

	public function __construct(AreaMagician $owner, Player $player){
		$this->owner = $owner;
		$this->player = $player;

		$this->sendMessage("AreaMagician을 시작합니다. 도중에 진행을 원치 않으실 때, 'exit'을 입력해주세요.");
		$this->sendMessage("기준이 되는 땅 크기를 설정합니다.");
		$this->sendMessage("첫번째 좌표를 터치해주세요.");
	}

	public function getId(){
		return strtolower($this->player->getName());
	}

	public function sendMessage(string $message){
		$this->player->sendMessage(AreaMagician::$prefix . $message);
	}

	public function sendPositionInfo(){
		$message = "§7현재 ";
		switch($this->player->getDirection()){
		case 0: $message .= "+X축 방향을 보고 계십니다"; break; // South
		case 1: $message .= "+Z축 방향을 보고 계십니다"; break; // West
		case 2:	$message .= "-X축 방향을 보고 계십니다"; break; // North
		case 3: $message .= "-Z축 방향을 보고 계십니다"; break; // East
		}
		$this->player->sendPopup($message);
	}

	public function onTouch(Position $position){
		if($this->pos1 === null){
			$this->pos1 = $position->asVector3();
			$this->sendMessage("첫번째 좌표(" . $this->pos1 . ")를 선택하였습니다. 두번째 좌표를 터치해주세요.");
		}else if($this->pos2 === null){
			$this->pos2 = $position->asVector3();
			$this->sendMessage("두번째 좌표(" . $this->pos2 . ")를 선택하였습니다.");
			$this->sendMessage("몇가지 설정을 더 하겠습니다. 채팅창에 값을 입력해주세요.");
			$this->sendMessage("X축으로 몇 개 생성하시겠습니까? 양수값을 입력하시면 양수 방향으로, 음수값을 입력하시면 음수 방향으로 설정됩니다.");
		}
	}

	public function onMessage(string $message){
		if(strtolower($message) === 'exit'){
			$this->close();
			return;
		}

		if($this->pos2 === null) return;

		$this->sendMessage("입력값 : " . $message);

		if($this->xAmount === null){
			if(!is_numeric($message) || intval($message) == 0){
				$this->sendMessage("값은 0이 아닌 정수로 입력해주세요.");
				return;
			}
			$this->xAmount = intval($message);
			$this->sendMessage("Z축으로 몇 개 생성하시겠습니까? 양수값을 입력하시면 양수 방향으로, 음수값을 입력하시면 음수 방향으로 설정됩니다.");
		}else if($this->zAmount === null){
			if(!is_numeric($message) || intval($message) == 0){
				$this->sendMessage("값은 0이 아닌 정수로 입력해주세요.");
				return;
			}
			$this->zAmount = intval($message);
			$this->sendMessage("X축 방향으로, 땅 사이 간격을 몇 블럭으로 설정하시겠습니까?");
		}else if($this->xInterval === null){
			if(!is_numeric($message) || intval($message) < 0){
				$this->sendMessage("값은 자연수로 입력해주세요.");
				return;
			}
			$this->xInterval = intval($message);
			$this->sendMessage("Z축 방향으로, 땅 사이 간격을 몇 블럭으로 설정하시겠습니까?");
		}else if($this->zInterval === null){
			if(!is_numeric($message) || intval($message) < 0){
				$this->sendMessage("값은 자연수로 입력해주세요.");
				return;
			}
			$this->zInterval = intval($message);
			$this->sendMessage("땅 주변에 울타리를 생성하시겠습니까? 생성하려면 y, 생성하지 않으려면 n을 입력해주세요.");
		}else if($this->fenceSet === null){
			$message = strtolower($message);

			if($message === 'y'){
				$this->fenceSet = true;
			}else if($message === 'n'){
				$this->fenceSet = false;
			}else{
				return; // invalid input, try again
			}

			$this->sendMessage("땅의 가격을 얼마로 설정하시겠습니까? 가격 설정을 원치 않으시면(땅을 판매하는 것이 아니라면) -1을 입력해주세요.");
		}else if($this->price === null){
			if(!is_numeric($message) || intval($message) < -1){
				$this->sendMessage("값은 자연수 또는 -1을 입력해주세요.");
				return;
			}
			$this->price = intval($message);

			$this->sendMessage("선택한 땅의 블럭들을 다른 영역에 똑같이 복사 및 붙여넣기 하시겠습니까? 복사하려면 y, 복사하지 않으려면 n을 입력해주세요. 복사하는 경우, 선택한 좌표 2개로 직육면체를 계산하여 복사합니다.");

		}else if($this->copy === null){
			$message = strtolower($message);

			if($message === 'y'){
				$this->copy = true;
			}else if($message === 'n'){
				$this->copy = false;
			}else{
				return; // invalid input, try again
			}

			// process position
			$tmp1 = $this->pos1;
			$tmp2 = $this->pos2;
			$this->pos1 = new Vector3(
				min($tmp1->getFloorX(), $tmp2->getFloorX()),
				min($tmp1->getFloorY(), $tmp2->getFloorY()),
				min($tmp1->getFloorZ(), $tmp2->getFloorZ())
			);
			$this->pos2 = new Vector3(
				max($tmp1->getFloorX(), $tmp2->getFloorX()),
				max($tmp1->getFloorY(), $tmp2->getFloorY()),
				max($tmp1->getFloorZ(), $tmp2->getFloorZ())
			);
			$this->xLength = $this->pos2->x - $this->pos1->x + 1;
			$this->zLength = $this->pos2->z - $this->pos1->z + 1;

			$this->sendMessage("-------------------------------------");
			$this->sendMessage("땅의 크기 : " . $this->xLength . "x" . $this->zLength);
			$this->sendMessage("땅의 면적 : " . ($this->xLength * $this->zLength));
			$this->sendMessage("기준이 될 땅의 좌표 : (" . $this->pos1->x . "," . $this->pos1->z . ") ~ (" . $this->pos2->x . "," . $this->pos2->z . ")");
			$this->sendMessage("X축 방향으로, 땅의 갯수 : " . $this->xAmount);
			$this->sendMessage("Z축 방향으로, 땅의 갯수 : " . $this->zAmount);
			$this->sendMessage("생성될 땅의 갯수 : " . ($this->xAmount * $this->zAmount));
			$this->sendMessage("X축 방향으로, 땅 사이 간격 : " . $this->xInterval);
			$this->sendMessage("Z축 방향으로, 땅 사이 간격 : " . $this->zInterval);
			$this->sendMessage("땅의 가격 : " . $this->price);
			$this->sendMessage("울타리 생성 여부 : " . ($this->fenceSet ? "예" : "아니오"));
			$this->sendMessage("블럭 복사 여부 : " . ($this->copy ? "예" : "아니오"));
			$this->sendMessage("-------------------------------------");
			$this->sendMessage("입력하신 내용은 위와 같습니다. 계속 진행하려면 y를, 취소하려면 exit을 입력해주세요.");
		}else if($this->done === null){
			$message = strtolower($message);
			if($message === 'y'){
				$this->done = true;

				$this->process();
			}
		}
	}

	public function copy(){
		$serializer = new BlockSerializer($this->player->getLevel());
		$serializer->serialize($this->pos1, $this->pos2);
		$this->deserializer = new BlockDeserializer($this->player->getLevel(), $serializer->buffer);
	}

	public function paste(Vector3 $pos){
		$this->deserializer->deserialize($pos);
	}

	public function process(){
		$areaProvider = \ifteam\SimpleArea\database\area\AreaProvider::getInstance();
		$count = 0;

		$xAmp = ($this->xLength + $this->xInterval) * ($this->xAmount < 0 ? -1 : 1);
		$zAmp = ($this->zLength + $this->zInterval) * ($this->zAmount < 0 ? -1 : 1);

		$this->xAmount = abs($this->xAmount);
		$this->zAmount = abs($this->zAmount);

		if($this->copy) $this->copy();

		for($xi = 0; $xi < $this->xAmount; $xi++){
			for($zi = 0; $zi < $this->zAmount; $zi++){
				$x0 = $this->pos1->x + $xAmp * $xi;
				$x1 = $this->pos2->x + $xAmp * $xi;
				$z0 = $this->pos1->z + $zAmp * $zi;
				$z1 = $this->pos2->z + $zAmp * $zi;

				$area = $areaProvider->addArea(
					$this->player->getLevel(), // level
					$x0, // startX
					$x1, // endX
					$z0, // startZ
					$z1, // endZ
					"", // owner
					($this->price >= 0), // isHome
					$this->fenceSet // fenceSet
				);
				if($this->price >= 0) $area->setPrice($this->price);
				if($this->copy) $this->paste(new Vector3($x0, $this->pos1->y, $z0));

				$count++;
			}
		}

		$this->sendMessage("땅 생성을 완료하였습니다, 생성된 땅 : " . $count . "개");
		$this->close();
	}

	public function close(){
		$this->sendMessage("AreaMagician을 종료합니다.");
		$this->owner->removeSession($this);
	}
}

class BlockSerializer extends BinaryStream{
	private $level;

	public function __construct(Level $level){
		$this->level = $level;
	}

	public function serialize(Vector3 $pos1, Vector3 $pos2){
		$x0 = min($pos1->getFloorX(), $pos2->getFloorX());
		$x1 = max($pos1->getFloorX(), $pos2->getFloorX());
		$y0 = min($pos1->getFloorY(), $pos2->getFloorY());
		$y1 = max($pos1->getFloorY(), $pos2->getFloorY());
		$z0 = min($pos1->getFloorZ(), $pos2->getFloorZ());
		$z1 = max($pos1->getFloorZ(), $pos2->getFloorZ());

		$this->putUnsignedVarInt($x1 - $x0);
		$this->putUnsignedVarInt($y1 - $y0);
		$this->putUnsignedVarInt($z1 - $z0);

		for($y = $y0; $y <= $y1; $y++){
			for($z = $z0; $z <= $z1; $z++){
				for($x = $x0; $x <= $x1; $x++){
					$this->putByte($this->level->getBlockIdAt($x, $y, $z));
					$this->putByte($this->level->getBlockDataAt($x, $y, $z));
				}
			}
		}
	}
}

class BlockDeserializer extends BinaryStream{
	private $level;

	public function __construct(Level $level, string $buffer){
		parent::__construct($buffer);
		$this->level = $level;
	}

	public function deserialize(Vector3 $pos){
		$xlen = $this->getUnsignedVarInt();
		$ylen = $this->getUnsignedVarInt();
		$zlen = $this->getUnsignedVarInt();

		$x0 = $pos->getFloorX();
		$x1 = $x0 + $xlen;
		$y0 = $pos->getFloorY();
		$y1 = $y0 + $ylen;
		$z0 = $pos->getFloorZ();
		$z1 = $z0 + $zlen;

		for($y = $y0; $y <= $y1; $y++){
			for($z = $z0; $z <= $z1; $z++){
				for($x = $x0; $x <= $x1; $x++){
					$this->level->setBlockIdAt($x, $y, $z, $this->getByte());
					$this->level->setBlockDataAt($x, $y, $z, $this->getByte());
				}
			}
		}

		$this->offset = 0; // reusable
	}
}