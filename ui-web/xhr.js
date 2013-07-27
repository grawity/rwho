var waiting = 0;
var timer_id;
var hidden_prop;
var hidden_event;

function set_timer() {
	var interval = is_visible() ? settings.interval : 120;
	if (timer_id) {
		clearTimeout(timer_id);
	}
	timer_id = setTimeout(fetch_data, interval * 1000);
}

function fetch_data() {
	var p = location.href.indexOf("?");
	var url = (p >= 0 ? location.href.substr(0, p) : location.href) +
			"?" + settings.args;

	var xhr = new XMLHttpRequest();
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
}

function handle_data(data) {
	var table = document.getElementById("rwho-sessions");
	var body = table.getElementsByTagName("tbody")[0];
	body.innerHTML = data;
}

function is_visible() {
	return hidden_prop ? !document[hidden_prop] : true;
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

document.addEventListener("DOMContentLoaded", fetch_data, true);

if (hidden_event) {
	document.addEventListener(hidden_event, update_visibility, false);
}
