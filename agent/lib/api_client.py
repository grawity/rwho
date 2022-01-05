from .exceptions import *
from .json_rpc import JsonRpcClient, RemoteFault

class RwhoClient():
    _fault_map = {
        403: RwhoUploadRejectedError,
        410: RwhoShutdownRequestedError,
    }

    def __init__(self, url, host_name=None):
        self.rpc = JsonRpcClient(url)
        self.host_name = host_name

    def set_auth_basic(self, username, password):
        self.rpc._set_auth_basic(username, password)

    def set_auth_gssapi(self, service="HTTP"):
        self.rpc._set_auth_gssapi(service)

    def call(self, method, *params):
        try:
            return self.rpc.call(method, params)
        except RemoteFault as e:
            if handler := self._fault_map.get(e.code):
                raise handler(e.message) from None
            else:
                raise

    def put_sessions(self, sessions):
        return self.call("PutEntries", self.host_name, [*sessions])

    def remove_host(self):
        return self.call("ClearEntries", self.host_name)
