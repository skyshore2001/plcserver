<?php

// use SQLite:
//putenv("P_DB=jdcloud.db");

// use MySQL:
// putenv("P_DB=localhost/jdserver");
// putenv("P_DBCRED=test:1234");

// DONT use DB:
putenv("P_DB=null");

// test mode: default value=0
// putenv("P_TEST_MODE=1");

// debug level: default value=0
// putenv("P_DEBUG=9");
// putenv("P_DEBUG_LOG=1"); // 0: no log to 'debug.log' 1: all log, 2: error log

// Plc.read/Plc.write接口读写失败后重试次数, 2表示最多3次
$GLOBALS["conf_retry_cnt"] = 2;

