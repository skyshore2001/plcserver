# plcserver - PLC访问中间件

在jdserver基础上，增加了PLC读、写、监控变化并回调功能，支持s7和modbus-tcp协议。
见plc.example.json示例配置文件。

在使用时，根据示例创建plc.json配置后，即可用HTTP接口进行PLC数据读写。

jdserver的消息推送与任务调度服务保留不变。

参考：

- [jdserver](README.jdsever.md)
- [plc-access](README.plc-access.md)

## 安装

安装swoole: 
生产环境使用centos7，可使用预编译好的包：

	wget https://oliveche.com/app/tool/swoole-4.8.8-php74-centos7-lj.xz
	sudo tar -C /opt -axf swoole-4.8.8-php74-centos7-lj.xz
	(会创建目录 /opt/php74)
	(加个swoole链接指向php)
	sudo ln -sf /opt/php74/bin/php /usr/bin/swoole

开发环境可使用windows 10上的wsl，使用ubuntu20上的编译好的php74模块：

	wget https://oliveche.com/app/tool/swoole-4.8.8-ubuntu20-php74.tgz
	sudo tar -C /usr/lib/php/20190902 -axf swoole-4.8.8-ubuntu20-php74.tgz
	(创建swoole.so)

在/etc/php/7.4/cli/conf.d目录下创建20-swoole.ini:

	extension=swoole.so

## 配置与运行

根据conf.user.template.php创建配置文件conf.user.php，指定数据库连接。一般使用mysql数据库。

根据示例plc.example.json创建PLC各地址字段的配置文件plc.json.
TODO: 配置工具

测试运行：

	swoole jdserver.php
	或
	php jdserver.php

启动后默认端口为8081，可以通过-p参数修改，如`swoole jdserver.php -p 10081`。

服务安装：（若需要修改端口，可编辑下列文件，在命令行上加-p参数）

	sudo ./plcserver.service.sh

服务运行：

	sudo ./plcserver.service.sh start
	sudo ./plcserver.service.sh restart
	(或sudo systemctl restart plcserver)

运行时可在网页中配置PLC：

	http://localhost:8081/conf

在网页中查看或修改字段值：

	http://localhost:8081/plc

## 读、写接口

提供基于HTTP的WebAPI接口。使用swoole运行服务：

有关消息推送和任务调度接口见jdserver文档。

### 读PLC

	Plc.read(code?, items)

示例：

	GET $conf_plcdAddr/api/Plc.read?code=plc1&items=id,job

根据code和字段名，在plc.json配置文件中查找相应地址并读写。
如果未指定code, 则取配置中第1个。

### 写PLC

	Plc.write(code?)(items...)

给PLC下发任务，比如写plc1(某PLC编码):

```http
POST $conf_plcdAddr/api/write?code=plc1

{
    task1Id: 1001, // 拧紧枪任务号
    sn: "sn1", // 电池包序列号
    device: "dev1", // 拧紧枪编码
    job: 23, // 拧紧枪jobId
}
```

如果未指定code, 则取配置中第1个。
经配置转换后，plcserver向转换后的地址写数据. 

### PLC数据变化监控

当PLC中数据变化时（plcserver轮询PLC状态），回调指定URL，配置示例：

```json
"plc1": {
  "items": {
    "outFlag": {
      "addr": "DB100.0.3:bit",
      "watch": [ "outBin" ]
    },
    "outBin": {
      "addr": "DB100.20:uint16"
    }
  }
  "notifyUrl": "http://localhost/jdcloud/api/Plc.notify"
}
```

items中只要设置了watch属性，就表示该字段要监控，值一般指定为一个数组，代表回调时一起返回的字段值，空数组表示只返回当前字段本身。
回调地址由notifyUrl指定。

回调示例：

```http
POST http://localhost/jdcloud/api/Plc.notify

{
	"outFlag": 1,
	"outBin": 13
}
```

## 命令行工具plc-access.php

详细请参考：[plc-access](README.plc-access.md)

读S7 PLC:

	php plc-access.php -h 192.168.1.101 DB1.1:int8

写S7 PLC: （写后会自动读一次）

	php plc-access.php -h 192.168.1.101 DB1.1:uint8=200

s7协议地址格式为：

- DB{dbNumber}.{startAddr}:{type}
- DB{dbNumber}.{startAddr}.{bitOffset}:bit
- array format:
  - DB{dbNumber}.{startAddr}:{type}[amount]
  - DB{dbNumber}.{startAddr}.{bitOffset}:bit[amount]

modbus-tcp协议读、写：（加-t modbus参数）

	php plc-access.php -t modbus S1.0:word[2]=20000,40000
	php plc-access.php -t modbus S1.0:word[2]

modbus协议地址格式为：

- S{slaveId}.{startAddr}:{type}
- 注意：startAddr为0开始，以字(word)为单位(对比s7协议是字节为单位)

参数选项：

-h : plc host. default=127.0.0.1:102
-p : proto. Enum(s7(default), modbus)
-x : 写时以16进制设置，读后显示16进制数据。

支持的类型如下：

- int8
- uint8/byte
- int16/int
- uint16/word
- int32/dint
- uint32/dword
- bit/bool
- float
- char[最大长度]
- string[最大长度]

数组读写：

	php plc-access.php -h 192.168.1.101 DB1.1:byte[2]=125,225

字符串读写：定长字符串用`char[长度]`（长度不超过256）, 变长字符串用`string[长度]`（长度不超过254）

	php plc-access.php DB21.0:char[4]="AB"
	php plc-access.php DB21.0:char[4]
	"AB\x00\x00"

	php plc-access.php DB21.0:string[4]="AB"
	php plc-access.php DB21.0:string[4]
	"AB"

变长字符串string类型与西门子S7系列PLC的字符串格式兼容，它比char类型多2字节头部，分别表示总长度和实际长度。

兼容C语言风格的"\x00"风格(两个16进制数字表示一个字符)，如

	php plc-access.php DB21.0:char[4]="A\x00\x01B"
	php plc-access.php DB21.0:char[4]
	"A\x00\x01B"

## 本地模拟测试

### s7设备模拟

可以使用snap7库中提供的server程序。默认有DB21等可以使用。

http://snap7.sourceforge.net/

### modbus设备模拟

可以使用ModbusPal程序。

使用modbus模拟器进行测试：(ModbusPal.jar)
https://plc4x.apache.org/users/getting-started/virtual-modbus.html

