#!/bin/bash
# The coordinator records this identity before granting permission to exec.
set -euo pipefail
attempt_dir="$1"
generation_file="$2"
generation="$3"
token="$4"
[[ "$attempt_dir" == /tmp/* && "$token" =~ ^[a-f0-9]{48}$ && ! -L "$attempt_dir" ]] || exit 1
[[ "$(cat "$generation_file" 2>/dev/null)" == "$generation" ]] || exit 1
stat_text="$(cat /proc/$$/stat)"
stat_fields="${stat_text##*) }"
start="$(awk '{print $20}' <<< "$stat_fields")"
printf '{"pid":%s,"start":"%s","token":"%s"}\n' "$$" "$start" "$token" > "$attempt_dir/owner.pending"
mv "$attempt_dir/owner.pending" "$attempt_dir/owner.json"
for ((round=0; round<100; round++)); do
  [[ "$(cat "$generation_file" 2>/dev/null)" == "$generation" ]] || exit 1
  if [[ -f "$attempt_dir/grant" && "$(cat "$attempt_dir/grant")" == "$token" ]]; then
    mapfile -d '' -t command < <(php -r '
      $spec = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
      foreach ($spec as $part) { if (!is_string($part) || str_contains($part, "\0")) { exit(1); } }
      echo implode("\0", $spec), "\0";
    ' "$attempt_dir/command.json")
    ((${#command[@]} > 0)) || exit 1
    exec "${command[@]}"
  fi
  sleep .05
done
exit 1
