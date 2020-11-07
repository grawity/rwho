#!/usr/bin/env python3
from pprint import pprint
import asyncio
import os
import pyinotify
import socket
import time

from lib.exceptions import *
from lib.utmp_linux import UTMP_PATH, enum_sessions
from lib.api_client import RwhoUploader
from lib.config import ConfigReader

class RwhoAgent():
    DEFAULT_SERVER = "https://rwho.nullroute.eu.org/server.php"
    CONFIG_PATH = "/etc/rwho/agent.conf"
    KOD_PATH = "/etc/rwho/agent.kod"

    def __init__(self, config_path=None):
        self._check_kod()
        self.config = ConfigReader(config_path or self.CONFIG_PATH)
        self.server_url = self.config.get_str("agent.notify_url", self.DEFAULT_SERVER)
        self.last_upload = -1
        self.wake_interval = 1*15
        self.update_interval = 5*60
        # TODO: Verify that update_interval >= wake_interval

        self.api = RwhoUploader(self.server_url)
        self.api.host_name = socket.gethostname().lower()
        self.api.host_fqdn = socket.getfqdn().lower()
        self.api.host_fqdn = "ember-test.nullroute.eu.org"

        if p := self.config.get_str("agent.auth_password"):
            self.api.auth_method = "basic"
            self.api.auth_pass = p

        #self.api.auth_method = "gssapi"

    def _check_kod(self):
        if os.path.exists(self.KOD_PATH):
            with open(self.KOD_PATH, "r") as fh:
                message = fh.readline()
            raise RwhoShutdownRequestedError(message)

    def _store_kod(self, message):
        with open(self.KOD_PATH, "w") as fh:
            fh.write(message)

    def refresh(self):
        sessions = [*enum_sessions()]
        print("uploading %d sessions" % len(sessions))
        try:
            self.api.put_sessions(sessions)
            self.last_upload = time.time()
        except RwhoShutdownRequestedError as e:
            print("shutdown requested, giving up")
            self._store_kod(e.args[0])
            raise

    def cleanup(self):
        print("removing all host data")
        try:
            self.api.remove_host()
        except RwhoShutdownRequestedError as e:
            print("shutdown requested, giving up")
            self._store_kod(e.args[0])
            raise

def run_forever(agent):
    loop = asyncio.get_event_loop()

    async def on_periodic_upload():
        while True:
            if agent.last_upload < time.time() - agent.update_interval:
                print("periodic: uploading on timer")
                try:
                    agent.refresh()
                except RwhoShutdownRequestedError:
                    loop.stop()
                    return True
                except Exception as e:
                    print("periodic: upload failed: %r" % e)
                    loop.stop()
                    return True
            else:
                print("periodic: last upload too recent, skipping")
            print("periodic: waiting %s seconds" % agent.wake_interval)
            await asyncio.sleep(agent.wake_interval)

    def on_inotify_event(event):
        print("inotify: uploading on event %r" % event)
        try:
            agent.refresh()
        except RwhoShutdownRequestedError:
            loop.stop()
            return True
        except Exception as e:
            print("inotify: upload failed: %r" % e)
            loop.stop()
            return True
        return False

    # TODO: Automatically set one from the other if missing
    if agent.wake_interval and agent.update_interval:
        periodic_task = loop.create_task(on_periodic_upload())

    watchmgr = pyinotify.WatchManager()
    watchmgr.add_watch(UTMP_PATH, pyinotify.IN_MODIFY)
    notifier = pyinotify.AsyncioNotifier(watchmgr, loop,
                                         default_proc_fun=on_inotify_event)

    try:
        loop.run_forever()
        print("loop was stopped")
        agent.cleanup()
    except KeyboardInterrupt:
        print("got SIGINT")
        loop.stop()
        agent.cleanup()

if __name__ == "__main__":
    try:
        agent = RwhoAgent()
        run_forever(agent)
    except RwhoShutdownRequestedError as e:
        print("exiting on server shutdown request: %s" % e.args[0])
        exit(1)
