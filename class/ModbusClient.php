<?php

/*
@class ModbusClient
@author liangjian <liangjian@oliveche.com>

Usage: read and write

	try {
		$plc = PlcAccess::create("modbus", "192.168.1.101"); // default tcp port 502: "192.168.1.101:502"
		$plc->write([["S1.0:dword", 70000], ["S2.0:word[2]", [30000,50000]], ["S3.0:float", 3.14]]);
		$res = $plc->read(["S1.0:dword", "S2.0:word[2]", "S3.0:float"]);
		// on success $res=[ 70000, [30000,50000], 3.14 ]
	}
	catch (PlcAccessException $ex) {
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
class ModbusClient extends PlcAccess
{
	protected $addr;
	protected $fp;

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
		"char" => ["fmt"=>"a", "len"=>1],
		"string" => ["fmt"=>"a", "len"=>1]
		// "double" => ["fmt"=>"?", "len"=>8, "WordLen"=>0x0?, "TransportSize"=>0x0?],
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

	// items: [ "S1.0:word", "S1.4:float" ]
	// items: [ "S1.0:word[2]", "S1.4:float[2]" ]
	// items: [ "S1.0:bit", "S1.4:bit[4]" ]
	function read($items) {
		$items1 = parent::read($items);
		$ret = [];
		foreach ($items1 as $item) {
			// item: {code, type, amount, value?, slaveId, startAddr}
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
				throw new PlcAccessException($error);
			}
			$t = $item["type"];
			if ($item["type"] == "bit") {
				if (! $item["isArray"]) {
					$value = ord($res[$pos]) & 0x1;
				}
				else { // bit数组
					$value = self::unpackBits($res, $pos, $item["amount"]);
				}
			}
			else {
				$value0 = substr($res, $pos, $byteCnt);
				$packFmt = self::$typeMap[$t]["fmt"];
				$value = $this->readItem($item, $packFmt, $value0);
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
			$writePacket = $this->buildWritePacket($item);
			$res = $this->req($writePacket, $pos);
		}
		return "write ok";
	}

	// item: {code, type, amount, slaveId, startAddr}
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

	// item: {code, type, amount, value, slaveId, startAddr}
	protected function buildWritePacket($item) {
		if ($item["type"] == "bit") {
			if (! $item["isArray"]) {
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
			$t = $item["type"];
			$packFmt = self::$typeMap[$t]["fmt"];
			$valuePack = $this->writeItem($item, $packFmt);
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
		if ($this->fp === null) {
			$this->fp = self::getTcpConn($this->addr, 502); // default modbus port
		}
		$fp = $this->fp;
		$rv = fwrite($fp, $req);

		$res = fread($fp, 4096);
		// TODO: 包可能没收全, 应根据下面长度判断
		if (!$res) {
			$error = "read timeout or receive null response";
			throw new PlcAccessException($error);
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
			throw new PlcAccessException($error);
		}
		$pos = 8;
		return $res;
	}

	// return: {code, type, isArray, amount, value?, slaveId, startAddr}
	protected function parseItem($itemAddr, $value=null) {
		$item = parent::parseItem($itemAddr, $value);
		if (! preg_match('/^S(?<slaveId>\d+) \.(?<startAddr>\d+)$/x', $item["code"], $ms)) {
			$error = "bad modbus item: `{$item['code']}`";
			throw new PlcAccessException($error);
		}
		if (! array_key_exists($item["type"], self::$typeMap)) {
			$error = "unsupport modbus item type: `$itemAddr`";
			throw new PlcAccessException($error);
		}
		$item["slaveId"] = $ms["slaveId"];
		$item["startAddr"] = $ms["startAddr"];
		return $item;
	}
}
