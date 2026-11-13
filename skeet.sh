#!/bin/bash
export HOME=/home/chairboy
export PATH=$PATH:/opt/bsky_sh_cli/bin

# Secrets are read from config.ini next to this script (not in git; see config.example.ini)
bot_cfg() { php -r '$c = parse_ini_file($argv[1], true, INI_SCANNER_RAW); echo $c[$argv[2]][$argv[3]] ?? "";' "$(dirname "$0")/config.ini" "$1" "$2"; }

# Bluesky credentials with App Password
USERNAME="spacetfrs.bsky.social"
APP_PASSWORD="$(bot_cfg bluesky app_password)"

env > /home/chairboy/tfrmonitor/cron_env.log

echo "Running as user: $(whoami)"
echo "HOME: $HOME"
echo "PATH: $PATH"

# Assign arguments to variables
TEXT_FILE="$1"
IMAGE_PATH="$2"
echo "Version:"
bsky --version
# Authenticate with BlueSky using hardcoded username and app password
echo "Logging in $USERNAME"
bsky login --handle $USERNAME --password $APP_PASSWORD
echo "Text to post:"
cat /home/chairboy/tfrmonitor/skeet.txt
echo "Checking image readability:"
test -r /home/chairboy/tfrmonitor/rendered_maps/temp_bluesky_file.png && echo "Readable" || echo "Not readable"

CRON=true  bsky post --text-file /home/chairboy/tfrmonitor/skeet.txt --image /home/chairboy/tfrmonitor/rendered_maps/temp_bluesky_file.png
# (bsky post prints the new post above; the old debug file it used to cat here was stale and misleading)

echo "Logging out"
bsky logout

