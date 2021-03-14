var waiting = 0;
var timer_id;
var iterations = 0;
var hidden_prop;
var hidden_event;

function create_xhr() {
	if (window.XMLHttpRequest) {
		return new XMLHttpRequest();
	} else if (window.ActiveXObject) {
		/* IE 5-6 */
		return new ActiveXObject("Microsoft.XMLHTTP");
	} else {
		return null;
	}
}

function fetch_data() {
	/* Include timestamp to prevent IE 5 from caching the response forever */
	var time = new Date().getTime();
	var query = "?" + settings.args.replace(/^\?/, "") + "&t=" + time;

	var pos = location.href.indexOf("?");
	var url = (pos >= 0 ? location.href.substr(0, pos) : location.href) + query;

	var xhr = create_xhr();
	waiting++;
	xhr.open('GET', url, true);
	xhr.onreadystatechange = function (event) {
		if (xhr.readyState == 4) {
			waiting--;
			if (xhr.status == 200) {
				handle_data(xhr.responseText);
			} else if (xhr.status) {
				console.log("Error loading data: "+xhr.status);
			}
			set_timer();
		}
	};
	xhr.send(null);
	iterations++;
}

function handle_data(data) {
	var old_div = document.getElementById("rwho-table-wrapper");
	var new_div = document.createElement("div");
	new_div.innerHTML = data;

	/* Use DOM instead of raw innerHTML to make it work in IE 5 */
	/* But replace just the <tbody> to avoid flickering in Mozilla */
	var old_tbody = old_div.getElementsByTagName("tbody")[0];
	var new_tbody = new_div.getElementsByTagName("tbody")[0];
	old_tbody.parentNode.replaceChild(new_tbody, old_tbody);
}

function is_visible() {
	return hidden_prop ? !document[hidden_prop] : true;
}

function set_timer() {
	var interval = settings.interval;
	if (!is_visible()) {
		interval = Math.max(interval, 120);
	}
	if (iterations > 500) {
		interval *= (iterations / 500);
	}
	if (iterations > 10000) {
		console.log("nobody's looking at us, going to sleep");
		return;
	}
	if (timer_id) {
		clearTimeout(timer_id);
	}
	timer_id = setTimeout(fetch_data, interval * 1000);
}

function update_visibility() {
	if (is_visible()) {
		console.log("page became visible, starting instant update");
		fetch_data();
	} else {
		console.log("page became hidden, adjusting timer");
		set_timer();
	}
}

if (!window.console) {
	window.console = {};
	window.console.log = function(msg) {};
}

if (!create_xhr()) {
	alert("[BUG] no XHR on this browser");
} else if (document.addEventListener) {
	/* Standard DOM (IE 9+) */
	document.addEventListener("DOMContentLoaded", fetch_data, true);

	if (typeof document.hidden !== "undefined") {
		hidden_prop = "hidden";
		hidden_event = "visibilitychange";
	} else if (typeof document.mozHidden !== "undefined") {
		hidden_prop = "mozHidden";
		hidden_event = "mozvisibilitychange";
	} else if (typeof document.msHidden !== "undefined") {
		hidden_prop = "msHidden";
		hidden_event = "msvisibilitychange";
	} else if (typeof document.webkitHidden !== "undefined") {
		hidden_prop = "webkitHidden";
		hidden_event = "webkitvisibilitychange";
	}

	if (hidden_event) {
		document.addEventListener(hidden_event, update_visibility, false);
	}
} else if (document.attachEvent) {
	/* IE 4-8 */
	window.attachEvent("onload", fetch_data);

	/* Create a custom property */
	hidden_prop = "hidden";
	/* This is not optimal, as blurred windows are actually still visible. */
	window.attachEvent("onfocus", function() {
		document[hidden_prop] = false;
		update_visibility();
	});
	window.attachEvent("onblur", function() {
		document[hidden_prop] = true;
		update_visibility();
	});
} else {
	alert("[BUG] no event handler on this browser");
}
