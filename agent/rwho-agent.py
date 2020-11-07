#!/usr/bin/env python3
from pprint import pprint
import asyncio
import pyinotify
import socket
import time

from lib.utmp_linux import UTMP_PATH, enum_sessions
from lib.api_client import RwhoUploader
from lib.config import ConfigReader

DEFAULT_SERVER = "https://rwho.nullroute.eu.org/server.php"

class RwhoAgent():
    def __init__(self, config_path=None):
        self.config = ConfigReader("/etc/rwho/agent.conf")
        self.server_url = self.config.get_str("agent.notify_url", DEFAULT_SERVER)
        self.last_upload = -1
        self.wake_interval = 1*60
        self.update_interval = 5*60
        # TODO: Verify that update_interval >= wake_interval

        self.api = RwhoUploader(self.server_url)
        self.api.host_name = socket.gethostname().lower()
        self.api.host_fqdn = socket.getfqdn().lower()
        #self.api.host_fqdn = "ember-test.nullroute.eu.org"

        if p := self.config.get_str("agent.auth_password"):
            self.api.auth_method = "basic"
            self.api.auth_pass = p

        #self.api.auth_method = "gssapi"

    def refresh(self):
        sessions = [*enum_sessions()]
        print("uploading %d sessions" % len(sessions))
        self.api.put_sessions(sessions)
        self.last_upload = time.time()

    def cleanup(self):
        print("removing all host data")
        self.api.remove_host()

def run_forever(agent):
    async def on_periodic_upload():
        while True:
            if agent.last_upload < time.time() - agent.update_interval:
                print("periodic upload")
                agent.refresh()
            else:
                print("periodic: last upload too recent, skipping")
            print("waiting %s seconds" % agent.wake_interval)
            await asyncio.sleep(agent.wake_interval)

    def on_inotify_event(event):
        print("upload on inotify event %r" % event)
        agent.refresh()
        print("event done")
        return False

    loop = asyncio.get_event_loop()

    # TODO: Automatically set one from the other if missing
    if agent.wake_interval and agent.update_interval:
        periodic_task = loop.create_task(on_periodic_upload())

    watchmgr = pyinotify.WatchManager()
    watchmgr.add_watch(UTMP_PATH, pyinotify.IN_MODIFY)
    notifier = pyinotify.AsyncioNotifier(watchmgr, loop,
                                         default_proc_fun=on_inotify_event)

    try:
        loop.run_forever()
    except KeyboardInterrupt:
        print("got SIGINT")
        loop.stop()
        agent.cleanup()
    except Exception as e:
        print("[got %r, shutting down]" % e)
        loop.stop()

if __name__ == "__main__":
    agent = RwhoAgent()
    run_forever(agent)
