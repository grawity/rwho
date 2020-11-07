class RwhoServerError(Exception):
    pass

class RwhoUploadRejectedError(RwhoServerError):
    pass

class RwhoShutdownRequestedError(RwhoServerError):
    pass
