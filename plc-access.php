<?php
/*
read:
	plc-access -h 192.168.1.101 DB1.1:int8

write and read:
	plc-access -h 192.168.1.101 DB1.1:uint8=200

command options:

-h : plc host. default=127.0.0.1:102 for snap7, or 127.0.0.1:501 for modbus
-x : use hex(16-based) number 
-p : proto. Enum(s7(default), modbus)

modbus-tcp write and read:

	php plc-access.php -t modbus S1.0:word[2]=20000,40000
	php plc-access.php -t modbus S1.0:word[2]

*/

require("jdcloud-php/common.php");
require("class/PlcAccess.php");

if ($argc < 2) {
	echo("Usage:
  s7 read: 
    php plc-access.php DB1.0:dword DB1.4:word[2]
  s7 write: 
    php plc-access.php DB1.0:dword=30000 DB1.4:word[2]=30001,30002
  modbus read (S1.0: slave 1 addr 0): 
    php plc-access.php -p modbus S1.0:word[2] S2.0:dword S3.0:bit[4]
  modbus write: 
    php plc-access.php -p modbus S1.0:word[2]=30000,30001 S2.0:dword=50000 S3.0:bit[4]=0,1,1,0

  -p {proto}: s7(default),modbus
  -h {host}: default host: 127.0.0.1
  -x: show hex 
  support type: bit/bool, int8, uint8/byte, int16/int, uint16/word, int32/dint, uint32/dword, int64, uint64, float, double, char[], string[]
");
	exit(0);
}
$opt = [
	"proto" => "s7",
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
		else if ($v == '-p') {
			$value = "proto";
		}
		else if ($v == '-x') {
			$opt['useHex'] = true;
		}
		continue;
	}
	if (strpos($v, '=') !== false) {
		$varr = explode('=', $v);
		// handle array read/write:
		if (stripos($varr[0], '[') !== false) {
			if (! isStringType($varr[0])) {
				$varr[1] = explode(',', $varr[1]);
			}
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
	$plc = PlcAccess::create($opt['proto'], $opt['addr']);
	if ($opt['write']) {
		beforeWrite($opt['write']);
		$plc->write($opt['write']);
		echo("=== write ok\n");
	}

	if ($opt['read']) {
		$res = $plc->read($opt['read']);
		afterRead($res);
		echo("=== read ok: " . json_encode($res, JSON_PRETTY_PRINT));
	}
}
catch (Exception $ex) {
	echo("*** error: " . $ex->getMessage() . "\n");
}

function isStringType($type) {
	return preg_match('/:(char|string)/', $type);
}

// "ab\x31\x32"
function decodeString($v) {
	return preg_replace_callback('/\\\\x([0-9a-z]{2})/i', function ($ms) {
		$c = hexdec($ms[1]);
		return chr($c);
	}, $v);
}

function encodeString($v) {
	return preg_replace_callback('/./', function ($ms) {
		if (ctype_print($ms[0]))
			return $ms[0];
		$s = dechex(ord($ms[0]));
		return "\x" . str_pad($s, 2, "0", STR_PAD_LEFT);
	}, $v);
}

// req: ["DB100.0:uint8", 100] or ["DB100.0:uint8[2]", [100, 101]]
function beforeWrite(&$req) {
	$handleOne = function ($type, &$val) {
		if (isStringType($type)) {
			$val = decodeString($val);
		}
		else if (substr($val,0,2) == "0x") {
			$val = hexdec(substr($val,2));
		}
	};
	foreach ($req as &$v) {
		if (is_array($v[1])) {
			foreach ($v[1] as &$v2) {
				$handleOne($v[0], $v2);
			}
		}
		else {
			$handleOne($v[0], $v[1]);
		}
	}
}

function afterRead(&$res) {
	global $opt;
	$useHex = $opt["useHex"];
	foreach ($res as &$v) {
		if (is_array($v)) {
			afterRead($v);
		}
		else if (is_string($v)) {
			$v = encodeString($v);
		}
		else if ($useHex && is_int($v)) {
			$v = sprintf("0x%02x", $v);
		}
	}
}
