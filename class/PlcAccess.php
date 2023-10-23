<?php

class PlcAccessException extends LogicException 
{
}

class PlcAccess
{
	static protected $typeAlias = [
		"bool" => "bit",
		"byte" => "uint8",
		"word" => "uint16",
		"dword" => "uint32",
		"int" => "int16",
		"dint" => "int32"
	];

	static protected $typeMap = [
		// len: 字节数
		"bit" => ["fmt"=>"C", "len"=>1],
		"int8" => ["fmt"=>"c", "len"=>1],
		"uint8" => ["fmt"=>"C", "len"=>1],

		"int16" => ["fmt"=>"n", "len"=>2],
		"uint16" => ["fmt"=>"n", "len"=>2],

		"int32" => ["fmt"=>"N", "len"=>4],
		"uint32" => ["fmt"=>"N", "len"=>4],

		"int64" => ["fmt"=>"J", "len"=>8],
		"uint64" => ["fmt"=>"J", "len"=>8],

		"float" => ["fmt"=>"G", "len"=>4],
		"double" => ["fmt"=>"E", "len"=>8],

		"char" => ["fmt"=>"a", "len"=>1],
		"string" => ["fmt"=>"a", "len"=>1]
	];

	static function readPlc($proto, $addr, $items) {
		$plc = PlcAccess::create($proto, $addr);
		return $plc->read($items);
	}
	static function writePlc($proto, $addr, $items) {
		$plc = PlcAccess::create($proto, $addr);
		return $plc->write($items);
	}

	// $plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	static function create($proto, $addr) {
		if ($proto == 's7') {
			require_once("S7Plc.php");
			return new S7Plc($addr);
		}
		else if ($proto == 'modbus') {
			require_once("ModbusClient.php");
			return new ModbusClient($addr);
		}
		// 模拟设备
		else if ($proto == 'mock') {
			return new PlcMockClient();
		}
		throw new PlcAccessException("unknown proto $proto");
	}

	// [ ["DB100.0:byte"] ] => [ ["code"=>"DB100.0", "type"=>"byte", "isArray"=>false, "amount"=>1] ]
	function read($items) {
		$items1 = [];
		foreach ($items as $addr) {
			$item = $this->parseItem($addr);
			$items1[] = $item;
		}
		return $items1;
	}

	// [ ["DB100.0:byte", 11] ] => [ ["code"=>"DB100.0", "type"=>"byte", "amount"=>1, "isArray"=>false, "value"=>11] ]
	function write($items) {
		$items1 = [];
		foreach ($items as $e) {
			$val = $e[1] === null? "": $e[1];
			$item = $this->parseItem($e[0], $val);
			$items1[] = $item;
		}
		return $items1;
	}

	// item: {code, type, isArray, amount}
	protected function readItem($item, $value0) {
		$t = $item["type"];
		$packFmt = self::$typeMap[$t]["fmt"];
		if ($t == "char") {
			if ($item["amount"] == strlen($value0)) {
				$value = $value0;
			}
			else {
				$value = substr($value0, 0, $item["amount"]);
			}
		}
		else if ($t == "string") {
			$rv = unpack("C2", substr($value0, 0, 2));
			$cap = $rv[1];
			$strlen = $rv[2];
			if ($strlen < strlen($value0) - 2) {
				$value = substr($value0, 2, $strlen);
			}
			else {
				$value = substr($value0, 2);
			}
		}
		else if ($t == "bit") {
			return self::readBitItem($item, $value0);
		}
		else if (! $item["isArray"]) {
			$value = unpack($packFmt, $value0)[1];
		}
		else { // 数组
			$rv = unpack($packFmt.$item["amount"], $value0);
			$value = array_values($rv);
		}
		self::fixInt($item["type"], $value);
		return $value;
	}

	static function readBitItem($item, $value0) {
		$arr = unpack("C*", $value0); // NOTE: index from 1
		$n = $item["amount"];
		if (! $item["isArray"])
			return self::getBit($arr[1], $item["bit"]);

		$rv = [];
		for ($i=$item["bit"], $bi=1; $n > 0; --$n) {
			$rv[] = self::getBit($arr[$bi], $i);
			if ($i == 7) {
				$i = 0;
				++ $bi;
			}
			else {
				++ $i;
			}
		}
		return $rv;
	}

	// item: {code, type, isArray, amount, value}
	protected function writeItem($item) {
		$t = $item["type"];
		if ($t == "bit") {
			if (! $item["isArray"])
				return pack("C", ($item["value"]?1:0));
			return self::packBits($item["value"]);
		}
		$packFmt = self::$typeMap[$t]["fmt"];
		if ($t == "char" || $t == "string") {
			$valuePack = $item["value"];
		}
		else if ($item["isArray"]) { // 数组处理
			$valuePack = '';
			foreach ($item["value"] as $v) {
				$valuePack .= pack($packFmt, $v);
			}
		}
		else {
			$valuePack = pack($packFmt, $item["value"]);
		}
		return $valuePack;
	}

	protected static function getTcpConn($addr, $defaultPort) {
		if (strpos($addr, ':') === false)
			$addr .= ":" . $defaultPort;
		@$fp = fsockopen("tcp://" . $addr, null, $errno, $errstr, 3); // connect timeout=3s
		if ($fp === false) {
			$error = "fail to open tcp connection to `$addr`, error $errno: $errstr";
			throw new PlcAccessException($error);
		}
		stream_set_timeout($fp, 3, 0); // read timeout=3s
		return $fp;
	}

	// return: {code, type, isArray, amount, value?}
	// value=null means for read item
	protected function parseItem($itemAddr, $value = null) {
		if (! preg_match('/^(?<code>.*):(?<type>\w+) (?:\[(?<amount>\d+)\])?$/x', $itemAddr, $ms)) {
			$error = "bad plc item addr: `$itemAddr`";
			throw new PlcAccessException($error);
		}
		if (array_key_exists($ms["type"], self::$typeAlias)) {
			$ms["type"] = self::$typeAlias[$ms["type"]];
		}
		$item = [
			"code"=>$ms["code"],
			"type"=>$ms["type"],
			"isArray" => isset($ms["amount"]),
			"amount" => (@$ms["amount"]?:1),
			"bit" => 0
		];
		if ($value !== null) { // for write, NOTE: value CAN NOT be null!
			// char and string is specical!
			if ($item["type"] == "char") {
				$diff = $item["amount"] - strlen($value);
				if ($diff > 0) { // pad 0 if not enough
					$value .= str_repeat("\x00", $diff);
				}
				else if ($diff < 0) { // trunk if too long
					$value = substr($value, 0, $item["amount"]);
				}
			}
			else if ($item["type"] == "string") {
				$diff = $item["amount"] - strlen($value);
				if ($diff < 0) { // trunk if too long
					$value = substr($value, 0, $item["amount"]);
				}
				$value = pack("CC", $item["amount"], strlen($value)) . $value;
				$item["amount"] = strlen($value);
			}
			else if ($item["isArray"]) {
				if (! is_array($value)) {
					$error = "require array value for $itemAddr";
					throw new PlcAccessException($error);
				}
				// 自动截断或补0
				$diff = $item["amount"] - count($value);
				// $error = "bad array amount for $itemAddr";
				if ($diff < 0) {
					$value = array_slice($value, 0, $item["amount"]);
				}
				else if ($diff > 0) {
					while ($diff-- != 0) {
						$value[] = 0;
					}
				}
			}
			$item["value"] = $value;
		}
		else {
			if ($item["type"] == "string") {
				$item["amount"] += 2;
			}
		}
		return $item;
	}

	// 无符号转有符号
	protected static function fixInt($type, &$value) {
		if (is_array($value)) {
			foreach ($value as &$v) {
				self::fixInt($type, $v);
			}
			unset($v);
			return;
		}
		if ($type == "int16") {
			if ($value > 0x8000)
				$value -= 0x10000;
		}
		else if ($type == "int32") {
			if ($value > 0x80000000)
				$value -= 0x100000000;
		}
	}

	private static function getBit($x, $n) {
		return ($x >> $n) & 1;
	}

	private static function packBits($bitArr) {
		$i = 0;
		$byte = 0;
		$ret = '';
		foreach ($bitArr as $v) {
			if (!is_int($v))
				$v = intval($v);
			$byte |= (($v & 0x1) << $i);
			if ($i++ == 8) {
				$ret .= pack("C", $byte);
				$byte = 0;
				$i = 0;
			}
		}
		if ($i) {
			$ret .= pack("C", $byte);
		}
		return $ret;
	}

	/*
	private static function unpackBits($res, $pos, $bitCnt) {
		$value = [];
		for ($i=0,$j=8; $i<$bitCnt; ++$i,++$j) {
			if ($j == 8) {
				$j = 0;
				$byte = ord($res[$pos ++]);
			}
			if ($byte & (0x01 << $j)) {
				$value[] = 1;
			}
			else {
				$value[] = 0;
			}
		}
		return $value;
	}
	*/
}

class PlcMockClient extends PlcAccess
{
	static private $values = []; // $itemCode => $value

	// items: [ "S1.0:word", "S1.4:float" ]
	// items: [ "S1.0:word[2]", "S1.4:float[2]" ]
	// items: [ "S1.0:bit", "S1.4:bit[4]" ]
	function read($items) {
		$items1 = parent::read($items);
		$ret = [];
		foreach ($items1 as $item) {
			// item: {code, type, amount, value?, slaveId, startAddr}
			$value = 0;
			if (array_key_exists($item["code"], self::$values)) {
				$value0 = self::$values[$item["code"]];
				$value = $this->readItem($item, $value0);
			}
			else {
				if ($item["type"] == "char" || $item["type"] == "string") {
					$value = "";
				}
				else if ($item["isArray"]) {
					$value = [];
					for ($i=0; $i<$item["amount"]; ++$i) {
						$value[] = 0;
					}
				}
			}
			$ret[] = $value;
		}
		return $ret;
	}

	// items: [ ["S1.0:word", 30000], ["S1.4:float", 3.14]  ]
	// items: [ ["S1.0:word[2]", [30000, 50000]], ["S1.4:float[2]", [3.14,99.8]]  ]
	// items: [ ["S1.0:bit", 1], ["S1.4:bit[4]", [1,1,1,1]] ]
	function write($items) {
		$items1 = parent::write($items);
		foreach ($items1 as $item) {
			// item: {code, type, amount, value, slaveId, startAddr}
			self::$values[$item["code"]] = $this->writeItem($item);
		}
		return "write ok";
	}
}

