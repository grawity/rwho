# Base classes

class RwhoServerError(Exception):
    # Exceptions which were caused by an API result from server
    pass

class RwhoPermanentError(Exception):
    # Exceptions which cause an exit with EX_NORESTART
    pass

# Exceptions

class RwhoUnauthorizedClientError(RwhoServerError):
    code = 1

class RwhoUnauthorizedHostError(RwhoServerError):
    code = 2

class RwhoShutdownRequestedError(RwhoServerError, RwhoPermanentError):
    # Upload returned a 'KOD' result, or a stored KOD was found on startup
    code = 3
