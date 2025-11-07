<?php

declare(strict_types=1);

namespace alias\bazaar\libs\muqsit\invmenu\session;

use alias\bazaar\libs\muqsit\invmenu\InvMenu;
use alias\bazaar\libs\muqsit\invmenu\type\graphic\InvMenuGraphic;

final class InvMenuInfo{

	public function __construct(
		readonly public InvMenu $menu,
		readonly public InvMenuGraphic $graphic,
		readonly public ?string $graphic_name
	){}
}