#!/bin/bash
# A detached worker must not keep its parent's flock descriptors alive. Use a
# fresh shell command so closing inherited FD 255 cannot close this script.
exec /bin/bash -c '
  for inherited_fd in /proc/$$/fd/*; do
    inherited_fd=${inherited_fd##*/}
    [[ "$inherited_fd" =~ ^[0-9]+$ ]] || continue
    if (( inherited_fd > 2 )); then eval "exec ${inherited_fd}>&-"; fi
  done
  exec "$@"
' zfsas-detach "$@"
