var waiting = 0;

function set_timer() {
	setTimeout(fetch_data, settings.interval * 1000);
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

document.addEventListener("DOMContentLoaded", fetch_data, true);
