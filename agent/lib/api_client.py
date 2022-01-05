import json
import requests
import socket
import sys

from .exceptions import *
from .json_rpc import JsonRpcClient, RemoteFault
from .log_util import *

class RwhoClient():
    def __init__(self, url, host_name=None):
        self.rpc = JsonRpcClient(url)
        self.host_name = host_name

    def set_auth_basic(self, username, password):
        import requests.auth
        self.rpc.ua.auth = requests.auth.HTTPBasicAuth(username, password)

    def set_auth_gssapi(self, service="HTTP"):
        import gssapi
        import requests_gssapi
        spnego = gssapi.Mechanism.from_sasl_name("SPNEGO")
        self.rpc.ua.auth = requests_gssapi.HTTPSPNEGOAuth(target_name=service,
                                                          mech=spnego,
                                                          opportunistic_auth=True)

    def call(self, method, *args):
        try:
            return self.rpc.call(method, args)
        except RemoteFault as e:
            if e.code == 403:
                raise RwhoUploadRejectedError(e.message) from None
            elif e.code == 410:
                raise RwhoShutdownRequestedError(e.message) from None
            else:
                raise

    def put_sessions(self, sessions):
        return self.call("PutEntries", self.host_name, [*sessions])

    def remove_host(self):
        return self.call("ClearEntries", self.host_name)
