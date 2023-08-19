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
		foreach ($items1 as $i=>$item) {
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
				$error = "item `{$items[$i]}`: wrong response byte count: expect $expectedCnt, actual $byteCnt";
				throw new PlcAccessException($error);
			}
			$value0 = substr($res, $pos, $byteCnt);
			$value = $this->readItem($item, $value0);
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

	// item: {code, type, amount, value, slaveId, startAddr}
	protected function buildWritePacket($item) {
		$valuePack = $this->writeItem($item);
		$dataLen = strlen($valuePack);
		if ($item["type"] == "bit") {
			if ($item["isArray"]) { // bit数组
				$cnt = count($item["value"]);
			}
			else {
				$cnt = 1;
			}
		}
		else {
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
			// stream_set_timeout($this->fp, 0, 100000); // 测试超时
		}
		// 异常时关闭连接，确保单例再连接时安全
		$ok = false;
		$g = new Guard(function () use (&$ok) {
			if ($ok)
				return;
			fclose($this->fp);
			$this->fp = null;
		});

		$fp = $this->fp;
		$rv = fwrite($fp, $req);

		$res = fread($fp, 4096);
		// TODO: 包可能没收全, 应根据下面长度判断
		if (!$res) {
			if (feof($this->fp))
				$error = "connection lost";
			else
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
		// 比较transId一致
		if (substr($res, 0, 2) != substr($req, 0, 2)) {
			$error = "transId mismatch";
			throw new PlcAccessException($error);
		}
		$pos = 8;
		$ok = true;
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
