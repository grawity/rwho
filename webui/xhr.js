var waiting = 0;

function now() {
	return (new Date()).getTime() / 1000;
}

function interval(start) {
	var diff = Math.round(now() - start);
	var s = diff % 60; diff -= s; diff /= 60;
	var m = diff % 60; diff -= m; diff /= 60;
	var h = diff % 24; diff -= h; diff /= 24;
	var d = diff;
	switch (true) {
		case d > 1:	return d+" days";
		case h > 0:	return h+"h "+m+"m";
		case m > 1:	return m+"m "+s+"s";
		default:	return s+" secs";
	}
	return diff;
}

function fetch_data() {
	var p = location.href.indexOf("?");
	var json_url = (p >= 0 ? location.href.substr(0, p) : location.href) + "?" + json_args;

	var xhr = new XMLHttpRequest();
	waiting++;
	xhr.open('GET', json_url, true);
	xhr.onreadystatechange = function (event) {
		if (xhr.readyState == 4) {
			waiting--;
			if (xhr.status == 200) {
				handle_data(xhr.responseText);
			} else if (xhr.status) {
				console.log("Error loading "+page+" data: "+xhr.status);
			}
			setTimeout(fetch_data, update_interval);
		}
	};
	xhr.send(null);
}

function handle_data(data) {
	switch (page) {
		case "utmp":
			return handle_utmp_data(data);
		case "host":
			return handle_host_data(data);
	}
}

function handle_utmp_data(data) {
	if (JSON.parse) {
		data = JSON.parse(data);
	} else {
		data = eval("("+data+")");
	}

	var table = document.createElement("tbody");
	
	if (!data.utmp.length) {
		var trow = document.createElement("tr");
		var cell = document.createElement("td");
		cell.colSpan = html_columns;
		cell.className = "comment";
		cell.innerHTML = "Nobody is logged in.";
		trow.appendChild(cell);
		table.appendChild(trow);
	}

	var byuser = {};
	for (var i = 0; i < data.utmp.length; i++) {
		if (!(data.utmp[i].user in byuser))
			byuser[data.utmp[i].user] = [];
		byuser[data.utmp[i].user].push(data.utmp[i]);
	}

	for (var user in byuser) {
		for (var i = 0; i < byuser[user].length; i++) {
			var row = byuser[user][i];
			var trow = document.createElement("tr");
			var cell;

			//if (row.updated < now()-data.maxage) {
			if (row.stale) {
				trow.className = "stale";
			}

			var user_cell = document.createElement("td");
			if (data.query.user === null) {
				var link = document.createElement("a");
				link.textContent = row.user;
				link.href = "?user="+row.user;
				user_cell.appendChild(link);
			} else {
				user_cell.textContent = row.user;
			}

			if (data.query.summary) {
				if (i == 0) {
					cell = user_cell;
					cell.rowSpan = byuser[user].length;
					trow.appendChild(cell);
				}
			} else {
				cell = user_cell;
				trow.appendChild(cell);

				cell = document.createElement("td");
				cell.textContent = row.uid;
				trow.appendChild(cell);
			}

			cell = document.createElement("td");
			//var hostname = data.query.summary
			//	? row.host.substr(0, row.host.indexOf("."))
			//	: row.host;
			var hostname = row.host.substr(0, row.host.indexOf("."));
			if (data.query.host === null) {
				var link = document.createElement("a");
				link.textContent = hostname;
				link.href = "?host="+row.host;
				link.title = row.host;
				cell.appendChild(link);
			} else {
				cell.textContent = hostname;
			}
			trow.appendChild(cell);

			cell = document.createElement("td");
			cell.textContent = row.is_summary ? "("+row.line+" ttys)" : row.line;
			trow.appendChild(cell);

			cell = document.createElement("td");
			cell.textContent = row.rhost;
			trow.appendChild(cell);

			table.appendChild(trow);
		}
	}

	var htable = document.getElementById("sessions");
	var hbody = htable.getElementsByTagName("tbody");
	htable.replaceChild(table, hbody[0]);
}

function handle_host_data(data) {
	if (JSON.parse) {
		data = JSON.parse(data);
	} else {
		data = eval("("+data+")");
	}

	var table = document.createElement("tbody");

	if (!data.length) {
		var trow = document.createElement("tr");
		var cell = document.createElement("td");
		cell.colSpan = html_columns;
		cell.className = "comment";
		cell.innerHTML = "No active hosts.";
		trow.appendChild(cell);
		table.appendChild(trow);
	}

	for (var i = 0; i < data.length; i++) {
		var row = data[i];
		var trow = document.createElement("tr");
		var cell;

		cell = document.createElement("td");
		var link = document.createElement("a");
		link.textContent = row.host.substr(0, row.host.indexOf("."));
		link.href = "./?host="+row.host;
		cell.appendChild(link);
		trow.appendChild(cell);

		cell = document.createElement("td");
		cell.textContent = row.host;
		trow.appendChild(cell);

		cell = document.createElement("td");
		cell.textContent = row.users;
		trow.appendChild(cell);

		cell = document.createElement("td");
		cell.textContent = row.entries;
		trow.appendChild(cell);

		cell = document.createElement("td");
		cell.textContent = interval(row.updated);
		trow.appendChild(cell);

		table.appendChild(trow);
	}

	var htable = document.getElementById("sessions");
	var hbody = htable.getElementsByTagName("tbody");
	htable.replaceChild(table, hbody[0]);
}

document.addEventListener("DOMContentLoaded", function (event) {
	setTimeout(fetch_data, update_interval);
}, true);
