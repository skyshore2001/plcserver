function urlencoded(param)
{
	var out = [];
	for (var key in param) {
		if (param.hasOwnProperty(key)) {
			out.push(key + '=' + encodeURIComponent(param[key]));
		}
	}
	return out.join('&');
}

function makeUrl(url, param)
{
	if (param == null)
		return url;
	var paramStr = urlencoded(param);
	return url + (url.indexOf('?')>=0? "&": "?") + paramStr;
}

function isPlainObject(o) {
	if (o === null || o === undefined)
		return false;
	return o.constructor.name == 'Object';
}

function isArray(o) {
	return Array.isArray(o);
}

function extendNoOverride(target) {
	var len = arguments.length;
	for (var i=1; i<len; ++i) {
		var src = arguments[i];
		if (! isPlainObject(src))
			continue;
		for (name in src) {
			var src1 = src[name];
			var target1 = target[name];
			if (target1 === src1) {
				continue;
			}
			if (target1 !== undefined) {
				if (isPlainObject(target1)) {
					extendNoOverride(target1, src1);
				}
				continue;
			}
			if (isPlainObject(src1)) {
				target[name] = extend({}, src1);
			}
			else if (isArray(src1)) {
				target[name] = cloneArray(src1);
			}
			else {
				target[name] = src1;
			}
		}
	}
	return target;
}

/* 
deep copy; 对object做合并，对其它类型直接覆盖。

	var obj1 = extend({a: {b:1}, a2: [3,4]}, {a: {c:2, b:3}, a2: [9], d:1}); // {a: {b:3, c:2}, a2:[9], d:1})
	# 只对object合并, 不覆盖已有属性
	var obj1 = extendNoOverride({a: {b:1}, a2: [3,4]}, {a: {c:2, b:3}, a2: [9], d:1}); // {a: {b:1, c:2}, a2:[3,4], d:1})

	var arr1 = cloneArray(arr);
	var obj1 = extend({}, obj);
 */
function extend(target) {
	var len = arguments.length;
	for (var i=1; i<len; ++i) {
		var src = arguments[i];
		if (! isPlainObject(src))
			continue;
		for (name in src) {
			var src1 = src[name];
			var target1 = target[name];
			if (target1 === src1) {
				continue;
			}

			if (isArray(src1)) {
				target[name] = cloneArray(src1);
			}
			else if (isPlainObject(src1)) {
				if (isPlainObject(target1)) {
					extend(target1, src1);
				}
				else {
					target[name] = extend({}, src1);
				}
			}
			else {
				target[name] = src1;
			}
		}
	}
	return target;
}

function cloneArray(arr) {
	return arr.map(e => {
		if (isPlainObject(e))
			return extend({}, e);
		if (isArray(e))
			return cloneArray(e);
		return e;
	});
}

function app_alert(msg) {
	alert(msg);
}

function app_abort() {
	throw "abort";
}

// opt: {useJson, jdcloud:0, ...}
// jdcloud=0: 不要做jdcloud协议检查
function callSvr(url, data, opt) {
	opt = extend({}, {
		jdcloud: 1,
	}, opt);
	if (data) {
		var ct = "application/x-www-form-urlencoded";
		if (opt.useJson) {
			ct = "application/json";
		}
		if (typeof(data) != "string") {
			if (ct.indexOf('json') >= 0)
				data = JSON.stringify(data);
			else
				data = urlencoded(data);
		}
		extendNoOverride(opt, {
			method: "post",
			body: data,
			headers: {
				"Content-Type": ct
			}
		});
	}
	return fetch(url, opt).then(e => e.json()).then(rv => {
		if (! opt.jdcloud)
			return rv;
		if (! (rv instanceof Array && rv.length >= 2)) {
			app_alert("返回数据不正确");
			return false;
		}
		if (rv[0] != 0) {
			var msg = "操作失败: " + rv.join(',');
			app_alert(msg);
			console.warn("callSvr fail: ", rv);
			app_abort();
		}
		return rv[1];
	});
}

// {name => obj} => [ obj={name, ...} ]
function map2arr(map, keyName, cb)
{
	var arr = [];
	for (var [name, obj] of Object.entries(map)) {
		obj[keyName] = name;
		if (cb) {
			cb(name, obj);
		}
		arr.push(obj);
	}
	return arr;
}

function arr2map(arr, keyName, cb)
{
	var obj = {};
	for (var e of arr) {
		obj[e[keyName]] = e;
		if (cb) {
			cb(e);
		}
		delete e[keyName];
	}
	return obj;
}

function parseQuery(s)
{
	var ret = {};
	if (s != "")
	{
		var a = s.split('&')
		for (i=0; i<a.length; ++i) {
			var a1 = a[i].split("=");
			var val = a1[1];
			if (val === undefined)
				val = 1;
			else if (/^-?[0-9]+$/.test(val)) {
				val = parseInt(val);
			}
			else if (/^-?[0-9.]+$/.test(val)) {
				val = parseFloat(val);
			}
			else {
				val = decodeURIComponent(val);
			}
			ret[a1[0]] = val;
		}
	}
	return ret;
}

var g_args = {};
if (location.search) {
	g_args = parseQuery(location.search.substr(1));
}

if (g_args.zoom) {
	setTimeout(function () {
		document.body.style.zoom = g_args.zoom;
	});
}

