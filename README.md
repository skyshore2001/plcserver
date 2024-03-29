# plcserver - PLC访问中间件

在jdserver基础上，增加了PLC读、写、监控变化并回调功能，支持s7和modbus-tcp协议，以及用于测试的mock协议。
见plc.example.json示例配置文件。

在使用时，根据示例创建plc.json配置后，即可用HTTP接口进行PLC数据读写。

jdserver的消息推送与任务调度服务保留不变。

参考：

- [jdserver](README.jdsever.md)
- [plc-access](README.plc-access.md)

## 安装

安装swoole: 
生产环境使用ubuntu20以上，使用预编译好的模块：

	wget https://yibo.ltd/app/tool/swoole-4.8.8-ubuntu20-php74.tgz
	sudo tar -C /usr/lib/php/20190902 -axf swoole-4.8.8-ubuntu20-php74.tgz
	(创建swoole.so)

在/etc/php/7.4/cli/conf.d目录下创建20-swoole.ini:

	extension=swoole.so

也支持centos7以上，可使用预编译好的包：

	wget https://yibo.ltd/app/tool/swoole-4.8.8-php74-centos7-lj.xz
	sudo tar -C /opt -axf swoole-4.8.8-php74-centos7-lj.xz
	(会创建目录 /opt/php74)
	(加个swoole链接指向php)
	sudo ln -sf /opt/php74/bin/php /usr/bin/swoole

Windows开发环境可使用[swoole-cli](https://www.swoole.com/download)下载二进制发行包；也可以使用windows 10上的wsl，运行ubuntu20并使用预编译好的php74-swoole模块。

## 配置与运行

根据conf.user.template.php创建配置文件conf.user.php，指定数据库连接。一般使用mysql数据库。

根据示例plc.example.json创建PLC各地址字段的配置文件plc.json，或直接使用web配置工具（后面会讲）。

如果服务运行时手工更新了plc.json，在读写数据时会自动加载，一般不必重启服务。
但这期间监控变量（设置了watch的变量）未更新，若想立即更新配置，可以手工读一次数据。

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

运行时可在网页中配置或监控PLC：

	http://localhost:8081/

也可与Apache一起使用，将目录链接到Apache的主目录，示例：

	cd /var/www/html
	ln -sf /var/www/src/plcserver ./

注意已在.htaccess文件中配置了转发，Apache须打开proxy和rewrite模块，无须其它额外配置。

打开Web监控页，可查看各字段值，双击字段值可以修改值：

	http://localhost/plcserver/

点击Plc上链接可打开Web配置页，也可以直接打开Web配置页：

	http://localhost/plcserver/conf.html

## 读、写接口

提供基于HTTP的WebAPI接口。使用swoole运行服务：

有关消息推送和任务调度接口见jdserver文档。

### 读PLC

	GET $conf_plcAddr/Plc.read?items=id,job

或通过code指定从哪个PLC读字段：

	GET $conf_plcAddr/Plc.read?code=plc1&items=id,job

- items: 字段名列表，多个以逗号分隔。根据items中指定的字段名，在plc.json配置文件中查找相应地址并读写。
特别地，当items设置为ALL时，表示取所有定义的字段，目前用于监控页。

- code: PLC编码，可选参数。字段名默认在在所有PLC配置里查找，如果指定了code, 则取该code对应PLC中定义的字段名。

注意：不指定code时，所有要读的字段必须在同一PLC上，否则将会报错找不到字段。

返回JSON数组，第1项表示返回码，0为成功，非0为失败。
成功时第2项为返回数据，不同接口返回格式参考协议。失败时第2项为错误信息，第3项为调试信息。

成功返回示例：

	[0, {id: 99, job: 23}]

失败返回示例：

	[1, "接口错误", "unknown ac 'xxx'"]

如果需要JSON对象式的返回格式，可以加URL参数retfn=obj （以下其它接口也适用）：

	GET $conf_plcAddr/Plc.read?code=plc1&items=id,job

成功返回示例：

	{code: 0, data: {id:99, job: 23}}

失败返回示例：

	{code: 1, message: "接口错误", debug: "unknown ac 'xxx'"}

### 写PLC

给PLC下发任务示例:

```http
POST $conf_plcAddr/write?code=plc1

{
    task1Id: 1001, // 拧紧枪任务号
    sn: "sn1", // 电池包序列号
    device: "dev1", // 拧紧枪编码
    job: 23, // 拧紧枪jobId
}
```

POST内容中为要写入的字段名，默认在在所有PLC配置里查找，如果指定了code, 则取该code对应PLC中定义的字段名。
经配置转换后，plcserver向转换后的地址写数据. 

注意：不指定code时，所有要读的字段必须在同一PLC上，否则将会报错找不到字段。

成功返回示例：

	[0, "write plc ok"]

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
回调地址由notifyUrl指定。每个字段上也可指定额外的notifyUrl, 则同时也会发送到这个地址上。

数据变化和写数据会记录到plcserver日志中.
要对数据变化做更多逻辑控制, 可以在插件中处理plc_change事件(详见后面插件事例).

回调示例：

```http
POST http://localhost/jdcloud/api/Plc.notify

{
	"outFlag": 1,
	"outBin": 13
}
```

在这里, outFlag称为监控字段, outBin称为扩展返回字段. 
一般来说, 接收端可以简单地用`$POST["outFlag"] == 1`来判断outFlag字段变化为1.
但假如某字段a也是监控字段, 且把监控字段outFlag设置为a的扩展返回字段, 这样a变化时可能返回`{a:1, outFlag:1}`, 
这会导致上面判断outFlag变化的逻辑就不对了, 应改为只检测第1个字段, 这才是变化的字段. 

当多个字段同时变化时, 系统会发起多次调用, 每次只通知一个变化.

### 配置更新与重加载

配置更新接口：

	POST Plc.conf
	Content-Type: application/json

	{json config}

配置文件保存到文件plc.json；保存后会自动重新加载新配置。

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
- float (real/单精度4B-6位有效数字)
- double (lreal/双精度8B-15位有效数字)
- char[最大长度]
- string[最大长度] (西门子PLC支持长度0-254)
- TODO: wchar[最大长度]
- TODO: wstring[最大长度] (西门子PLC支持长度0-65534)
(西门子S7-1200类型参考: https://www.ad.siemens.com.cn/productportal/Prods/S7-1200_PLC_EASY_PLUS/function/DB_Data%20type/DB_date%20type.html)

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

### plcserver模拟：使用mock协议

可以将s7/modbus协议改成mock，这样就可以模拟读写接口以及回调。初始化值为全0，在写入值后会保存，直到重置或重启服务。
mock协议会分析和处理字段类型，而字段地址不做具体解析，一般可直接用s7或modbus的地址。

### s7设备模拟

可以使用snap7库中提供的server程序。默认有DB21等可以使用。

http://snap7.sourceforge.net/

### modbus设备模拟

可以使用ModbusPal程序。

使用modbus模拟器进行测试：(ModbusPal.jar)
https://plc4x.apache.org/users/getting-started/virtual-modbus.html

## 插件扩展

可以处理数据变化事件,自行控制通知逻辑, 示例:

```php
$GLOBALS["jdserver_event"]->on('plc_change', function ($post) {
	if ($post["myfield"] == 1) {
		httpCall("http://myserver/myapp/notify/event1");
	}
});
```
