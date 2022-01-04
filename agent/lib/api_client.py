import json
import requests
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

    def auth_set_basic(self, username, password):
        import requests.auth
        self.ua.auth = requests.auth.HTTPBasicAuth(username, password)

    def auth_set_kerberos(self, gss_service=None):
        import gssapi
        import requests_gssapi
        spnego = gssapi.Mechanism.from_sasl_name("SPNEGO")
        self.ua.auth = requests_gssapi.HTTPSPNEGOAuth(target_name=gss_service,
                                                      mech=spnego,
                                                      opportunistic_auth=True)

    def upload(self, action, data):
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
