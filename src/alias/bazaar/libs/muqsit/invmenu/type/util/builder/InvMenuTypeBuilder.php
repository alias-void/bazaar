<?php

declare(strict_types=1);

namespace alias\bazaar\libs\muqsit\invmenu\type\util\builder;

use alias\bazaar\libs\muqsit\invmenu\type\InvMenuType;

interface InvMenuTypeBuilder{

	public function build() : InvMenuType;
}