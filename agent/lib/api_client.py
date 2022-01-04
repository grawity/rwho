import json
import requests
import requests.auth
import socket
import sys

from .exceptions import *
from .log_util import *

class RwhoClient():
    def __init__(self, url,
                       host_name=None,
                       host_fqdn=None,
                       auth_method=None,
                       auth_user=None,
                       auth_pass=None):
        self.url = url
        self.host_name = host_name
        self.host_fqdn = host_fqdn
        self.auth_method = auth_method
        self.auth_user = auth_user or host_fqdn
        self.auth_pass = auth_pass
        self.ua = requests.Session()

    # Defer constructing ua.auth so that .auth_user/.auth_pass could be
    # manually set by the caller.
    def _init_auth(self):
        if not self.ua.auth:
            if self.auth_method == "basic":
                self.ua.auth = requests.auth.HTTPBasicAuth(username=self.auth_user,
                                                           password=self.auth_pass)
            elif self.auth_method == "gssapi":
                import requests_gssapi
                self.ua.auth = requests_gssapi.HTTPSPNEGOAuth()
            elif self.auth_method:
                raise ValueError("Invalid auth_method %r" % self.auth_method)

    def upload(self, action, data):
        self._init_auth()
        log_debug("api: calling %r with %d items", action, len(data))
        payload = {
            "host": self.host_fqdn,
            "action": action,
            "utmp": json.dumps(data),
        }
        resp = self.ua.post(self.url, data=payload)
        resp.raise_for_status()
        log_debug("api: server returned %r", resp.text)
        if resp.text.strip() == "OK":
            return True
        elif resp.text.startswith("KOD"):
            raise RwhoShutdownRequestedError(resp.text.strip())
        else:
            raise RwhoUploadRejectedError(resp.text.strip())

    def put_sessions(self, sessions):
        return self.upload(action="put", data=[*sessions])

    def remove_host(self):
        return self.upload(action="destroy", data=[])
