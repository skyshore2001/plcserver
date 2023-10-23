# plc-access

PLC读写库及命令行工具。

支持西门子s7协议和modbus协议(modbus-tcp)。

关联项目：

- [s7plc](https://github.com/skyshore2001/s7plc/): 超简单的PHP语言的西门子S7系列PLC读写模块
- [plcserver](https://github.com/skyshore2001/plcserver/): PLC访问中间件，通过web接口来对PLC进行读、写、**监控值变化并回调**。

## 命令行工具plc-access.php

读S7 PLC:

	php plc-access.php -h 192.168.1.101 DB1.1:int8

写S7 PLC: （写后会自动读一次）

	php plc-access.php -h 192.168.1.101 DB1.1:uint8=200

写时支持使用16进制，以"0x"开头，兼容C语言语法：

	php plc-access.php DB21.1:uint8=0xff

读时可以用-x参数将结果显示为16进制：

	php plc-access.php DB21.1.0:bit DB21.1.7:bit  -x

s7协议地址格式为：

- DB{dbNumber}.{startAddr}:{type}
- DB{dbNumber}.{startAddr}.{bitOffset}:bit
- array format:
  - DB{dbNumber}.{startAddr}:{type}[amount]
  - DB{dbNumber}.{startAddr}.{bitOffset}:bit[amount]

**s7地址格式对照**

- DB21.DBB4 (byte): DB21.4:int8 (-127~127) or DB21.4:uint8 (0~256)
- DB21.DBW4 (word): DB21.4:int16 (-32767~32768) or DB21.4:uint16 (0~65536)
- DB21.DBD4 (dword): DB21.4:int32 or DB21.4:uint32 or DB21.4:float
- DB21.DBX4.0 (bit): DB21.4.0:bit

modbus-tcp协议读、写：（加-t modbus参数）

	php plc-access.php -t modbus S1.0:word[2]=20000,40000
	php plc-access.php -t modbus S1.0:word[2]

modbus协议地址格式为：

- S{slaveId}.{startAddr}:{type}
- 注意：startAddr为0开始，以字(word)为单位(对比s7协议是字节为单位)
读写bit对应coils，其它对应Holding registers.

参数选项：

-h : plc host. 缺省值=127.0.0.1, s7默认端口102，modbus默认端口502
-p : proto. Enum(s7(default), modbus)
-x : 写时以16进制设置，读后显示16进制数据。

支持的类型如下：

- int8 (sint)
- uint8/byte (usint)
- int16/int
- uint16/word
- int32/dint
- uint32/dword
- bit/bool
- float (real/单精度4B-6位有效数字)
- double (lreal/双精度8B-15位有效数字)
- char[最大长度]
- string[最大长度] (西门子PLC支持长度0-254)
- TODO: wchar[最大长度]
- TODO: wstring[最大长度] (西门子PLC支持长度0-65534)
(西门子S7-1200类型参考: https://www.ad.siemens.com.cn/productportal/Prods/S7-1200_PLC_EASY_PLUS/function/DB_Data%20type/DB_date%20type.html)

数组读写：

	php plc-access.php -h 192.168.1.101 DB1.1:byte[2]=125,225

如果数组元素个数不足指定长度，会自动补0；反之若超出则自动截取为指定长度。

	php plc-access.php -h 192.168.1.101 DB1.1:byte[20]=9,10
	前两个byte设置9,10，其它清零。

字符串读写：定长字符串用`char[长度]`（长度不超过256）, 变长字符串用`string[长度]`（长度不超过254）

	php plc-access.php DB21.0:char[4]="AB"
	php plc-access.php DB21.0:char[4]
	"AB\x00\x00"

定长字符串写入时，若实际长度不足，自动补0，若超过则截断。读出时与指定长度相同。

	php plc-access.php DB21.0:string[4]="AB"
	php plc-access.php DB21.0:string[4]
	"AB"

变长字符串写入时，若实际长度不足，只写实际长度部分，若超过则截断。读出时为实际长度，小于等于指定长度。
变长字符串string类型与西门子S7系列PLC的字符串格式兼容，它比char类型多2字节头部，分别表示总长度和实际长度。

兼容C语言风格的"\x00"风格(两个16进制数字表示一个字符)，如

	php plc-access.php DB21.0:char[4]="A\x00\x01B"
	php plc-access.php DB21.0:char[4]
	"A\x00\x01B"

这与byte[4]或uint16[2]或uint32写入的效果相同(西门子PLC字节序为大端，如果是小端机，顺序会不一样)，如等价于：

	php plc-access.php DB21.0:byte[4]=0x61,0,1,0x62 -x
	[0x61, 0x00, 0x01, 0x62]

	php plc-access.php DB21.0:uint16[2]=0x6100,0x0162 DB21.0:byte[4] -x

	php plc-access.php DB21.0:uint32=0x61000162 DB21.0:byte[4] -x

TODO: 字节序一般为网络序(大端), 若为小端, 可在PlcAccess::typeMap里面为int16/int32等指定fmt2参数。

## PHP编程示例

[读写S7Plc示例](https://github.com/skyshore2001/s7plc/)如下：

方式一：单次读写（每次调用发起一次TCP短连接）

```php
require("common.php");
require("class/PlcAccess.php");

try {
	PlcAccess::writePlc("s7", "192.168.1.101", [
		["DB21.0:int32", 70000], // [item, value]
		["DB21.4:float", 3.14],
		["DB21.12.0:bit", 1]
	]);

	$res = PlcAccess::readPlc("s7", "192.168.1.101", ["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	var_dump($res);
	// on success $res=[ 70000, 3.14, 1 ]
}
catch (PlcAccessException $ex) {
	echo('error: ' . $ex->getMessage());
}
```

方式二：连续读写（维持一个TCP长连接）

```php
try {
	$plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([
		["DB21.0:int32", 70000],
		["DB21.4:float", 3.14],
		["DB21.12.0:bit", 1]
	]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	// on success $res=[ 30000, 3.14, 1 ]
}
catch (PlcAccessException $ex) {
	echo('error: ' . $ex->getMessage());
}
```
**数组读写**

```php
$plc->write([
	["DB21.0:int8[4]", [1,2,3,4]],
	["DB21.4:float[2]", [3.3, 4.4]
]);
$res = $plc->read(["DB21.0:int8[4]", "DB21.4:float[2]"]);
// $res example: [ [1,2,3,4], [3.3, 4.4] ]
```

如果数组元素个数不足指定长度，会自动补0；反之若超出则自动截取为指定长度。

	$plc->write([ ["DB21.0:int8[4]", [1,2]] ]); // 等价于写[1,2,0,0]
	$plc->write([ ["DB21.0:int8[4]", []] ]); // 全部清零

在一次读写中，可以同时使用数组和普通元素：

```php
$plc->write([
	["DB21.0:int8[4]", [3,4] ],
	["DB21.4:float", 3.3],
	["DB21.8:float", 4.4]
]);
$res = $plc->read(["DB21.0:int8[4]", "DB21.4:float", "DB21.8:float"]);
// $res example: [ [1,2,3,4], 3.3, 4.4 ]
```

如果是Modbus协议，换成Modbus地址格式即可，接口相同。

	$plc = PlcAccess::create("modbus", "192.168.1.101"); // default tcp port 105
	$plc->write(["S1.0:word", 99]);
	$res = $plc->read(["S1.0:word"]);

**字符串读写**

读4字节定长字符串，注意字符串最长为256字节：

	$plc->write([ ["DB21.0:char[4]", "abcd"] ]);
	$res = $plc->read(["DB21.0:char[4]"]);

可以写任意字符，长度不足会自动补0，超过会自动截断，如：

	$plc->write([ ["DB21.0:char[4]", "\x01\x02\x03"] ]); // 实际写入"\x01\x02\x03\x00"
	$plc->write([ ["DB21.0:char[4]", "abcdef"] ]); // 实际写入"abcd"

变长字符串string类型与西门子S7系列PLC的字符串格式兼容，它比char类型多2字节头部，分别表示总长度和实际长度，因而最大长度为254：

	$plc->write([ ["DB21.0:string[4]", "ab"] ]); // 实际写入 "\x04\x02ab"
	$res = $plc->read(["DB21.0:string[4]"]); // 读到"ab"

与定长字符串相比，变长字符串读数据时会读全部长度，返回实际长度的字符串，写数据时只会写指定长度。

**null值**

字符串(char[]或string[])设置值为null与设置""相同.
数值型设置null与设置0相同.
读取时必有值, 不可能读出null.

