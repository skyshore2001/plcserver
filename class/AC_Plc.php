<?php

class AC_Plc extends JDApiBase
{
	static $conf, $tmConf;

	function api_read() {
		$plcCode = $this->env->param("code", null, "G");
		$items = explode(',', $this->env->mparam("items", null, "G"));
		if (count($items) == 0)
			return;

		$conf = self::loadPlcConf();
		$plcConf = self::findPlc($conf, $plcCode, $items);

		$errInfo = "fail to call `Plc.read` for `$plcCode`";
		return callWithRetry(function () use ($plcConf, $items) {
			return self::readItems($plcConf, $items);
		}, $errInfo);
	}

	static protected $sem_loadPlcConf = null;
	static protected function loadPlcConf() {
		if (self::$sem_loadPlcConf == null)
			self::$sem_loadPlcConf = new CoSemphore(1);
		// lock
		$lock = new CoLockGuard(self::$sem_loadPlcConf);

		clearstatcache();
		@$tmConf = filemtime("plc.json");
		if ($tmConf === self::$tmConf)
			return self::$conf;

		@$confStr = file_get_contents("plc.json");
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
		writeLog("### load conf: plc.json");

		// 部署watch任务
		foreach ($conf as $plcCode => $plcConf) {
			if ($plcConf['disabled'])
				continue;
			$watchItems = array_filter($plcConf['items'], function ($item) {
				return !$item['disabled'] && isset($item['watch']);
			});

			if (count($watchItems) > 0) { // {itemCode=>item}
				// 注意：配置变化后(watchPlc()中检查self::$tmConf)，将自动退出
				safeGo(function () use ($plcConf, $watchItems) {
					self::handleWatchItems($plcConf, $watchItems);
				});
			}
		}
		return $conf;
	}

	function api_write() {
		$plcCode = $this->env->param("code", null, "G");
		$items = $this->env->_POST;
		if (count($items) == 0)
			return "no item";

		$conf = self::loadPlcConf();
		$plcConf = self::findPlc($conf, $plcCode, array_keys($items));
		$errInfo = "fail to call `Plc.write` for `$plcCode`";
		return callWithRetry(function () use ($plcConf, $items) {
			self::writeItems($plcConf, $items);
			return "write plc ok";
		}, $errInfo);
	}

	// retrun: plcConf
	static function findPlc($conf, &$plcCode, $items)
	{
		if (count($items) == 0)
			jdRet(E_PARAM, "require items");
		if (count($conf) == 0)
			jdRet(E_SERVER, "no plc configured");
		$itemCode = $items[0];
		$ret = null;
		foreach ($conf as $plcCode0 => $plcConf) {
			if (($plcCode === $plcCode0 || $plcCode === null) && 
				($itemCode == 'ALL' || array_key_exists($itemCode, $plcConf["items"]))
			) {
				$plcCode = $plcCode0;
				$ret = $plcConf;
				break;
			}
		}
		if (!$ret)
			jdRet(E_PARAM, "cannot find plc item: $itemCode");
		if ($plcConf["disabled"])
			jdRet(E_FORBIDDEN, "item $itemCode on plc $plcCode is disabled");
		return $ret;
	}

	static protected function readItems($plcConf, $items, $plcObj = null) {
		if ($items[0] == 'ALL') {
			$items = [];
			foreach ($plcConf["items"] as $itemCode => $item) {
				if ($item["disabled"])
					continue;
				$items[] = $itemCode;
				$items1[] = $item["addr"];
			}
		}
		else {
			$items1 = [];
			foreach ($items as $itemCode) {
				$found = false;
				foreach ($plcConf["items"] as $itemCode0 => $item) {
					if ($itemCode == $itemCode0) {
						if ($item["disabled"])
							jdRet(E_FORBIDDEN, null, "item `$itemCode` is disabled");
						$items1[] = $item["addr"];
						$found = true;
					}
				}
				if (!$found)
					jdRet(E_PARAM, "unknown plc item: {$plcConf['code']}.$itemCode");
			}
		}
		if ($plcObj == null) {
			$plcObj = self::create($plcConf["addr"], $plcConf);
		}
		$res = $plcObj->read($items1);
		$ret = array_combine($items, $res);
		return $ret;
	}

	private static function isStringType($type) {
		return preg_match('/:(char|string)/', $type);
	}
	static protected function writeItems($plcConf, $items, $plcObj = null) {
		writeLog("!!! write: " . jsonEncode($items));
		$items1 = []; // elem: [addr, value]
		foreach ($items as $itemCode=>$value) { // code=>value
			$found = false;
			foreach ($plcConf["items"] as $itemCode0 => $item) {
				if ($itemCode == $itemCode0) {
					if ($item["disabled"])
						jdRet(E_FORBIDDEN, null, "item `$itemCode` is disabled");
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
			$plcObj = self::create($plcConf["addr"], $plcConf);
		}
		$res = $plcObj->write($items1);
	}

	static protected function handleWatchItems($plcConf, $watchItems) {
		$oldValues = [];
		$itemAddrList = [];
		$itemCodeList = [];
		foreach ($watchItems as $code=>$e) {
			$itemAddrList[] = $e['addr'];
			$itemCodeList[] = $code;
		}

		self::watchPlc($plcConf, $itemAddrList, function ($plcObj, $values) use ($plcConf, $watchItems, $itemCodeList, &$oldValues) {
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
					$post = [];
					$post[$code] = $value;
					// writeLog("!!! item `$code` value change: " . jsonEncode($old) . " => " . jsonEncode($value));
					$watch = $watchItems[$code]['watch'];
					if (is_array($watch)) {
						$res = self::readItems($plcConf, $watch, $plcObj);
						$post += $res;
					}
					$s = jsonEncode($post);
					writeLog("!!! change: $s" . " <= " . jsonEncode($old));

					safeGo(function () use ($post) {
						$GLOBALS["jdserver_event"]->trigger("plc_change", [$post]);
					});
					foreach ([$plcConf["notifyUrl"], $watchItems[$code]['notifyUrl']] as $url) {
						if (! $url)
							continue;
						// echo("notify $url\n");
						safeGo(function () use ($url, $post) {
							httpCall($url, $post);
						});
					}
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
		$plc = self::create("127.0.0.1", null);
		$rv = $plc->write([["DB21.0:int32", 90000], ["DB21.4:float", 3.14]]);
		$plc->read(["DB21.0:int32", "DB21.4:float"]);
	}

	// 某些PLC设备（如modbus按钮盒）只支持单个连接轮询。当存在多个连接时，读出错误很可能错乱。
	// 在PLC配置上设置forceSingleAccess=true，确保底层只使用单个plcObj对象（单个连接）访问同一设备。
	private static $plcProxyMap = []; // addr=>plcProxy

	static function create($addr, $plcConf) {
		if ($plcConf && $plcConf["forceSingleAccess"]) {
			if (! array_key_exists($addr, self::$plcProxyMap)) {
				$plcObj = self::create($addr, null);
				self::$plcProxyMap[$addr] = new PlcAccessProxy($plcObj);
			}
			return self::$plcProxyMap[$addr];
		}
		$rv = parse_url($addr);
		$proto = ($rv['scheme'] ?: "s7");
		if (! in_array($proto, ["s7", "modbus", "mock"]))
			jdRet(E_PARAM, "unsupported plc addr protocol: `$proto`", "PLC地址错误: $addr");
		$addr1 = $rv["host"];
		if ($rv["port"]) {
			$addr1 .= ":" . $rv["port"];
		}
		$plcObj = PlcAccess::create($proto, $addr1);
		return $plcObj;
	}

	static function watchPlc($plcConf, $items, $cb) {
		$tmConf = self::$tmConf;
		$lastEx = null;
		$plcCode = $plcConf["code"];
		while (true) {
			try {
				$plcObj = self::create($plcConf["addr"], $plcConf);
				while (true) {
					// 配置更新后，退出协程
					if ($tmConf !== self::$tmConf || JDServer::$reloadFlag)
						return;
					$res = $plcObj->read($items);
					$cb($plc, $res);
					if ($lastEx) {
						logit("watchPlc ok for `$plcCode` (restored from error)");
						$lastEx = null;
					}
					sleep(1);
				}
			}
			catch (Exception $ex) {
				$plcObj = null;
				if (!$lastEx || $lastEx->getMessage() != $ex->getMessage()) {
					logit("watchPlc fails for `$plcCode` (will skip the same error): " . $ex);
					$lastEx = $ex;
				}
			}
			if ($tmConf !== self::$tmConf || JDServer::$reloadFlag)
				return;
			sleep(2);
		}
	}
}

// 协程信号量, 可实现互斥锁或信号同步.
// TODO: 目前不支持自旋(同一协程多次调用将死锁). 可利用Co::getCid()判断是否同一协程调用.
class CoSemphore
{
	private $v;
	function __construct($initV = 0) {
		$this->v = $initV;
	}
	function wait($n = 1) {
		while ($this->v <= 0) {
			usleep(1000);
		}
		$this->v -= $n;
		// writeLog(Co::getCid() . "wait");
	}
	function signal($n = 1) {
		$this->v += $n;
		// writeLog(Co::getCid() . "signal");
	}
}

// 注意：不能继承Guard
class CoLockGuard
{
	private $sem = null;
	function __construct(CoSemphore $sem) {
		$this->sem = $sem;
		$this->sem->wait();
	}
	function __destruct() {
		$this->sem->signal();
	}
}

class PlcAccessProxy
{
	private $plcObj;
	private $sem = null;
	function __construct($plcObj) {
		$this->plcObj = $plcObj;
		$this->sem = new CoSemphore(1);
	}
	function read($items) {
		$this->sem->wait();
		$g = new Guard(function () {
			$this->sem->signal();
		});
		return $this->plcObj->read($items);
	}
	function write($items) {
		$this->sem->wait();
		$g = new Guard(function () {
			$this->sem->signal();
		});
		return $this->plcObj->write($items);
	}
}

function callWithRetry($fn, $errInfo)
{
	$retryCnt = $GLOBALS["conf_retry_cnt"] ?: 0;
	if (!$retryCnt)
		return $fn();
	$i = 0;
	while (true) {
		try {
			return $fn();
		}
		catch (Exception $ex) {
			if ($i < $retryCnt) {
				++ $i;

				logit("$errInfo (will retry $i): $ex");
				usleep(rand(20000, 100000)); // 20-100ms
			}
			else {
				logit("$errInfo (after retry $i)");
				throw $ex;
			}
		}
	}
}

