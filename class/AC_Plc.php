<?php

class AC_Plc extends JDApiBase
{
	static $conf, $tmConf;

	function api_read() {
		$plcCode = $this->env->param("code");
		$items = explode(',', $this->env->mparam("items"));
		if (count($items) == 0)
			return;

		$conf = self::loadPlcConf();
		$found = false;
		foreach ($conf as $plcCode0 => $plcConf) {
			if ($plcCode === null)
				$plcCode = $plcCode0;
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
		clearstatcache();
		$tmConf = filemtime("plc.json");
		if ($tmConf === self::$tmConf)
			return self::$conf;

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
		self::$conf = $conf;
		self::$tmConf = $tmConf;
		echo("### load conf: plc.json\n");

		// 部署watch任务
		foreach ($conf as $plcCode => $plcConf) {
			if ($plcConf['disabled'])
				continue;
			$watchItems = array_filter($plcConf['items'], function ($item) {
				return !$item['disabled'] && isset($item['watch']);
			});

			if (count($watchItems) > 0) { // {itemCode=>item}
				// 注意：配置变化后(watchPlc()中检查self::$tmConf)，将自动退出
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
		return $conf;
	}

	function api_write() {
		$plcCode = $this->env->param("code");
		$items = $this->env->_POST;
		if (count($items) == 0)
			return;

		$conf = self::loadPlcConf();
		$found = false;
		foreach ($conf as $plcCode0 => $plcConf) {
			if ($plcCode === null)
				$plcCode = $plcCode0;
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
		if ($items[0] == 'ALL') {
			$items = array_keys($plcConf["items"]);
			$items1 = array_map(function ($item) {
				return $item["addr"];
			}, $plcConf["items"]);
		}
		else {
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
		}
		if ($plcObj == null) {
			$plcObj = self::create($plcConf["addr"]);
		}
		$res = $plcObj->read($items1);
		$ret = array_combine($items, $res);
		return $ret;
	}

	private static function isStringType($type) {
		return preg_match('/:(char|string)/', $type);
	}
	static protected function writeItems($plcConf, $items, $plcObj = null) {
		$items1 = []; // elem: [addr, value]
		foreach ($items as $itemCode=>$value) { // code=>value
			$found = false;
			foreach ($plcConf["items"] as $itemCode0 => $item) {
				if ($itemCode == $itemCode0) {
					if (stripos($item["addr"], '[') !== false) {
						if (! self::isStringType($item["addr"])) {
							$value = explode(',', $value);
						}
					}
					$items1[] = [$item["addr"], $value];
					$found = true;
				}
			}
			if (!$found)
				jdRet(E_PARAM, "unknown plc item: {$plcConf['code']}.$itemCode");
		}
		if ($plcObj == null) {
			$plcObj = self::create($plcConf["addr"]);
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

		self::watchPlc($plcConf['addr'], $itemAddrList, function ($plcObj, $values) use ($plcConf, $watchItems, $itemCodeList, &$oldValues) {
			foreach ($values as $i=>$value) {
				$old = $oldValues[$i];
				if ($old === null) {
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
					writeLog("!!! item `$code` value change: " . jsonEncode($old) . " => " . jsonEncode($value));
					if (is_array($watch)) {
						$res = self::readItems($plcConf, $watch, $plcObj);
						$post += $res;
					}
					$url = $plcConf['notifyUrl'];
					writeLog("!!! notify $url: " . jsonEncode($post) ."\n");
					httpCall($url, $post);
				}
			}
		});
	}

	static function init() {
		self::loadPlcConf();
	}

	function api_conf() {
		file_put_contents("plc.json", jsonEncode($this->env->_POST, true));
		self::init();
	}

	function api_test() {
		$plc = self::create("127.0.0.1");
		$rv = $plc->write([["DB21.0:int32", 90000], ["DB21.4:float", 3.14]]);
		$plc->read(["DB21.0:int32", "DB21.4:float"]);
	}

	static function create($addr) {
		$rv = parse_url($addr);
		$proto = ($rv['schema'] ?: "s7");
		if (! in_array($proto, ["s7", "modbus"]))
			jdRet(E_PARAM, "unsupported plc addr protocol: `$proto`", "PLC地址错误: $addr");
		$addr1 = $rv["host"];
		if ($rv["port"]) {
			$addr1 .= ":" . $rv["port"];
		}
		$plc = PlcAccess::create($proto, $addr1);
		return $plc;
	}

	static function watchPlc($addr, $items, $cb) {
		$tmConf = self::$tmConf;
		while (true) {
			try {
				$plc = self::create($addr);
				while (true) {
					// 配置更新后，退出协程
					if ($tmConf !== self::$tmConf)
						return;
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

