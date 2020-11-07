#!/usr/bin/env python3
from pprint import pprint

from lib.event_loop import *
from lib.utmp_linux import enum_sessions
from lib.api_client import RwhoUploader
from lib.config import ConfigReader
import socket

config = ConfigReader("/etc/rwho/agent.conf")

server_url = "https://rwho.nullroute.eu.org/server.php"
client = RwhoUploader(server_url)
client.host_name = socket.gethostname().lower()
client.host_fqdn = socket.getfqdn().lower()
#client.host_fqdn = "ember-test.nullroute.eu.org"

'''
if p := config.get_str("agent.auth_password"):
    client.auth_method = "basic"
    client.auth_pass = p
'''

#client.auth_method = "gssapi"

sessions = [*enum_sessions()]
pprint(sessions)
client.put_sessions(sessions)
client.remove_host()
