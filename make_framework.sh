#!/bin/bash
set -ue

cd "$(dirname "$0")"

# use tempfile to prevent renewing mtime
F=$(mktemp)
echo -e '<?php\n/**\n' >"$F"
sed "s/CLIENT/$CLIENT/g" LICENSE | sed "s/APPLICATION/$APP/g" >>"$F"
echo -e '\n*/\n' >>"$F"

while read -r f
do
	php -w "$f" | grep -v '^<?php' >>"$F"
done < <(find ./library/ -type f -name \*.php)

echo 'Basic::bootstrap();' >>"$F"

# for rsync; make mtime equal to last actual modification, not now
touch -d "$(du -s --time .|cut -f2)" "$F"
mv -f "$F" framework.inc.php

chmod -w,+r framework.inc.php