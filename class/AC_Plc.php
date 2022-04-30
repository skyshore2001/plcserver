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

		return self::readItems($plcConf, $items);
	}

	function api_write() {
		$confStr = file_get_contents("plc.json");
		$conf = jsonDecode($confStr);
		if (!$conf)
			jdRet(E_SERVER, "bad conf plc.json");
		$plcCode = mparam("code");
		$items = $this->env->_POST;
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
		self::writeItems($plcConf, $items);
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
				jdRet(E_PARAM, "unknown plc item: $plcCode.$itemCode");
		}
		if ($plcObj) {
			$res = $plcObj->read($items1);
		}
		else {
			$res = Plc::readPlc($plcConf["addr"], $items1);
		}
		if ($res === false)
			jdRet(E_EXT, "fail to read plc", "读数据失败");
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
				jdRet(E_PARAM, "unknown plc item: $plcCode.$itemCode");
		}
		if ($plcObj) {
			$res = $plcObj->write($items1);
		}
		else {
			$res = Plc::writePlc($plcConf["addr"], $items1);
		}
		if ($res === false)
			jdRet(E_EXT, "fail to write plc", "写数据失败");
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
		$confStr = file_get_contents("plc.json");
		$conf = jsonDecode($confStr);
		if (!$conf)
			jdRet(E_SERVER, "bad conf plc.json");

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

	static function writePlc($addr, $items) {
		$plc = self::create($addr);
		$plc->write($items);
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

