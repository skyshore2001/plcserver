<?php

class AC_Plc extends JDApiBase
{
	function api_read() {
		$confStr = file_get_contents("plc.json");
		$conf = jsonDecode($confStr);
		if (!$conf)
			jdRet(E_SERVER, "bad conf plc.json");
		$plcCode = mparam("code");
		$items = explode(',', mparam("items"));
		if (count($items) == 0)
			return;

		$found = false;
		foreach ($conf as $plcCode0 => $plcConf) {
			if ($plcCode0 == $plcCode) {
				if ($plcConf["disabled"])
					jdRet(E_FORBIDDEN, "plc $plcCode is disabled");
				$found = true;
				break;
			}
		}
		if (!$found)
			jdRet(E_PARAM, "unknown plc $plcCode");

		$items1 = [];
		foreach ($items as $itemCode) {
			$found = false;
			foreach ($plcConf["items"] as $itemCode0 => $item) {
				if ($itemCode == $itemCode0) {
					$addr = $item["addr"];
					if (! preg_match('/^DB(\d+)\.(\d+):(\w+)(?:\[(\d+)\])?$/', $addr, $ms))
						jdRet(E_SERVER, "bad plc item addr: $addr", "配置项错误");
					$items1[] = [
						"dbNumber"=>$ms[1],
						"startAddr"=>$ms[2],
						"type"=>$ms[3],
						"amount" => ($ms[4]?:1)
					];
					$found = true;
				}
			}
			if (!$found)
				jdRet(E_PARAM, "unknown plc item: $plcCode.$itemCode");
		}
		$res = S7Plc::readPlc($items1);
		$ret = [];
		foreach ($res as $i=>$one) {
			if ($one["code"] != 0)
				jdRet(E_SERVER, "fail to read some item");
			$ret[$items[$i]] = $one["value"];
		}
		return $ret;
	}

	function api_test() {
		return S7Plc::test();
	}
}

/*
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
	// items: [{ dbNumber, type=int8/int16/int32/float/double, startAddr, amount }]
	static function readPlc($items) {
	//	$setupData = buildSetupReq();
		$payload = buildReadRequest($items);
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

		$data = $TPKT . $COTP . $payload;
		$fp = fsockopen("tcp://127.0.0.1:102");
	//	$rv = fwrite($fp, $setupData);
	//	$res = fread($fp, 4096);

		$rv = fwrite($fp, $data);
		$res = fread($fp, 4096);
		fclose($fp);
		if (!$res)
			jdRet(E_SERVER, "bad response");

		$version = unpack("C", $res[0])[1]; // TPKT check
		if ($version != 3)
			jdRet("bad protocol");
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
		if ($S7ResHeader23['Error']!=0)
			jdRet(E_SERVER, 'server return error: ' . $S7ResHeader23['Error']);

		$pos += 12;
		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunRead",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items))
			jdRet(E_SERVER, 'bad server item count: ' . $ResParams["ItemCount"]);
		$pos += 2;

		$ret = [];
		// S7DataItem
		$typeMap = [
			"int8" => "C",
			"int16" => "n",
			"int32" => "V",
			"float" => "f",
		];
		for ($i = 0; $i < count($items); $i++) {
			$ResData = myunpack(substr($res, $pos, 10), [
				"C", "ReturnCode",
				"C", "TransportSize",
				"n", "DataLen",
				// data
			]);
			$retItem = ["code" => $ResData["ReturnCode"], "value"=>null];
			if ($retItem["code"] == 0xff) { // <-- 0xFF means Result OK
				$retItem["code"] = 0;
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
			$type = $typeMap[$items[$i]['type']];
			$retItem["value"] = unpack($type, $value)[1];

			if ($len % 2 != 0) {
				++ $len;  // Skip fill byte for Odd frame
			}
			$pos += $len;
			$ret[] = $retItem;
		};
		return $ret;
	}

	// items: [{ dbNumber, type=int8/int16/int32/float/double, startAddr, amount }]
	static function buildReadRequest($items) {
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
		return $S7ReqHeader . $ReqParams;
	}

	static function test() {
		$rv = S7Plc::readPlc([
			["dbNumber"=>21, "startAddr"=>0, "amount"=>1, "type"=>"int32"],
			["dbNumber"=>21, "startAddr"=>4, "amount"=>1, "type"=>"float"],
		]);
	}
}

