<?php

/*
@class ModbusClient
@author liangjian <liangjian@oliveche.com>

Usage (level 1): read/write once (short connection)

	try {
		// S1.0:dword - slave1, addr 0 (NOTE: word addr from 0)
		ModbusClient::writePlc("192.168.1.101", [["S1.0:dword", 70000], ["S2.0:word[2]", [30000,50000]], ["S3.0:float", 3.14]]);

		$res = ModbusClient::readPlc("192.168.1.101", ["S1.0", "S2.0:word[2]", "S3.0:float"]);
		// on success $res=[ 70000, [30000,50000], 3.14 ]
	}
	catch (ModbusException $ex) {
		echo($ex->getMessage());
	}

Usage (level 2): read and write in one connection (long connection)

	try {
		$plc = new ModbusClient("192.168.1.101"); // default tcp port 502: "192.168.1.101:502"
		$plc->write([["S1.0:dword", 70000], ["S2.0:word[2]", [30000,50000]], ["S3.0:float", 3.14]]);
		$res = $plc->read(["S1.0:dword", "S2.0:word[2]", "S3.0:float"]);
		// on success $res=[ 70000, [30000,50000], 3.14 ]
	}
	catch (ModbusException $ex) {
		echo($ex->getMessage());
	}
fail code:

        0x01 => "ILLEGAL FUNCTION",
        0x02 => "ILLEGAL DATA ADDRESS",
        0x03 => "ILLEGAL DATA VALUE",
        0x04 => "SLAVE DEVICE FAILURE",
        0x05 => "ACKNOWLEDGE",
        0x06 => "SLAVE DEVICE BUSY",
        0x08 => "MEMORY PARITY ERROR",
        0x0A => "GATEWAY PATH UNAVAILABLE",
        0x0B => "GATEWAY TARGET DEVICE FAILED TO RESPOND");
*/

class ModbusException extends LogicException 
{
}

class ModbusClient
{
	protected $addr;
	protected $fp;

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
		"bit" => ["fmt"=>"C", "len"=>0.125],
		"int8" => ["fmt"=>"C", "len"=>1],
		"uint8" => ["fmt"=>"C", "len"=>1],

		"int16" => ["fmt"=>"n", "len"=>2],
		"uint16" => ["fmt"=>"n", "len"=>2],

		"int32" => ["fmt"=>"N", "len"=>4],
		"uint32" => ["fmt"=>"N", "len"=>4],

		"float" => ["fmt"=>"f", "len"=>4],
		"char" => ["fmt"=>"a", "len"=>1]
		// "double" => ["fmt"=>"?", "len"=>8, "WordLen"=>0x0?, "TransportSize"=>0x0?],
		// "string[]"
	];

	function __construct($addr) {
		$this->addr = $addr;
	}

	function __destruct() {
		if ($this->fp) {
			fclose($this->fp);
			$this->fp = null;
		}
	}

	protected function getConn() {
		if ($this->fp === null) {
			$addr = $this->addr;
			if (strpos($addr, ':') === false)
				$addr .= ":502"; // default modbus-tcp port
			@$fp = fsockopen("tcp://" . $addr, null, $errno, $errstr, 3); // connect timeout=3s
			if ($fp === false) {
				$error = "fail to open tcp connection to `$addr`, error $errno: $errstr";
				throw new ModbusException($error);
			}
			stream_set_timeout($fp, 3, 0); // read timeout=3s
			$this->fp = $fp;
		}
		return $this->fp;
	}

	// items: [ "S1.0:word", "S1.4:float" ]
	// items: [ "S1.0:word[2]", "S1.4:float[2]" ]
	// items: [ "S1.0:bit", "S1.4:bit[4]" ]
	function read($items) {
		$ret = [];
		foreach ($items as $addr) {
			$item = $this->parseItem($addr);
			// item: { slaveId, type, startAddr, amount }
			$readPacket = $this->buildReadPacket($item);
			$res = $this->req($readPacket, $pos);
			$byteCnt = unpack("C", $res[$pos])[1];
			++ $pos;
			if ($item["type"] != "bit") {
				$expectedCnt = self::$typeMap[$item["type"]]["len"] * $item["amount"];
				if ($expectedCnt % 2 != 0)
					++ $expectedCnt;
			}
			else {
				$expectedCnt = ceil($item["amount"] / 8);
			}
			if ($expectedCnt != $byteCnt) {
				$error = "item `$addr`: wrong response byte count: expect $expectedCnt, actual $byteCnt";
				throw new ModbusException($error);
			}
			$t = $item["type"];
			$fmt = self::$typeMap[$t]["fmt"];
			if ($item["type"] == "bit") {
				if ($item["amount"] == 1) {
					$value = ord($res[$pos]) & 0x1;
				}
				else { // bit数组
					$value = self::unpackBits($res, $pos, $item["amount"]);
				}
			}
			else {
				if ($item["amount"] == 1) {
					$value = unpack($fmt, substr($res, $pos, $byteCnt))[1];
				}
				else { // 数组
					$rv = unpack($fmt.$item["amount"], substr($res, $pos, $byteCnt));
					$value = array_values($rv);
				}
				self::fixInt($item["type"], $value);
			}
			$ret[] = $value;
		}
		return $ret;
	}

	private static function fixInt($type, &$value) {
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

	// items: [ ["S1.0:word", 30000], ["S1.4:float", 3.14]  ]
	// items: [ ["S1.0:word[2]", [30000, 50000]], ["S1.4:float[2]", [3.14,99.8]]  ]
	// items: [ ["S1.0:bit", 1], ["S1.4:bit[4]", [1,1,1,1]] ]
	function write($items) {
		foreach ($items as $e) {
			$item = $this->parseItem($e[0], $e[1]);
			// item: { slaveId, type, startAddr, amount, value }
			$writePacket = $this->buildWritePacket($item);
			$res = $this->req($writePacket, $pos);
		}
		return "write ok";
	}

	static function readPlc($addr, $items) {
		$plc = new ModbusClient($addr);
		return $plc->read($items);
	}
	static function writePlc($addr, $items) {
		$plc = new ModbusClient($addr);
		return $plc->write($items);
	}

	// item: { slaveId, type, startAddr, amount }
	protected function buildReadPacket($item) {
		if ($item["type"] != "bit") {
			$byteCnt = self::$typeMap[$item["type"]]["len"] * $item["amount"];
			$amount = (int)ceil($byteCnt / 2); // word字数
		}
		else {
			$amount = $item["amount"]; // bit位数
		}
		$req = mypack([
			"n", rand(0,65000), // trans id
			"n", 0, // protocol id
			"n", 6, // length
			"C", $item["slaveId"],
			"C", $item['type'] == 'bit'? 1: 3, // FC 1:read coil, FC 3:read register
			"n", $item["startAddr"],
			"n", $amount,
		]);
		return $req;
	}

	private static function packBits($bitArr) {
		$i = 0;
		$byte = 0;
		$ret = '';
		foreach ($bitArr as $v) {
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
	private function unpackBits($res, $pos, $bitCnt) {
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

	// items: [{ slaveId, type=int8/int16/int32/float, startAddr, amount, value }]
	protected function buildWritePacket($item) {
		if ($item["type"] == "bit") {
			if ($item["amount"] == 1) {
				$valuePack = pack("C", $item["value"] & 0x1);
				$cnt = 1;
			}
			else { // bit数组
				$cnt = count($item["value"]);
				$valuePack = self::packBits($item["value"]);
			}
			$dataLen = strlen($valuePack);
		}
		else {
			$fmt = self::$typeMap[$item["type"]]["fmt"];
			if ($item["amount"] > 1) { // 数组处理
				$valuePack = '';
				foreach ($item["value"] as $v) {
					$valuePack .= pack($fmt, $v);
				}
			}
			else {
				$valuePack = pack($fmt, $item["value"]);
			}
			$dataLen = strlen($valuePack);
			if ($dataLen % 2 != 0) { // 补为偶数字节
				++ $dataLen;
				$valuePack .= "\x00";
			}
			$cnt = $dataLen / 2;
		}

		$reqHeader = mypack([
			"C", $item["type"]=="bit"? 15: 16, // FC 15: write coils, FC 16: write registers
			"n", $item["startAddr"],
			"n", $cnt, // word count or bit count
			"C", $dataLen // byte count
		]);
		$dataLen += 6;

		$protoHeader = mypack([
			"n", rand(0,65000), // trans id
			"n", 0, // protocol id
			"n", $dataLen + 1, // length
			"C", $item["slaveId"]
		]);
		return $protoHeader . $reqHeader . $valuePack;
	}

	// $pos: 内容开始位置
	protected function req($req, &$pos) {
		$fp = $this->getConn();
		$rv = fwrite($fp, $req);

		$res = fread($fp, 4096);
		// TODO: 包可能没收全, 应根据下面长度判断
		if (!$res) {
			$error = "read timeout or receive null response";
			throw new ModbusException($error);
		}
		$header = myunpack(substr($res, 0, 8), [
			"n", "transId",
			"n", "protoId",
			"n", "dataLen",
			"C", "deviceId",
			"C", "fnCode",
		]);
		if (($header["fnCode"] & 0x80) != 0) {
			$failCode = ord($res[8]);
			$error = "response fail code=$failCode";
			throw new ModbusException($error);
		}
		$pos = 8;
		return $res;
	}

	// return: {slaveId, startAddr, type, amount}
	protected function parseItem($itemAddr, $value=null) {
		if (! preg_match('/^S(?<slaveId>\d+) \.(?<addr>\d+) :(?<type>\w+) (?:\[(?<amount>\d+)\])?$/x', $itemAddr, $ms)) {
			$error = "bad modbus item addr: `$itemAddr`";
			throw new ModbusException($error);
		}
		if (array_key_exists($ms["type"], self::$typeAlias)) {
			$ms["type"] = self::$typeAlias[$ms["type"]];
		}
		else if (! array_key_exists($ms["type"], self::$typeMap)) {
			$error = "unknown modbus item type: `$itemAddr`";
			throw new ModbusException($error);
		}
		$item1 = [
			"slaveId"=>$ms["slaveId"],
			"startAddr"=>$ms["addr"],
			"type"=>$ms["type"],
			"amount" => (@$ms["amount"]?:1)
		];
		if ($value !== null) {
			if ($item1["amount"] > 1) {
				if (! is_array($value)) {
					$error = "require array value for $itemAddr";
					throw new ModbusException($error);
				}
				if (count($value) != $item1["amount"]) {
					$error = "bad array amount for $itemAddr";
					throw new ModbusException($error);
				}
			}
			$item1["value"] = $value;
		}
		return $item1;
	}
}
