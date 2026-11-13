#!/bin/bash
rm /home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png
rm /home/chairboy/tfrmonitor/launch_forecasts/probability.png
rm /home/chairboy/tfrmonitor/launch_forecasts/primary_concerns.png
rm /home/chairboy/tfrmonitor/launch_forecasts/24hourdelay.png
rm /home/chairboy/tfrmonitor/launch_forecasts/launchdate.png


pdftoppm $1 /home/chairboy/tfrmonitor/launch_forecasts/working.png -png

convert /home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png -crop 102x51+96+823 /home/chairboy/tfrmonitor/launch_forecasts/probability.png
convert /home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png -crop 829x60+405+820 /home/chairboy/tfrmonitor/launch_forecasts/primary_concerns.png
convert /home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png -crop 108x61+92+1137 /home/chairboy/tfrmonitor/launch_forecasts/24hourdelay.png
convert /home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png -crop 525x37+622+174 /home/chairboy/tfrmonitor/launch_forecasts/launchdate.png


#if [ ! -f /home/chairboy/bocaroadclosure/briefs/tmp.png-1.png ]; then
#    cp /home/chairboy/bocaroadclosure/briefs/blank.png /home/chairboy/bocaroadclosure/briefs/output.png
#fi