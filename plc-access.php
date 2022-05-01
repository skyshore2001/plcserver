<?php
/*
read:
	plc-access -h 192.168.1.101 DB1.1:int8

write:
	plc-access -h 192.168.1.101 DB1.1:uint8=200

write and read:
	php plc-access.php DB21.1:uint8=ff  DB21.1.0:bit DB21.1.7:bit  -x

item address: 

- DB{dbNumber}.{startAddr}:{type}
- DB{dbNumber}.{startAddr}.{bitOffset}:bit
- array format:
  - DB{dbNumber}.{startAddr}:{type}[amount]
  - DB{dbNumber}.{startAddr}.{bitOffset}:bit[amount]

command options:

-h : plc host. default=127.0.0.1:102
-x : use hex(16-based) number 
-p : proto. Enum(s7(default), modbus)

type:

- int8
- uint8/byte
- int16/int
- uint16/word
- int32/dint
- uint32/dword
- bit/bool
- float
- char

write array:
	php plc-access.php -h 192.168.1.101 DB1.1:byte[2]=125,225

handle char:

	php plc-access.php DB21.0:char[4]=A,B,,C
	php plc-access.php DB21.0:char[4]
	"AB\u0000C"

	php plc-access.php DB21.0:char[2]=A,B DB21.0:uint8[2]
	"AB", [65,66]

	php plc-access.php DB21.0:uint32 -x
	"x41420043"

modbus-tcp write and read:

	php plc-access.php -t modbus S1.0:word[2]=20000,40000
	php plc-access.php -t modbus S1.0:word[2]

*/

require("jdcloud-php/common.php");
require("class/S7Plc.php");
require("class/ModbusClient.php");

if ($argc < 2) {
	echo("Usage:
  s7 read: 
    php plc-access.php DB1.0:dword DB1.4:word[2]
  s7 write: 
    php plc-access.php DB1.0:dword=30000 DB1.4:word[2]=30001,30002
  modbus read (slave 1 addr 1): 
    php plc-access.php -p modbus S1.1:word[2] S2.1:dword
  modbus write: 
    php plc-access.php -p modbus S1.1:word[2]=30000,30001 S2.1:dword=50000

  -p {proto}: s7(default),modbus
  -h {host}: default host: 127.0.0.1
  -x: show hex 
  support type: int8, uint8/byte, int16/int, uint16/word, int32/dword, bit/bool, float, char
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
	if ($opt['proto'] == 's7') {
		$plc = new S7Plc($opt['addr']);
	}
	else if ($opt['proto'] == 'modbus') {
		$plc = new ModbusClient($opt['addr']);
	}
	else {
		echo("*** unknown proto {$opt['proto']}\n");
	}
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
