#!/bin/sh
if ! command -v python3 >/dev/null 2>&1; then
    printf '%s\n' 'Python 3.9+ is required: https://www.python.org/downloads/'
    exit 1
fi
exec python3 "$(dirname "$0")/pull.py" "$@"
