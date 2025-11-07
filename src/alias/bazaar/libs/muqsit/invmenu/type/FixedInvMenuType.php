<?php

declare(strict_types=1);

namespace alias\bazaar\libs\muqsit\invmenu\type;

use alias\bazaar\libs\muqsit\invmenu\type\InvMenuType;

/**
 * An InvMenuType with a fixed inventory size.
 */
interface FixedInvMenuType extends InvMenuType{

	/**
	 * Returns size (number of slots) of the inventory.
	 *
	 * @return int
	 */
	public function getSize() : int;
}