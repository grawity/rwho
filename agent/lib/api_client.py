from .exceptions import *
from .json_rpc import JsonRpcClient, RemoteFault, ConnectionError

class RwhoClient(JsonRpcClient):
    _fault_map = {
        403: RwhoUploadRejectedError,
        410: RwhoShutdownRequestedError,
    }

    def __init__(self, url, *, host_name=None):
        super().__init__(url)
        self.host_name = host_name

    def rpc_call(self, method, params):
        try:
            return super().rpc_call(method, params)
        except RemoteFault as e:
            if handler := self._fault_map.get(e.code):
                raise handler(e.message) from None
            else:
                raise

    def verify_auth(self):
        return self.WhoAmI()

    def put_sessions(self, sessions):
        return self.PutHostEntries(self.host_name, [*sessions])

    def remove_host(self):
        return self.ClearHostEntries(self.host_name)
