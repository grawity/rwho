#!/usr/bin/env python3
from pprint import pprint

from lib.event_loop import *
from lib.utmp_linux import enum_sessions
from lib.api_client import RwhoUploader
import socket

sessions = [*enum_sessions()]
pprint(sessions)

server_url = "https://rwho.nullroute.eu.org/server.php"
client = RwhoUploader(server_url)
client.hostname = socket.gethostname().lower()
client.hostfqdn = socket.getfqdn().lower()
client.hostfqdn = "ember-test.nullroute.eu.org"
client.send_sessions(sessions)
