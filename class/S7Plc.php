<?php

/*
@class S7Plc
@author liangjian

Usage (level 1): read/write once (short connection)

	$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int32", "DB21.4:float"], $error);
	// on success $res=[ 30000, 3.14 ]
	if ($res === false)
		echo($error); // $error holds the last error message.

Usage (level 2): read and write in one connection (long connection)

	$plc = new S7Plc("192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$res = $plc->read(["DB21.0:int32", "DB21.4:float"]);
	// on success $res=[ 30000, 3.14 ]
	if ($res === false)
		echo($plc->error);
	// $plc = null; // close connection immediately

Read Request/Response Packet:

	TPKT 4B
	COTP 3B
	S7ReqHeader 10B
	ReqParam 2B
	 ReqData
	 ...


	TPKT 4B
	COTP 3B
	S7ResHeader 12B
	ResParam 2B
	 ResData
	 ...
*/
class S7Plc
{
	public $error;
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

	protected function getConn() {
		if ($this->fp === null) {
			$addr = $this->addr;
			if (strpos($addr, ':') === false)
				$addr .= ":102"; // default s7 port
			$fp = fsockopen("tcp://" . $addr);
			if ($fp === false) {
				$this->error = "fail to open tcp connection to `$addr`";
				return false;
			}
			$this->fp = $fp;
		}
		return $this->fp;
	}

	function lastError() {
		return $this->error;
	}

	// items: ["DB21.0:int32", "DB21.4:float"]
	function read($items) {
		$items1 = [];
		foreach ($items as $addr) {
			if (! preg_match('/^DB(\d+)\.(\d+):(\w+)(?:\[(\d+)\])?$/', $addr, $ms)) {
				$this->error = "bad plc item addr: $addr";
				return false;
			}
			$items1[] = [
				"dbNumber"=>$ms[1],
				"startAddr"=>$ms[2],
				"type"=>$ms[3],
				"amount" => ($ms[4]?:1)
			];
		}

		$readPacket = $this->buildReadPacket($items1);
		$fp = $this->getConn();
		if ($fp === false)
			return false;
		$rv = fwrite($fp, $readPacket);

		$res = fread($fp, 4096);
		if (!$res) {
			$this->error = "receive null response";
			return false;
		}

		$version = unpack("C", $res[0])[1]; // TPKT check
		if ($version != 3) {
			$this->error = "bad response: bad protocol";
			return false;
		}
		$payloadSize = unpack("n", substr($res,2,2))[1]; // TODO: check size
		// TODO: 包可能没收全

		$pos = 7; // TPKT+COTP
		$S7ResHeader23 = myunpack(substr($res, $pos, 12), [
			"C", "P", // Telegram ID, always 0x32
			"C", "PDUType", // Header type 2 or 3
			"n", "AB_EX",
			"n", "Sequence",
			"n", "ParamLen",
			"n", "DataLen",
			"n", "Error"
		]);
		if ($S7ResHeader23['Error']!=0) {
			$this->error = 'server returns error: ' . $S7ResHeader23['Error'];
			return false;
		}

		$pos += 12;
		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunRead",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items)) {
			$this->error = 'bad server item count: ' . $ResParams["ItemCount"];
			return false;
		}
		$pos += 2;

		// S7DataItem
		$typeMap = [
			"int8" => "C",
			"int16" => "n",
			"int32" => "V",
			"float" => "f",
		];
		$ret = [];
		for ($i = 0; $i < count($items1); $i++) {
			$ResData = myunpack(substr($res, $pos, 10), [
				"C", "ReturnCode",
				"C", "TransportSize",
				"n", "DataLen",
				// data
			]);
			$retCode = $ResData["ReturnCode"];
			if ($retCode != 0xff) { // <-- 0xFF means Result OK
				$this->error = "fail to read {$items1[$i]}: return code=$retCode";
				return false;
			}
			$len = $ResData['DataLen'];
			if ($ResData['TransportSize'] != 0x09 /* TS_ResOctet */
					&& $ResData['TransportSize'] != 0x07 /* TS_ResReal */
					&& $ResData['TransportSize'] != 0x03 /* TS_ResBit */
				) {
				$len /= 8; // bit数转byte数
			}
			$pos += 4;
			$value = substr($res, $pos, $len);
			$type = $typeMap[$items1[$i]['type']];
			$value1 = unpack($type, $value)[1];
			$ret[] = $value1;

			if ($len % 2 != 0) {
				++ $len;  // Skip fill byte for Odd frame
			}
			$pos += $len;
		};
		return $ret;
	}

	static function readPlc($addr, $items, &$error) {
		$plc = new S7Plc($addr);
		$rv = $plc->read($items);
		if ($rv === false)
			$error = $plc->error;
		return $rv;
	}

	// items: [{ dbNumber, type=int8/int16/int32/float/double, startAddr, amount }]
	protected function buildReadPacket($items) {
		$ReqParams = mypack([
			"C", 0x04, // FunRead=pduFuncRead
			"C", count($items), // ItemsCount
		]);
		$lenMap = [
			"int8" => 1,
			"int16" => 2,
			"int32" => 4,
			"float" => 4,
			"double" => 8
		];
		foreach ($items as $item) {
			$ReqFunReadItem = mypack([
				'C', 0x12,
				'C', 0x0A,
				'C', 0x10,
				'C', 0x02,// bit:1, byte:2, char:3, word:4, int:5, dword=6, dint=7, real=8
				"n", $item['amount'] * $lenMap[$item['type']],
				"n", $item['dbNumber'],
				// 'C', 0x84, // area: S7AreaDB; 位置: 8
				'N', (0x84000000 | ($item['startAddr'] * 8)) // 起始地址，按字节计转按位计。注意：这里需要修正，它只用了3B，与上1字节一起是4B
			]);
			$ReqParams .= $ReqFunReadItem;
		}
		$RPSize = strlen($ReqParams);

		$S7ReqHeader = mypack([
			"C", 0x32, // Telegram ID, always 32
			"C", 0x01, // PduType_request
			"n", 0, // AB_EX: AB currently unknown, maybe it can be used for long numbers.
			"n", 0, // Sequence; // Message ID. This can be used to make sure a received answer; TODO: GetNextWord
			"n", $RPSize, // Length of parameters which follow this header
			"n", 0 // DataLen: Length of data which follow the parameters; 0: No data in output
		]);
		$payload = $S7ReqHeader . $ReqParams;
		$TPKT = mypack([
			"C", 3, // version: isoTcpVersion
			"C", 0, // reserved: 0
			"n", strlen($payload)+7, // length + header length
		]);
		$COTP = mypack([
			"C", 2, // headerLength(下面2B)
			"C", 0xF0, // PDUType: pdu_type_DT
			"C", 0x80, // EoT_Num: pdu_EoT
		]);

		return $TPKT . $COTP . $payload;
	}
}

