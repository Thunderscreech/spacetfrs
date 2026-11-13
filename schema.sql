/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `atcscc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `atcscc` (
  `atcscc_id` int(11) NOT NULL AUTO_INCREMENT,
  `atcscc_unique` varchar(32) NOT NULL,
  `atcscc_string` varchar(256) NOT NULL,
  `atcscc_tweet` varchar(300) NOT NULL,
  `atcscc_date` datetime NOT NULL DEFAULT current_timestamp(),
  `twitter_status` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`atcscc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `corrected_airspace`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corrected_airspace` (
  `ca_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_string` varchar(512) NOT NULL,
  `replacement_string` varchar(1024) NOT NULL,
  PRIMARY KEY (`ca_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `launch_site_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `launch_site_locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `site_name` varchar(128) NOT NULL,
  `site_latitude` float NOT NULL,
  `site_longitude` float NOT NULL,
  PRIMARY KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `launch_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `launch_sites` (
  `site_id` int(11) NOT NULL AUTO_INCREMENT,
  `faa_name` varchar(64) NOT NULL,
  `pretty_name` varchar(128) NOT NULL,
  `launch_location_slug` varchar(64) NOT NULL,
  PRIMARY KEY (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `navsafety`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `navsafety` (
  `msgNumber` int(11) NOT NULL,
  `navArea` varchar(16) NOT NULL,
  `subregion` varchar(16) NOT NULL,
  `text` varchar(4096) NOT NULL,
  `issueDate` timestamp NULL DEFAULT NULL,
  `authority` varchar(64) NOT NULL,
  `cancelDate` varchar(32) DEFAULT NULL,
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`msgNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `status` (
  `status_item` varchar(32) NOT NULL,
  `status_data` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tfr_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tfr_list` (
  `tfr_id` int(11) NOT NULL AUTO_INCREMENT,
  `tfr_unique` varchar(128) NOT NULL,
  `notam` varchar(16) DEFAULT NULL,
  `tfr_url` varchar(128) DEFAULT NULL,
  `tfr_map_image` varchar(128) DEFAULT NULL,
  `tfr_type` varchar(32) NOT NULL,
  `tfr_start_time` datetime DEFAULT NULL,
  `tfr_end_time` datetime DEFAULT NULL,
  `tfr_detail` varchar(128) NOT NULL,
  `raw_airspace` varchar(1024) DEFAULT NULL,
  `formatted_airspace` varchar(4096) DEFAULT NULL,
  `map_string` varchar(4096) DEFAULT NULL,
  `radius` int(8) DEFAULT NULL COMMENT 'If there''s a radius, note it here',
  `altitude` varchar(128) DEFAULT NULL,
  `date_added` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_early` tinyint(1) NOT NULL DEFAULT 0,
  `tweet` varchar(512) DEFAULT NULL,
  `twitter_status` varchar(32) DEFAULT NULL,
  `mastodon_status` varchar(32) DEFAULT NULL,
  `tfr_processed` tinyint(4) NOT NULL DEFAULT 0,
  `notam_year` smallint(6) GENERATED ALWAYS AS (year(`date_added`) + (cast(substring_index(`notam`,'/',1) as signed) - year(`date_added`) MOD 10 + 15) MOD 10 - 5) STORED COMMENT 'NOTAM issue year: the year ending in the NOTAM year digit closest to date_added (IDs repeat every 10 years)',
  PRIMARY KEY (`tfr_id`),
  UNIQUE KEY `uniq_notam_year` (`notam`,`notam_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tfr_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tfr_locations` (
  `tfr_location_id` int(11) NOT NULL AUTO_INCREMENT,
  `tfr_location_matchstring` varchar(64) NOT NULL,
  `tfr_location_title` varchar(256) NOT NULL,
  `tfr_location_tweet_string` varchar(256) DEFAULT NULL,
  `tfr_location_notes` varchar(256) DEFAULT NULL,
  PRIMARY KEY (`tfr_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `upcoming_launches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `upcoming_launches` (
  `launch_id` int(11) NOT NULL,
  `launch_name` varchar(64) DEFAULT NULL,
  `launch_provider` varchar(64) DEFAULT NULL,
  `launch_vehicle` varchar(64) DEFAULT NULL,
  `launch_location_slug` varchar(64) DEFAULT NULL,
  `launch_est_date` varchar(32) DEFAULT NULL,
  `launch_slug` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`launch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `us_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `us_states` (
  `state_name` varchar(50) NOT NULL,
  `state_abbr` char(2) NOT NULL,
  PRIMARY KEY (`state_abbr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weather_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `weather_reports` (
  `weather_id` int(11) NOT NULL AUTO_INCREMENT,
  `weather_href` varchar(256) NOT NULL,
  `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
  `launch_day_probability` varchar(12) DEFAULT NULL,
  `launch_day_concerns` varchar(256) DEFAULT NULL,
  `next_day_probability` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`weather_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

