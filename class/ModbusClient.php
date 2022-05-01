<?php

/*
@class ModbusClient

@auther liangjian <liangjian@oliveche.com>
*/

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
		// TransportSize: TS_ResBit=0x03, TS_ResByte=0x04, TS_ResInt=0x05, TS_ResReal=0x07, TS_ResOctet=0x09
		"bit" => ["fmt"=>"C", "len"=>1, "wordCnt"=>1],
		"int8" => ["fmt"=>"C", "len"=>1, "wordCnt"=>0.5],
		"uint8" => ["fmt"=>"C", "len"=>1, "wordCnt"=>0.5],

		"int16" => ["fmt"=>"n", "len"=>2, "wordCnt"=>1],
		"uint16" => ["fmt"=>"n", "len"=>2, "wordCnt"=>1],

		"int32" => ["fmt"=>"N", "len"=>4, "wordCnt"=>2],
		"uint32" => ["fmt"=>"N", "len"=>4, "wordCnt"=>2],

		"float" => ["fmt"=>"f", "len"=>4, "wordCnt"=>2],
		"char" => ["fmt"=>"a", "len"=>1, "wordCnt"=>2]
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
			$fp = fsockopen("tcp://" . $addr);
			if ($fp === false) {
				$error = "fail to open tcp connection to `$addr`";
				throw new ModbusException($error);
			}
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
			$byteCnt = unpack("C", $pos)[1];
			$ret[] = substr($res, $pos+1, $byteCnt);
		}
		return $ret;
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
		$req = mypack([
			"n", rand(0,65000), // trans id
			"n", 0, // protocol id
			"n", 6, // length
			"C", $item["slaveId"],
			"C", $item['type'] == 'bit'? 1: 3, // FC 1:read coil, FC 3:read register
			"n", $item["startAddr"],
			"n", $item["amount"], // TODO: word count
		]);
		return $req;
	}

	// items: [{ slaveId, type=int8/int16/int32/float, startAddr, amount }]
	protected function buildWritePacket($item) {
		// TODO: bit
		$t = $item["type"];
		$fmt = self::$typeMap[$t]["fmt"];
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
		$reqHeader = mypack([
			"C", $item["type"]=="bit"? 15: 16, // FC 15: write coils, FC 16: write registers
			"n", $item["startAddr"],
			"n", $dataLen/2, // word count
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
			$error = "receive null response";
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
			$error = "reponse fail code=$failCode";
			throw new ModbusException($error);
		}
		$pos = 8;
		return $res;
	}

	// return: {slaveId, startAddr, bit, type, amount}
	protected function parseItem($itemAddr, $value=null) {
		if (! preg_match('/^S(?<slaveId>\d+) \.(?<addr>\d+) (?:\.(?<bit>\d+))? :(?<type>\w+) (?:\[(?<amount>\d+)\])?$/x', $itemAddr, $ms)) {
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
			"bit"=>$ms["bit"]?:0,
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
				if ($item1["type"] == "bit") {
					foreach($value as &$v) {
						$v = $v ? 1: 0;
					}
				}
			}
			else {
				if ($item1["type"] == "bit") {
					$value = $value ? 1: 0;
				}
			}
			$item1["value"] = $value;
		}
		return $item1;
	}
}
