<?php

require_once __DIR__."/../src/ssautoload.php";

use sscf\SScf;
use sscf\SSLog;


$s = new SScf();
$aa = $s->getScfFunctions();
SSLog::debug($aa);