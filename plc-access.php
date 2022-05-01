<?php
/*
read:
	plc-access -h 192.168.1.101 DB1.1:int8 DB1.1:int8[2]

	options:
	-x : use 16-based number

write:
	plc-access -h 192.168.1.101 DB1.1:int8=-2

write array:
	plc-access -h 192.168.1.101 DB1.1:byte[2]=125,225

write and read:
	php plc-access.php DB21.1:uint8=ff  DB21.1.0:bit DB21.1.7:bit  -x

item address: 

- DB{dbNumber}.{startAddr}:{type}
- DB{dbNumber}.{startAddr}.{bitOffset}:bit

type:

- int8
- uint8/byte
- int16/int
- uint16/word
- int32/dint
- uint32/dword
- bit/bool
- float

handle char:

	php plc-access.php DB21.0:char[4]=A,B,,C
	php plc-access.php DB21.0:char[4]
	"AB\u0000C"

	php plc-access.php DB21.0:uint32 -x
	"x41420043"

*/

require("jdcloud-php/common.php");
require("class/S7Plc.php");

$opt = [
	"addr" => "127.0.0.1",
	"useHex" => false,
	"read" => [],
	"write" => []
];

$value = null;
foreach ($argv as $i=>$v) {
	if ($i == 0)
		continue;
	if ($value) {
		$opt[$value] = $v;
		$value = null;
		continue;
	}
	if ($v[0] == '-') {
		if ($v == '-h') {
			$value = "addr";
		}
		if ($v == '-x') {
			$opt['useHex'] = true;
		}
		continue;
	}
	if (strpos($v, '=') !== false) {
		$varr = explode('=', $v);
		// handle array read/write:
		if (stripos($varr[0], '[') !== false) {
			$varr[1] = explode(',', $varr[1]);
		}
		$opt['write'][] = $varr;
		$opt['read'][] = $varr[0];
	}
	else {
		$opt['read'][] = $v;
	}
}

echo("=== access plc {$opt['addr']}\n");
try {
	$plc = new S7Plc($opt['addr']);
	if ($opt['write']) {
		handleReq($opt['write']);
		$plc->write($opt['write']);
		echo("=== write ok\n");
	}

	if ($opt['read']) {
		$res = $plc->read($opt['read']);
		handleRes($res);
		echo("=== read ok: " . jsonEncode($res, true));
	}
}
catch (Exception $ex) {
	echo("*** error: " . $ex->getMessage() . "\n");
}

// useHex
function handleReq(&$res) {
	global $opt;
	if ($opt["useHex"]) {
		foreach ($res as &$v) {
			if (is_array($v[1])) {
				foreach ($v[1] as &$v2) {
					if (preg_match('/^[0-9a-f]+$/i', $v2))
						$v2 = hexdec($v2);
				}
			}
			else {
				if (preg_match('/^[0-9a-f]+$/i', $v[1]))
					$v[1] = hexdec($v[1]);
			}
		}
	}
}

// useHex
function handleRes(&$res) {
	global $opt;
	if ($opt["useHex"]) {
		foreach ($res as &$v) {
			if (is_array($v)) {
				handleRes($v);
			}
			else if (is_int($v)) {
				$v = sprintf("x%02x", $v);
			}
		}
	}
}
