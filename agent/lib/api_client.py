import json
import requests
import socket
import sys

class RwhoUploader():
    def __init__(self, url,
                       hostname=None,
                       hostfqdn=None):
        self.url = url
        self.hostname = hostname
        self.hostfqdn = hostfqdn
        self.ua = requests.Session()

    def upload(self, action, data):
        from urllib.parse import urlencode
        from urllib.request import urlopen

        print("uploading %d items" % len(data))
        payload = {
            "host": self.hostname,
            "fqdn": self.hostfqdn,
            "opsys": sys.platform,
            "action": action,
            "utmp": json.dumps(data),
        }
        payload = urlencode(payload).encode("utf-8")
        resp = urlopen(self.url, payload)
        print("server returned: %r" % resp.readline().strip())

    def send_sessions(self, sessions):
        return self.upload(action="put",
                           data=[*sessions])
