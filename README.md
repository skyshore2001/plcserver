# plcserver - PLC访问中间件

在jdserver基础上，增加了PLC读、写、监控变化并回调功能，支持s7和modbus-tcp协议。
见plc.example.json示例配置文件。

在使用时，根据示例创建plc.json配置后，即可用HTTP接口进行PLC数据读写。

jdserver的消息推送与任务调度服务保留不变。

## 读、写接口

提供基于HTTP的WebAPI接口。

有关消息推送和任务调度接口见jdserver文档。

### 读PLC

	Plc.read(code, items)

示例：

	GET $conf_plcdAddr/api/Plc.read?code=plc1&items=id,job

根据code和字段名，在plc.json配置文件中查找相应地址并读写。

### 写PLC

	Plc.write(code)(items...)

给PLC下发任务，比如写plc1(某PLC编码):

```http
POST $conf_plcdAddr/api/write?code=plc1

{
    task1Id: 1001, // 拧紧枪任务号
    sn: "sn1", // 电池包序列号. TODO: PLC是否需要该信息？
    device: "dev1", // 拧紧枪编码
    job: 23, // 拧紧枪jobId
}
```

经配置转换后，plcserver向转换后的地址写数据. 

### PLC数据变化监控

当PLC中数据变化时（plcserver轮询PLC状态），回调指定URL，如：

```http
POST $baseUrl/api/notify

{
	task1Id: 1001,
	result: 'OK'
}
```

## 命令行工具plc-access.php

读S7 PLC:

	php plc-access.php -h 192.168.1.101 DB1.1:int8

写S7 PLC: （写后会自动读一次）

	php plc-access.php -h 192.168.1.101 DB1.1:uint8=200

读、写，使用16进制：

	php plc-access.php DB21.1:uint8=ff  DB21.1.0:bit DB21.1.7:bit  -x

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
- char

数组读写：

	php plc-access.php -h 192.168.1.101 DB1.1:byte[2]=125,225

字符读写：

	php plc-access.php DB21.0:char[4]=A,B,,C
	php plc-access.php DB21.0:char[4]
	"AB\u0000C"

	php plc-access.php DB21.0:char[2]=A,B DB21.0:uint8[2]
	"AB", [65,66]

	php plc-access.php DB21.0:uint32 -x
	"x41420043"

