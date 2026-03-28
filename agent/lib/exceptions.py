# Base classes

class RwhoServerError(Exception):
    # Exceptions which were caused by an API result from server
    pass

class RwhoPermanentError(Exception):
    # Exceptions which cause an exit with EX_NORESTART
    pass

# Exceptions

class UnauthorizedClientError(RwhoServerError):
    code = 1

class UnauthorizedHostError(RwhoServerError):
    code = 2

class ShutdownRequestedError(RwhoServerError, RwhoPermanentError):
    # Upload returned a 'KOD' result, or a stored KOD was found on startup
    code = 3
