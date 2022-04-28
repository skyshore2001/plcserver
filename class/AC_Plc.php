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
					$items1[] = $item["addr"];
					$found = true;
				}
			}
			if (!$found)
				jdRet(E_PARAM, "unknown plc item: $plcCode.$itemCode");
		}
		$res = Plc::readPlc($plcConf["addr"], $items1);
		if ($res === false)
			jdRet(E_EXT, "fail to read plc", "读数据失败");
		$ret = array_combine($items, $res);
		return $ret;
	}

	function api_test() {
		$rv = S7Plc::readPlc("127.0.0.1", ["DB21.0:int32", "DB21.4:float"], $error);
		if ($rv === false)
			jdRet(E_EXT, $error);
		return $rv;
	}
}

class Plc
{
	static function create($addr) {
		$rv = parse_url($addr);
		if ($rv['scheme'] == 's7') {
			$addr1 = str_replace('s7://', '', $addr);
			return new S7Plc($addr1);
		}
		jdRet(E_PARAM, "unknonw plc addr type: `{$rv['schema']}`", "PLC地址错误: $addr"); 
	}

	static function readPlc($addr, $items) {
		$plc = self::create($addr);
		return $plc->read($items);
	}

/*
	static function watchPlc($addr, $items, $cb) {
		while (true) {
			try {
				$plc = self::create($addr);
				while (true) {
					$res = $plc->read($items);
					if (todo_changed($res)) {
						$cb();
					}
					sleep(1);
				}
			}
			catch (Exception $ex) {
				logit($ex);
			}
			$a = null;
		}
	}
*/
}

