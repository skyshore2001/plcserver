// opt: {useJson, ...}
function callSvr(url, data, opt) {
	if (data) {
		var ct = "application/x-www-form-urlencoded";
		if (opt && opt.useJson) {
			ct = "application/json";
			if (typeof(data) != "string")
				data = JSON.stringify(data);
		}
		opt = Object.assign({}, {
			method: "post",
			body: data,
			headers: {
				"Content-Type": ct
			}
		}, opt);
	}
	return fetch(url, opt).then(e => e.json())
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

