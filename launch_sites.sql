-- Which rocketlaunch.live locations each TFR city maps to (used by rll_find_launch in common.inc).
-- A TFR matches a row when its FAA city contains faa_name (case-insensitive).
-- Load after schema.sql: mysql tfrmonitor < launch_sites.sql
/*M!999999\- enable the sandbox mode */ 
INSERT INTO `launch_sites` VALUES (1,'CANAVERAL','Cape Canaveral/Kennedy Space Center (FL)','cape-canaveral-sfs');
INSERT INTO `launch_sites` VALUES (2,'CANAVERAL','Cape Canaveral/Kennedy Space Center (FL)','kennedy-space-center');
INSERT INTO `launch_sites` VALUES (3,'BROWNSVILLE','Starbase (TX) SpaceX','starbase');
INSERT INTO `launch_sites` VALUES (4,'WALLOP','Wallops Flight Facility (Wallops Island, VA)','wallops-flight-facility');
INSERT INTO `launch_sites` VALUES (5,'VAN HORN','Corn Ranch (Van Horn, TX) Blue Origin','corn-ranch-spaceport');
INSERT INTO `launch_sites` VALUES (6,'CANAVERAL','Cape Canaveral/Kennedy Space Center (FL), pad TBD','cape-canaveral-ksc-tbd');
INSERT INTO `launch_sites` VALUES (7,'STARBASE','Starbase (TX) SpaceX','starbase');
INSERT INTO `launch_sites` VALUES (8,'BOCA CHICA','Starbase (TX) SpaceX','starbase');
INSERT INTO `launch_sites` VALUES (9,'WALLOP','Wallops (VA) MARS','mid-atlantic-regional-spaceport-wallops-island');
INSERT INTO `launch_sites` VALUES (10,'VANDENBERG','Vandenberg SFB (CA)','vandenberg-sfb');
INSERT INTO `launch_sites` VALUES (11,'LOMPOC','Vandenberg SFB (CA)','vandenberg-sfb');
INSERT INTO `launch_sites` VALUES (12,'SANTA BARBARA','Vandenberg SFB (CA)','vandenberg-sfb');
INSERT INTO `launch_sites` VALUES (13,'KODIAK','Pacific Spaceport Complex (AK)','pacific-spaceport-complex-alaska');
INSERT INTO `launch_sites` VALUES (14,'TRUTH OR CON','Spaceport America (NM)','spaceport-america');
INSERT INTO `launch_sites` VALUES (15,'SPACEPORT AMERICA','Spaceport America (NM)','spaceport-america');
