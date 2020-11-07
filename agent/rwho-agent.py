#!/usr/bin/env python3
from pprint import pprint
import asyncio
import pyinotify
import socket
import time

from lib.utmp_linux import UTMP_PATH, enum_sessions
from lib.api_client import RwhoUploader
from lib.config import ConfigReader

class RwhoAgent():
    def __init__(self, config_path=None):
        self.config = ConfigReader("/etc/rwho/agent.conf")

        self.server_url = self.config.get_str("agent.notify_url",
                                              "https://rwho.nullroute.eu.org/server.php")

        self.api = RwhoUploader(self.server_url)
        self.api.host_name = socket.gethostname().lower()
        self.api.host_fqdn = socket.getfqdn().lower()
        #self.api.host_fqdn = "ember-test.nullroute.eu.org"

        if p := self.config.get_str("agent.auth_password"):
            self.api.auth_method = "basic"
            self.api.auth_pass = p

        #self.api.auth_method = "gssapi"

        self.last_upload = -1

    def refresh(self):
        sessions = [*enum_sessions()]
        print("uploading %d sessions" % len(sessions))
        self.api.put_sessions(sessions)
        self.last_upload = time.time()

    def cleanup(self):
        print("removing all host data")
        self.api.remove_host()

def run_forever(agent):
    async def periodic_upload():
        interval = 10*60
        interval = 15
        while True:
            print("periodic upload")
            agent.refresh()
            await asyncio.sleep(interval)

    def inotify_event(event):
        print("upload on inotify event %r" % event)
        agent.refresh()
        return False

    loop = asyncio.get_event_loop()

    periodic_task = loop.create_task(periodic_upload())

    watchmgr = pyinotify.WatchManager()
    watchmgr.add_watch(UTMP_PATH, pyinotify.IN_MODIFY)
    notifier = pyinotify.AsyncioNotifier(watchmgr, loop,
                                         default_proc_fun=inotify_event)

    try:
        loop.run_forever()
    except Exception as e:
        print("[got %r, shutting down]" % e)
        loop.stop()

if __name__ == "__main__":
    agent = RwhoAgent()
    run_forever(agent)
