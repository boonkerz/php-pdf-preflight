#!/bin/sh
# Fails if the extension links against anything but the C runtime.
set -eu
SO="$1"
ALLOWED='^(libc\.so\.6|libc\.musl-[a-z0-9_]+\.so\.1|libgcc_s\.so\.1|libm\.so\.6|libdl\.so\.2|libpthread\.so\.0|ld-linux[-a-z0-9_.]*\.so\.[0-9]+)$'
echo "Dynamic dependencies of $SO:"
NEEDED=$(readelf -d "$SO" 2>/dev/null | sed -n 's/.*Shared library: \[\(.*\)\]/\1/p' || true)
if [ -z "$NEEDED" ] && command -v ldd >/dev/null; then
  NEEDED=$(ldd "$SO" | awk '{print $1}' | xargs -n1 basename)
fi
STATUS=0
for lib in $NEEDED; do
  if echo "$lib" | grep -Eq "$ALLOWED"; then
    echo "  ok      $lib"
  else
    echo "  FORBIDDEN $lib"
    STATUS=1
  fi
done
exit $STATUS
