<?php

class AC_Plc extends JDApiBase
{
	function api_read() {
		$plcCode = mparam("code");
		$items = explode(',', mparam("items"));
		if (count($items) == 0)
			return;

		$conf = self::loadPlcConf();
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

		return self::readItems($plcConf, $items);
	}

	static protected function loadPlcConf() {
		$confStr = file_get_contents("plc.json");
		if (! $confStr)
			return [];
		$conf = jsonDecode($confStr);
		if (!$conf)
			jdRet(E_SERVER, "bad conf plc.json");
		// auto add 'code' field
		foreach ($conf as $code => &$plcConf) {
			$plcConf["code"] = $code;
		}
		unset($plcConf);
		return $conf;
	}

	function api_write() {
		$plcCode = mparam("code");
		$items = $this->env->_POST;
		if (count($items) == 0)
			return;

		$conf = self::loadPlcConf();
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
		self::writeItems($plcConf, $items);
		return "write plc ok";
	}

	static protected function readItems($plcConf, $items, $plcObj = null) {
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
				jdRet(E_PARAM, "unknown plc item: {$plcConf['code']}.$itemCode");
		}
		if ($plcObj == null) {
			$plcObj = Plc::create($plcConf["addr"]);
		}
		$res = $plcObj->read($items1);
		$ret = array_combine($items, $res);
		return $ret;
	}

	static protected function writeItems($plcConf, $items, $plcObj = null) {
		$items1 = []; // elem: [addr, value]
		foreach ($items as $itemCode=>$value) { // code=>value
			$found = false;
			foreach ($plcConf["items"] as $itemCode0 => $item) {
				if ($itemCode == $itemCode0) {
					$items1[] = [$item["addr"], $value];
					$found = true;
				}
			}
			if (!$found)
				jdRet(E_PARAM, "unknown plc item: {$plcConf['code']}.$itemCode");
		}
		if ($plcObj == null) {
			$plcObj = Plc::create($plcConf["addr"]);
		}
		$res = $plcObj->write($items1);
	}

	static protected function handleWatchItems($plcConf, $watchItems) {
		if (! $plcConf['notifyUrl'])
			jdRet(E_SERVER, "require plc.notifyUrl for watch items", "PLC配置错误");
		$oldValues = [];
		$itemAddrList = [];
		$itemCodeList = [];
		foreach ($watchItems as $code=>$e) {
			$itemAddrList[] = $e['addr'];
			$itemCodeList[] = $code;
		}

		Plc::watchPlc($plcConf['addr'], $itemAddrList, function ($plcObj, $values) use ($plcConf, $watchItems, $itemCodeList, &$oldValues) {
			foreach ($values as $i=>$value) {
				$old = $oldValues[$i];
				if ($old == null) {
					$oldValues[$i] = $value;
				}
				else if ($old == $value) {
					// nothing to do
				}
				else {
					$oldValues[$i] = $value;
					$code = $itemCodeList[$i];
					$watch = $watchItems[$code]['watch'];
					$post = [$code => $value];
					echo("!!! item `$code` value change: $old => $value\n");
					if (is_array($watch)) {
						$res = self::readItems($plcConf, $watch, $plcObj);
						$post += $res;
					}
					$url = $plcConf['notifyUrl'];
					echo("!!! notify $url: " . jsonEncode($post) ."\n");
					httpCall($url, $post);
				}
			}
		});
	}

	static function init() {
		$conf = self::loadPlcConf();
		foreach ($conf as $plcCode => $plcConf) {
			if ($plcConf['disabled'])
				continue;
			$watchItems = array_filter($plcConf['items'], function ($item) {
				return !$item['disabled'] && isset($item['watch']);
			});

			if (count($watchItems) > 0) { // {itemCode=>item}
				go(function () use ($plcConf, $watchItems) {
					try {
						self::handleWatchItems($plcConf, $watchItems);
					}
					catch (Exception $ex) {
						echo("*** handleWatchItems fails: $ex\n");
					}
				});
			}
		}
	}

	function api_test() {
		//$rv = Plc::readPlc("127.0.0.1", ["DB21.0:int32", "DB21.4:float"]);
		$rv = Plc::writePlc("127.0.0.1", [["DB21.0:int32", 90000], ["DB21.4:float", 3.14]]);
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

	static function writePlc($addr, $items) {
		$plc = self::create($addr);
		$rv = $plc->write($items);
		return $rv;
	}

	static function watchPlc($addr, $items, $cb) {
		while (true) {
			try {
				$plc = self::create($addr);
				while (true) {
					$res = $plc->read($items);
					$cb($plc, $res);
					sleep(1);
				}
			}
			catch (Exception $ex) {
				logit($ex);
			}
		}
	}
}

