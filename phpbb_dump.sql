/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.16-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: phpbb
-- ------------------------------------------------------
-- Server version	10.11.16-MariaDB-ubu2204

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

--
-- Table structure for table `phpbb_acl_groups`
--

DROP TABLE IF EXISTS `phpbb_acl_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_acl_groups` (
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_option_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_role_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_setting` tinyint(2) NOT NULL DEFAULT 0,
  KEY `group_id` (`group_id`),
  KEY `auth_opt_id` (`auth_option_id`),
  KEY `auth_role_id` (`auth_role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_acl_groups`
--

LOCK TABLES `phpbb_acl_groups` WRITE;
/*!40000 ALTER TABLE `phpbb_acl_groups` DISABLE KEYS */;
INSERT INTO `phpbb_acl_groups` VALUES
(1,0,91,0,1),
(1,0,100,0,1),
(1,0,119,0,1),
(5,0,0,5,0),
(5,0,0,1,0),
(2,0,0,6,0),
(3,0,0,6,0),
(4,0,0,5,0),
(4,0,0,10,0),
(1,1,0,17,0),
(2,1,0,17,0),
(3,1,0,17,0),
(6,1,0,17,0),
(1,2,0,17,0),
(2,2,0,15,0),
(3,2,0,15,0),
(4,2,0,21,0),
(5,2,0,14,0),
(5,2,0,10,0),
(6,2,0,19,0),
(7,0,0,23,0),
(7,2,0,24,0),
(1,0,91,0,1),
(1,0,100,0,1),
(1,0,119,0,1),
(5,0,0,5,0),
(5,0,0,1,0),
(2,0,0,6,0),
(3,0,0,6,0),
(4,0,0,5,0),
(4,0,0,10,0),
(1,1,0,17,0),
(2,1,0,17,0),
(3,1,0,17,0),
(6,1,0,17,0),
(1,2,0,17,0),
(2,2,0,15,0),
(3,2,0,15,0),
(4,2,0,21,0),
(5,2,0,14,0),
(5,2,0,10,0),
(6,2,0,19,0),
(7,0,0,23,0),
(7,2,0,24,0);
/*!40000 ALTER TABLE `phpbb_acl_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_acl_options`
--

DROP TABLE IF EXISTS `phpbb_acl_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_acl_options` (
  `auth_option_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `auth_option` varchar(50) NOT NULL DEFAULT '',
  `is_global` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_local` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `founder_only` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`auth_option_id`),
  UNIQUE KEY `auth_option` (`auth_option`)
) ENGINE=InnoDB AUTO_INCREMENT=251 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_acl_options`
--

LOCK TABLES `phpbb_acl_options` WRITE;
/*!40000 ALTER TABLE `phpbb_acl_options` DISABLE KEYS */;
INSERT INTO `phpbb_acl_options` VALUES
(1,'f_',0,1,0),
(2,'f_announce',0,1,0),
(3,'f_announce_global',0,1,0),
(4,'f_attach',0,1,0),
(5,'f_bbcode',0,1,0),
(6,'f_bump',0,1,0),
(7,'f_delete',0,1,0),
(8,'f_download',0,1,0),
(9,'f_edit',0,1,0),
(10,'f_email',0,1,0),
(11,'f_flash',0,1,0),
(12,'f_icons',0,1,0),
(13,'f_ignoreflood',0,1,0),
(14,'f_img',0,1,0),
(15,'f_list',0,1,0),
(16,'f_list_topics',0,1,0),
(17,'f_noapprove',0,1,0),
(18,'f_poll',0,1,0),
(19,'f_post',0,1,0),
(20,'f_postcount',0,1,0),
(21,'f_print',0,1,0),
(22,'f_read',0,1,0),
(23,'f_reply',0,1,0),
(24,'f_report',0,1,0),
(25,'f_search',0,1,0),
(26,'f_sigs',0,1,0),
(27,'f_smilies',0,1,0),
(28,'f_sticky',0,1,0),
(29,'f_subscribe',0,1,0),
(30,'f_user_lock',0,1,0),
(31,'f_vote',0,1,0),
(32,'f_votechg',0,1,0),
(33,'f_softdelete',0,1,0),
(34,'m_',1,1,0),
(35,'m_approve',1,1,0),
(36,'m_chgposter',1,1,0),
(37,'m_delete',1,1,0),
(38,'m_edit',1,1,0),
(39,'m_info',1,1,0),
(40,'m_lock',1,1,0),
(41,'m_merge',1,1,0),
(42,'m_move',1,1,0),
(43,'m_report',1,1,0),
(44,'m_split',1,1,0),
(45,'m_softdelete',1,1,0),
(46,'m_ban',1,0,0),
(47,'m_pm_report',1,0,0),
(48,'m_warn',1,0,0),
(49,'a_',1,0,0),
(50,'a_aauth',1,0,0),
(51,'a_attach',1,0,0),
(52,'a_authgroups',1,0,0),
(53,'a_authusers',1,0,0),
(54,'a_backup',1,0,0),
(55,'a_ban',1,0,0),
(56,'a_bbcode',1,0,0),
(57,'a_board',1,0,0),
(58,'a_bots',1,0,0),
(59,'a_clearlogs',1,0,0),
(60,'a_email',1,0,0),
(61,'a_extensions',1,0,0),
(62,'a_fauth',1,0,0),
(63,'a_forum',1,0,0),
(64,'a_forumadd',1,0,0),
(65,'a_forumdel',1,0,0),
(66,'a_group',1,0,0),
(67,'a_groupadd',1,0,0),
(68,'a_groupdel',1,0,0),
(69,'a_icons',1,0,0),
(70,'a_jabber',1,0,0),
(71,'a_language',1,0,0),
(72,'a_mauth',1,0,0),
(73,'a_modules',1,0,0),
(74,'a_names',1,0,0),
(75,'a_phpinfo',1,0,0),
(76,'a_profile',1,0,0),
(77,'a_prune',1,0,0),
(78,'a_ranks',1,0,0),
(79,'a_reasons',1,0,0),
(80,'a_roles',1,0,0),
(81,'a_search',1,0,0),
(82,'a_server',1,0,0),
(83,'a_styles',1,0,0),
(84,'a_switchperm',1,0,0),
(85,'a_uauth',1,0,0),
(86,'a_user',1,0,0),
(87,'a_userdel',1,0,0),
(88,'a_viewauth',1,0,0),
(89,'a_viewlogs',1,0,0),
(90,'a_words',1,0,0),
(91,'u_',1,0,0),
(92,'u_attach',1,0,0),
(93,'u_chgavatar',1,0,0),
(94,'u_chgcensors',1,0,0),
(95,'u_chgemail',1,0,0),
(96,'u_chggrp',1,0,0),
(97,'u_chgname',1,0,0),
(98,'u_chgpasswd',1,0,0),
(99,'u_chgprofileinfo',1,0,0),
(100,'u_download',1,0,0),
(101,'u_emoji',1,0,0),
(102,'u_hideonline',1,0,0),
(103,'u_ignoreflood',1,0,0),
(104,'u_masspm',1,0,0),
(105,'u_masspm_group',1,0,0),
(106,'u_pm_attach',1,0,0),
(107,'u_pm_bbcode',1,0,0),
(108,'u_pm_delete',1,0,0),
(109,'u_pm_download',1,0,0),
(110,'u_pm_edit',1,0,0),
(111,'u_pm_emailpm',1,0,0),
(112,'u_pm_flash',1,0,0),
(113,'u_pm_forward',1,0,0),
(114,'u_pm_img',1,0,0),
(115,'u_pm_printpm',1,0,0),
(116,'u_pm_smilies',1,0,0),
(117,'u_readpm',1,0,0),
(118,'u_savedrafts',1,0,0),
(119,'u_search',1,0,0),
(120,'u_sendemail',1,0,0),
(121,'u_sendim',1,0,0),
(122,'u_sendpm',1,0,0),
(123,'u_sig',1,0,0),
(124,'u_viewonline',1,0,0),
(125,'u_viewprofile',1,0,0);
/*!40000 ALTER TABLE `phpbb_acl_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_acl_roles`
--

DROP TABLE IF EXISTS `phpbb_acl_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_acl_roles` (
  `role_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(255) NOT NULL DEFAULT '',
  `role_description` text NOT NULL,
  `role_type` varchar(10) NOT NULL DEFAULT '',
  `role_order` smallint(4) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`role_id`),
  KEY `role_type` (`role_type`),
  KEY `role_order` (`role_order`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_acl_roles`
--

LOCK TABLES `phpbb_acl_roles` WRITE;
/*!40000 ALTER TABLE `phpbb_acl_roles` DISABLE KEYS */;
INSERT INTO `phpbb_acl_roles` VALUES
(1,'ROLE_ADMIN_STANDARD','ROLE_DESCRIPTION_ADMIN_STANDARD','a_',1),
(2,'ROLE_ADMIN_FORUM','ROLE_DESCRIPTION_ADMIN_FORUM','a_',3),
(3,'ROLE_ADMIN_USERGROUP','ROLE_DESCRIPTION_ADMIN_USERGROUP','a_',4),
(4,'ROLE_ADMIN_FULL','ROLE_DESCRIPTION_ADMIN_FULL','a_',2),
(5,'ROLE_USER_FULL','ROLE_DESCRIPTION_USER_FULL','u_',3),
(6,'ROLE_USER_STANDARD','ROLE_DESCRIPTION_USER_STANDARD','u_',1),
(7,'ROLE_USER_LIMITED','ROLE_DESCRIPTION_USER_LIMITED','u_',2),
(8,'ROLE_USER_NOPM','ROLE_DESCRIPTION_USER_NOPM','u_',4),
(9,'ROLE_USER_NOAVATAR','ROLE_DESCRIPTION_USER_NOAVATAR','u_',5),
(10,'ROLE_MOD_FULL','ROLE_DESCRIPTION_MOD_FULL','m_',3),
(11,'ROLE_MOD_STANDARD','ROLE_DESCRIPTION_MOD_STANDARD','m_',1),
(12,'ROLE_MOD_SIMPLE','ROLE_DESCRIPTION_MOD_SIMPLE','m_',2),
(13,'ROLE_MOD_QUEUE','ROLE_DESCRIPTION_MOD_QUEUE','m_',4),
(14,'ROLE_FORUM_FULL','ROLE_DESCRIPTION_FORUM_FULL','f_',7),
(15,'ROLE_FORUM_STANDARD','ROLE_DESCRIPTION_FORUM_STANDARD','f_',5),
(16,'ROLE_FORUM_NOACCESS','ROLE_DESCRIPTION_FORUM_NOACCESS','f_',1),
(17,'ROLE_FORUM_READONLY','ROLE_DESCRIPTION_FORUM_READONLY','f_',2),
(18,'ROLE_FORUM_LIMITED','ROLE_DESCRIPTION_FORUM_LIMITED','f_',3),
(19,'ROLE_FORUM_BOT','ROLE_DESCRIPTION_FORUM_BOT','f_',9),
(20,'ROLE_FORUM_ONQUEUE','ROLE_DESCRIPTION_FORUM_ONQUEUE','f_',8),
(21,'ROLE_FORUM_POLLS','ROLE_DESCRIPTION_FORUM_POLLS','f_',6),
(22,'ROLE_FORUM_LIMITED_POLLS','ROLE_DESCRIPTION_FORUM_LIMITED_POLLS','f_',4),
(23,'ROLE_USER_NEW_MEMBER','ROLE_DESCRIPTION_USER_NEW_MEMBER','u_',6),
(24,'ROLE_FORUM_NEW_MEMBER','ROLE_DESCRIPTION_FORUM_NEW_MEMBER','f_',10),
(25,'ROLE_ADMIN_STANDARD','ROLE_DESCRIPTION_ADMIN_STANDARD','a_',1),
(26,'ROLE_ADMIN_FORUM','ROLE_DESCRIPTION_ADMIN_FORUM','a_',3),
(27,'ROLE_ADMIN_USERGROUP','ROLE_DESCRIPTION_ADMIN_USERGROUP','a_',4),
(28,'ROLE_ADMIN_FULL','ROLE_DESCRIPTION_ADMIN_FULL','a_',2),
(29,'ROLE_USER_FULL','ROLE_DESCRIPTION_USER_FULL','u_',3),
(30,'ROLE_USER_STANDARD','ROLE_DESCRIPTION_USER_STANDARD','u_',1),
(31,'ROLE_USER_LIMITED','ROLE_DESCRIPTION_USER_LIMITED','u_',2),
(32,'ROLE_USER_NOPM','ROLE_DESCRIPTION_USER_NOPM','u_',4),
(33,'ROLE_USER_NOAVATAR','ROLE_DESCRIPTION_USER_NOAVATAR','u_',5),
(34,'ROLE_MOD_FULL','ROLE_DESCRIPTION_MOD_FULL','m_',3),
(35,'ROLE_MOD_STANDARD','ROLE_DESCRIPTION_MOD_STANDARD','m_',1),
(36,'ROLE_MOD_SIMPLE','ROLE_DESCRIPTION_MOD_SIMPLE','m_',2),
(37,'ROLE_MOD_QUEUE','ROLE_DESCRIPTION_MOD_QUEUE','m_',4),
(38,'ROLE_FORUM_FULL','ROLE_DESCRIPTION_FORUM_FULL','f_',7),
(39,'ROLE_FORUM_STANDARD','ROLE_DESCRIPTION_FORUM_STANDARD','f_',5),
(40,'ROLE_FORUM_NOACCESS','ROLE_DESCRIPTION_FORUM_NOACCESS','f_',1),
(41,'ROLE_FORUM_READONLY','ROLE_DESCRIPTION_FORUM_READONLY','f_',2),
(42,'ROLE_FORUM_LIMITED','ROLE_DESCRIPTION_FORUM_LIMITED','f_',3),
(43,'ROLE_FORUM_BOT','ROLE_DESCRIPTION_FORUM_BOT','f_',9),
(44,'ROLE_FORUM_ONQUEUE','ROLE_DESCRIPTION_FORUM_ONQUEUE','f_',8),
(45,'ROLE_FORUM_POLLS','ROLE_DESCRIPTION_FORUM_POLLS','f_',6),
(46,'ROLE_FORUM_LIMITED_POLLS','ROLE_DESCRIPTION_FORUM_LIMITED_POLLS','f_',4),
(47,'ROLE_USER_NEW_MEMBER','ROLE_DESCRIPTION_USER_NEW_MEMBER','u_',6),
(48,'ROLE_FORUM_NEW_MEMBER','ROLE_DESCRIPTION_FORUM_NEW_MEMBER','f_',10);
/*!40000 ALTER TABLE `phpbb_acl_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_acl_roles_data`
--

DROP TABLE IF EXISTS `phpbb_acl_roles_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_acl_roles_data` (
  `role_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_option_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_setting` tinyint(2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`role_id`,`auth_option_id`),
  KEY `ath_op_id` (`auth_option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_acl_roles_data`
--

LOCK TABLES `phpbb_acl_roles_data` WRITE;
/*!40000 ALTER TABLE `phpbb_acl_roles_data` DISABLE KEYS */;
INSERT INTO `phpbb_acl_roles_data` VALUES
(1,49,1),
(1,51,1),
(1,52,1),
(1,53,1),
(1,55,1),
(1,56,1),
(1,57,1),
(1,61,1),
(1,62,1),
(1,63,1),
(1,64,1),
(1,65,1),
(1,66,1),
(1,67,1),
(1,68,1),
(1,69,1),
(1,72,1),
(1,74,1),
(1,76,1),
(1,77,1),
(1,78,1),
(1,79,1),
(1,85,1),
(1,86,1),
(1,87,1),
(1,88,1),
(1,89,1),
(1,90,1),
(2,49,1),
(2,52,1),
(2,53,1),
(2,62,1),
(2,63,1),
(2,64,1),
(2,65,1),
(2,72,1),
(2,77,1),
(2,85,1),
(2,88,1),
(2,89,1),
(3,49,1),
(3,52,1),
(3,53,1),
(3,55,1),
(3,66,1),
(3,67,1),
(3,68,1),
(3,78,1),
(3,85,1),
(3,86,1),
(3,88,1),
(3,89,1),
(4,49,1),
(4,50,1),
(4,51,1),
(4,52,1),
(4,53,1),
(4,54,1),
(4,55,1),
(4,56,1),
(4,57,1),
(4,58,1),
(4,59,1),
(4,60,1),
(4,61,1),
(4,62,1),
(4,63,1),
(4,64,1),
(4,65,1),
(4,66,1),
(4,67,1),
(4,68,1),
(4,69,1),
(4,70,1),
(4,71,1),
(4,72,1),
(4,73,1),
(4,74,1),
(4,75,1),
(4,76,1),
(4,77,1),
(4,78,1),
(4,79,1),
(4,80,1),
(4,81,1),
(4,82,1),
(4,83,1),
(4,84,1),
(4,85,1),
(4,86,1),
(4,87,1),
(4,88,1),
(4,89,1),
(4,90,1),
(5,91,1),
(5,92,1),
(5,93,1),
(5,94,1),
(5,95,1),
(5,96,1),
(5,97,1),
(5,98,1),
(5,99,1),
(5,100,1),
(5,101,1),
(5,102,1),
(5,103,1),
(5,104,1),
(5,105,1),
(5,106,1),
(5,107,1),
(5,108,1),
(5,109,1),
(5,110,1),
(5,111,1),
(5,112,1),
(5,113,1),
(5,114,1),
(5,115,1),
(5,116,1),
(5,117,1),
(5,118,1),
(5,119,1),
(5,120,1),
(5,121,1),
(5,122,1),
(5,123,1),
(5,124,1),
(5,125,1),
(6,91,1),
(6,92,1),
(6,93,1),
(6,94,1),
(6,95,1),
(6,98,1),
(6,99,1),
(6,100,1),
(6,101,1),
(6,102,1),
(6,104,1),
(6,105,1),
(6,106,1),
(6,107,1),
(6,108,1),
(6,109,1),
(6,110,1),
(6,111,1),
(6,114,1),
(6,115,1),
(6,116,1),
(6,117,1),
(6,118,1),
(6,119,1),
(6,120,1),
(6,121,1),
(6,122,1),
(6,123,1),
(6,125,1),
(7,91,1),
(7,93,1),
(7,94,1),
(7,95,1),
(7,98,1),
(7,99,1),
(7,100,1),
(7,101,1),
(7,102,1),
(7,107,1),
(7,108,1),
(7,109,1),
(7,110,1),
(7,113,1),
(7,114,1),
(7,115,1),
(7,116,1),
(7,117,1),
(7,122,1),
(7,123,1),
(7,125,1),
(8,91,1),
(8,93,1),
(8,94,1),
(8,95,1),
(8,98,1),
(8,100,1),
(8,102,1),
(8,104,0),
(8,105,0),
(8,117,0),
(8,122,0),
(8,123,1),
(8,125,1),
(9,91,1),
(9,93,0),
(9,94,1),
(9,95,1),
(9,98,1),
(9,99,1),
(9,100,1),
(9,101,1),
(9,102,1),
(9,107,1),
(9,108,1),
(9,109,1),
(9,110,1),
(9,113,1),
(9,114,1),
(9,115,1),
(9,116,1),
(9,117,1),
(9,122,1),
(9,123,1),
(9,125,1),
(10,34,1),
(10,35,1),
(10,36,1),
(10,37,1),
(10,38,1),
(10,39,1),
(10,40,1),
(10,41,1),
(10,42,1),
(10,43,1),
(10,44,1),
(10,45,1),
(10,46,1),
(10,47,1),
(10,48,1),
(11,34,1),
(11,35,1),
(11,37,1),
(11,38,1),
(11,39,1),
(11,40,1),
(11,41,1),
(11,42,1),
(11,43,1),
(11,44,1),
(11,45,1),
(11,47,1),
(11,48,1),
(12,34,1),
(12,37,1),
(12,38,1),
(12,39,1),
(12,43,1),
(12,45,1),
(12,47,1),
(13,34,1),
(13,35,1),
(13,38,1),
(14,1,1),
(14,2,1),
(14,3,1),
(14,4,1),
(14,5,1),
(14,6,1),
(14,7,1),
(14,8,1),
(14,9,1),
(14,10,1),
(14,11,1),
(14,12,1),
(14,13,1),
(14,14,1),
(14,15,1),
(14,16,1),
(14,17,1),
(14,18,1),
(14,19,1),
(14,20,1),
(14,21,1),
(14,22,1),
(14,23,1),
(14,24,1),
(14,25,1),
(14,26,1),
(14,27,1),
(14,28,1),
(14,29,1),
(14,30,1),
(14,31,1),
(14,32,1),
(14,33,1),
(15,1,1),
(15,4,1),
(15,5,1),
(15,6,1),
(15,7,1),
(15,8,1),
(15,9,1),
(15,10,1),
(15,12,1),
(15,14,1),
(15,15,1),
(15,16,1),
(15,17,1),
(15,19,1),
(15,20,1),
(15,21,1),
(15,22,1),
(15,23,1),
(15,24,1),
(15,25,1),
(15,26,1),
(15,27,1),
(15,29,1),
(15,31,1),
(15,32,1),
(15,33,1),
(16,1,0),
(17,1,1),
(17,8,1),
(17,15,1),
(17,16,1),
(17,21,1),
(17,22,1),
(17,25,1),
(17,29,1),
(18,1,1),
(18,5,1),
(18,8,1),
(18,9,1),
(18,10,1),
(18,14,1),
(18,15,1),
(18,16,1),
(18,17,1),
(18,19,1),
(18,20,1),
(18,21,1),
(18,22,1),
(18,23,1),
(18,24,1),
(18,25,1),
(18,26,1),
(18,27,1),
(18,29,1),
(18,31,1),
(18,33,1),
(19,1,1),
(19,8,1),
(19,15,1),
(19,16,1),
(19,21,1),
(19,22,1),
(20,1,1),
(20,4,1),
(20,5,1),
(20,8,1),
(20,9,1),
(20,10,1),
(20,14,1),
(20,15,1),
(20,16,1),
(20,17,0),
(20,19,1),
(20,20,1),
(20,21,1),
(20,22,1),
(20,23,1),
(20,24,1),
(20,25,1),
(20,26,1),
(20,27,1),
(20,29,1),
(20,31,1),
(20,33,1),
(21,1,1),
(21,4,1),
(21,5,1),
(21,6,1),
(21,7,1),
(21,8,1),
(21,9,1),
(21,10,1),
(21,12,1),
(21,14,1),
(21,15,1),
(21,16,1),
(21,17,1),
(21,18,1),
(21,19,1),
(21,20,1),
(21,21,1),
(21,22,1),
(21,23,1),
(21,24,1),
(21,25,1),
(21,26,1),
(21,27,1),
(21,29,1),
(21,31,1),
(21,32,1),
(21,33,1),
(22,1,1),
(22,5,1),
(22,8,1),
(22,9,1),
(22,10,1),
(22,14,1),
(22,15,1),
(22,16,1),
(22,17,1),
(22,18,1),
(22,19,1),
(22,20,1),
(22,21,1),
(22,22,1),
(22,23,1),
(22,24,1),
(22,25,1),
(22,26,1),
(22,27,1),
(22,29,1),
(22,31,1),
(22,33,1),
(23,99,0),
(23,104,0),
(23,105,0),
(23,122,0),
(24,17,0);
/*!40000 ALTER TABLE `phpbb_acl_roles_data` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_acl_users`
--

DROP TABLE IF EXISTS `phpbb_acl_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_acl_users` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_option_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_role_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_setting` tinyint(2) NOT NULL DEFAULT 0,
  KEY `user_id` (`user_id`),
  KEY `auth_option_id` (`auth_option_id`),
  KEY `auth_role_id` (`auth_role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_acl_users`
--

LOCK TABLES `phpbb_acl_users` WRITE;
/*!40000 ALTER TABLE `phpbb_acl_users` DISABLE KEYS */;
INSERT INTO `phpbb_acl_users` VALUES
(2,0,0,5,0),
(2,0,0,5,0);
/*!40000 ALTER TABLE `phpbb_acl_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_attachments`
--

DROP TABLE IF EXISTS `phpbb_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_attachments` (
  `attach_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_msg_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `in_message` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `is_orphan` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `physical_filename` varchar(255) NOT NULL DEFAULT '',
  `real_filename` varchar(255) NOT NULL DEFAULT '',
  `download_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `attach_comment` text NOT NULL,
  `extension` varchar(100) NOT NULL DEFAULT '',
  `mimetype` varchar(100) NOT NULL DEFAULT '',
  `filesize` int(20) unsigned NOT NULL DEFAULT 0,
  `filetime` int(11) unsigned NOT NULL DEFAULT 0,
  `thumbnail` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`attach_id`),
  KEY `filetime` (`filetime`),
  KEY `post_msg_id` (`post_msg_id`),
  KEY `topic_id` (`topic_id`),
  KEY `poster_id` (`poster_id`),
  KEY `is_orphan` (`is_orphan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_attachments`
--

LOCK TABLES `phpbb_attachments` WRITE;
/*!40000 ALTER TABLE `phpbb_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_banlist`
--

DROP TABLE IF EXISTS `phpbb_banlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_banlist` (
  `ban_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ban_userid` int(10) unsigned NOT NULL DEFAULT 0,
  `ban_ip` varchar(40) NOT NULL DEFAULT '',
  `ban_email` varchar(100) NOT NULL DEFAULT '',
  `ban_start` int(11) unsigned NOT NULL DEFAULT 0,
  `ban_end` int(11) unsigned NOT NULL DEFAULT 0,
  `ban_exclude` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `ban_reason` varchar(255) NOT NULL DEFAULT '',
  `ban_give_reason` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`ban_id`),
  KEY `ban_end` (`ban_end`),
  KEY `ban_user` (`ban_userid`,`ban_exclude`),
  KEY `ban_email` (`ban_email`,`ban_exclude`),
  KEY `ban_ip` (`ban_ip`,`ban_exclude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_banlist`
--

LOCK TABLES `phpbb_banlist` WRITE;
/*!40000 ALTER TABLE `phpbb_banlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_banlist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_bbcodes`
--

DROP TABLE IF EXISTS `phpbb_bbcodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_bbcodes` (
  `bbcode_id` smallint(4) unsigned NOT NULL DEFAULT 0,
  `bbcode_tag` varchar(16) NOT NULL DEFAULT '',
  `bbcode_helpline` text NOT NULL,
  `display_on_posting` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `bbcode_match` text NOT NULL,
  `bbcode_tpl` mediumtext NOT NULL,
  `first_pass_match` mediumtext NOT NULL,
  `first_pass_replace` mediumtext NOT NULL,
  `second_pass_match` mediumtext NOT NULL,
  `second_pass_replace` mediumtext NOT NULL,
  PRIMARY KEY (`bbcode_id`),
  KEY `display_on_post` (`display_on_posting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_bbcodes`
--

LOCK TABLES `phpbb_bbcodes` WRITE;
/*!40000 ALTER TABLE `phpbb_bbcodes` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_bbcodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_bookmarks`
--

DROP TABLE IF EXISTS `phpbb_bookmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_bookmarks` (
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`topic_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_bookmarks`
--

LOCK TABLES `phpbb_bookmarks` WRITE;
/*!40000 ALTER TABLE `phpbb_bookmarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_bookmarks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_bots`
--

DROP TABLE IF EXISTS `phpbb_bots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_bots` (
  `bot_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bot_active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `bot_name` varchar(255) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `bot_agent` varchar(255) NOT NULL DEFAULT '',
  `bot_ip` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`bot_id`),
  KEY `bot_active` (`bot_active`)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_bots`
--

LOCK TABLES `phpbb_bots` WRITE;
/*!40000 ALTER TABLE `phpbb_bots` DISABLE KEYS */;
INSERT INTO `phpbb_bots` VALUES
(1,1,'AdsBot [Google]',3,'AdsBot-Google',''),
(2,1,'Ahrefs [Bot]',4,'AhrefsBot/',''),
(3,1,'Alexa [Bot]',5,'ia_archiver',''),
(4,1,'Alta Vista [Bot]',6,'Scooter/',''),
(5,1,'Amazon [Bot]',7,'Amazonbot/',''),
(6,1,'Ask Jeeves [Bot]',8,'Ask Jeeves',''),
(7,1,'Baidu [Spider]',9,'Baiduspider',''),
(8,1,'Bing [Bot]',10,'bingbot/',''),
(9,1,'DuckDuckGo [Bot]',11,'DuckDuckBot/',''),
(10,1,'Exabot [Bot]',12,'Exabot/',''),
(11,1,'FAST Enterprise [Crawler]',13,'FAST Enterprise Crawler',''),
(12,1,'FAST WebCrawler [Crawler]',14,'FAST-WebCrawler/',''),
(13,1,'Francis [Bot]',15,'http://www.neomo.de/',''),
(14,1,'Gigabot [Bot]',16,'Gigabot/',''),
(15,1,'Google Adsense [Bot]',17,'Mediapartners-Google',''),
(16,1,'Google Desktop',18,'Google Desktop',''),
(17,1,'Google Feedfetcher',19,'Feedfetcher-Google',''),
(18,1,'Google [Bot]',20,'Googlebot',''),
(19,1,'Heise IT-Markt [Crawler]',21,'heise-IT-Markt-Crawler',''),
(20,1,'Heritrix [Crawler]',22,'heritrix/1.',''),
(21,1,'IBM Research [Bot]',23,'ibm.com/cs/crawler',''),
(22,1,'ICCrawler - ICjobs',24,'ICCrawler - ICjobs',''),
(23,1,'ichiro [Crawler]',25,'ichiro/',''),
(24,1,'Majestic-12 [Bot]',26,'MJ12bot/',''),
(25,1,'Metager [Bot]',27,'MetagerBot/',''),
(26,1,'MSN NewsBlogs',28,'msnbot-NewsBlogs/',''),
(27,1,'MSN [Bot]',29,'msnbot/',''),
(28,1,'MSNbot Media',30,'msnbot-media/',''),
(29,1,'NG-Search [Bot]',31,'NG-Search/',''),
(30,1,'Nutch [Bot]',32,'http://lucene.apache.org/nutch/',''),
(31,1,'Nutch/CVS [Bot]',33,'NutchCVS/',''),
(32,1,'OmniExplorer [Bot]',34,'OmniExplorer_Bot/',''),
(33,1,'Online link [Validator]',35,'online link validator',''),
(34,1,'psbot [Picsearch]',36,'psbot/0',''),
(35,1,'Seekport [Bot]',37,'Seekbot/',''),
(36,1,'Semrush [Bot]',38,'SemrushBot/',''),
(37,1,'Sensis [Crawler]',39,'Sensis Web Crawler',''),
(38,1,'SEO Crawler',40,'SEO search Crawler/',''),
(39,1,'Seoma [Crawler]',41,'Seoma [SEO Crawler]',''),
(40,1,'SEOSearch [Crawler]',42,'SEOsearch/',''),
(41,1,'Snappy [Bot]',43,'Snappy/1.1 ( http://www.urltrends.com/ )',''),
(42,1,'Steeler [Crawler]',44,'http://www.tkl.iis.u-tokyo.ac.jp/~crawler/',''),
(43,1,'Synoo [Bot]',45,'SynooBot/',''),
(44,1,'Telekom [Bot]',46,'crawleradmin.t-info@telekom.de',''),
(45,1,'TurnitinBot [Bot]',47,'TurnitinBot/',''),
(46,1,'Voyager [Bot]',48,'voyager/',''),
(47,1,'W3 [Sitesearch]',49,'W3 SiteSearch Crawler',''),
(48,1,'W3C [Linkcheck]',50,'W3C-checklink/',''),
(49,1,'W3C [Validator]',51,'W3C_*Validator',''),
(50,1,'WiseNut [Bot]',52,'http://www.WISEnutbot.com',''),
(51,1,'YaCy [Bot]',53,'yacybot',''),
(52,1,'Yahoo MMCrawler [Bot]',54,'Yahoo-MMCrawler/',''),
(53,1,'Yahoo Slurp [Bot]',55,'Yahoo! DE Slurp',''),
(54,1,'Yahoo [Bot]',56,'Yahoo! Slurp',''),
(55,1,'YahooSeeker [Bot]',57,'YahooSeeker/','');
/*!40000 ALTER TABLE `phpbb_bots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_config`
--

DROP TABLE IF EXISTS `phpbb_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_config` (
  `config_name` varchar(255) NOT NULL DEFAULT '',
  `config_value` varchar(255) NOT NULL DEFAULT '',
  `is_dynamic` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`config_name`),
  KEY `is_dynamic` (`is_dynamic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_config`
--

LOCK TABLES `phpbb_config` WRITE;
/*!40000 ALTER TABLE `phpbb_config` DISABLE KEYS */;
INSERT INTO `phpbb_config` VALUES
('active_sessions','0',0),
('allow_attachments','1',0),
('allow_autologin','1',0),
('allow_avatar','1',0),
('allow_avatar_gravatar','0',0),
('allow_avatar_local','0',0),
('allow_avatar_remote','0',0),
('allow_avatar_remote_upload','0',0),
('allow_avatar_upload','1',0),
('allow_bbcode','1',0),
('allow_birthdays','1',0),
('allow_board_notifications','1',0),
('allow_bookmarks','1',0),
('allow_cdn','0',0),
('allow_emailreuse','0',0),
('allow_forum_notify','1',0),
('allow_live_searches','1',0),
('allow_mass_pm','1',0),
('allow_name_chars','USERNAME_CHARS_ANY',0),
('allow_namechange','0',0),
('allow_nocensors','0',0),
('allow_password_reset','1',0),
('allow_pm_attach','0',0),
('allow_pm_report','1',0),
('allow_post_flash','1',0),
('allow_post_links','1',0),
('allow_privmsg','1',0),
('allow_quick_reply','1',0),
('allow_sig','1',0),
('allow_sig_bbcode','1',0),
('allow_sig_flash','0',0),
('allow_sig_img','1',0),
('allow_sig_links','1',0),
('allow_sig_pm','1',0),
('allow_sig_smilies','1',0),
('allow_smilies','1',0),
('allow_topic_notify','1',0),
('allowed_schemes_links','http,https,ftp',0),
('assets_version','1',0),
('attachment_quota','52428800',0),
('auth_bbcode_pm','1',0),
('auth_flash_pm','0',0),
('auth_img_pm','1',0),
('auth_method','db',0),
('auth_smilies_pm','1',0),
('avatar_filesize','6144',0),
('avatar_gallery_path','images/avatars/gallery',0),
('avatar_max_height','90',0),
('avatar_max_width','90',0),
('avatar_min_height','20',0),
('avatar_min_width','20',0),
('avatar_path','images/avatars/upload',0),
('avatar_salt','8fe48759f3b20fe7c212b7e838478fe0',0),
('board_contact','admin@domain.tld',0),
('board_contact_name','',0),
('board_disable','0',0),
('board_disable_msg','',0),
('board_email','admin@domain.tld',0),
('board_email_form','0',0),
('board_email_sig','Thanks, The Management',0),
('board_hide_emails','1',0),
('board_index_text','',0),
('board_startdate','1776276428',0),
('board_timezone','UTC',0),
('browser_check','1',0),
('bump_interval','10',0),
('bump_type','d',0),
('cache_gc','7200',0),
('cache_last_gc','0',1),
('captcha_gd','1',0),
('captcha_gd_3d_noise','1',0),
('captcha_gd_fonts','1',0),
('captcha_gd_foreground_noise','0',0),
('captcha_gd_wave','0',0),
('captcha_gd_x_grid','25',0),
('captcha_gd_y_grid','25',0),
('captcha_plugin','core.captcha.plugins.gd',0),
('check_attachment_content','1',0),
('check_dnsbl','0',0),
('chg_passforce','0',0),
('confirm_refresh','1',0),
('contact_admin_form_enable','1',0),
('cookie_domain','localhost',0),
('cookie_name','phpbb3_5yukg',0),
('cookie_notice','0',0),
('cookie_path','/',0),
('cookie_secure','',0),
('coppa_enable','0',0),
('coppa_fax','',0),
('coppa_mail','',0),
('cron_lock','0',1),
('database_gc','604800',0),
('database_last_gc','0',1),
('dbms_version','10.11.16-MariaDB-ubu2204',0),
('default_dateformat','D M d, Y g:i a',0),
('default_lang','en',0),
('default_search_return_chars','300',0),
('default_style','1',0),
('delete_time','0',0),
('display_last_edited','1',0),
('display_last_subject','1',0),
('display_order','0',0),
('display_unapproved_posts','1',0),
('edit_time','0',0),
('email_check_mx','1',0),
('email_enable','',0),
('email_force_sender','0',0),
('email_max_chunk_size','50',0),
('email_package_size','20',0),
('enable_accurate_pm_button','1',0),
('enable_confirm','1',0),
('enable_mod_rewrite','0',0),
('enable_pm_icons','1',0),
('enable_post_confirm','1',0),
('enable_queue_trigger','0',0),
('enable_update_hashes','1',0),
('extension_force_unstable','0',0),
('feed_enable','1',0),
('feed_forum','1',0),
('feed_http_auth','0',0),
('feed_item_statistics','1',0),
('feed_limit','10',0),
('feed_limit_post','15',0),
('feed_limit_topic','10',0),
('feed_overall','1',0),
('feed_overall_forums','0',0),
('feed_overall_forums_limit','15',0),
('feed_overall_topics','0',0),
('feed_overall_topics_limit','15',0),
('feed_topic','1',0),
('feed_topics_active','0',0),
('feed_topics_new','1',0),
('flood_interval','15',0),
('force_server_vars','0',0),
('form_token_lifetime','7200',0),
('form_token_mintime','0',0),
('form_token_sid_guests','1',0),
('forward_pm','1',0),
('forwarded_for_check','0',0),
('full_folder_action','2',0),
('fulltext_mysql_max_word_len','254',0),
('fulltext_mysql_min_word_len','4',0),
('fulltext_native_common_thres','5',0),
('fulltext_native_load_upd','1',0),
('fulltext_native_max_chars','14',0),
('fulltext_native_min_chars','3',0),
('fulltext_postgres_max_word_len','254',0),
('fulltext_postgres_min_word_len','4',0),
('fulltext_postgres_ts_name','simple',0),
('fulltext_sphinx_indexer_mem_limit','512',0),
('fulltext_sphinx_stopwords','0',0),
('gzip_compress','0',0),
('help_send_statistics','1',0),
('help_send_statistics_time','0',0),
('hot_threshold','25',0),
('icons_path','images/icons',0),
('img_create_thumbnail','0',0),
('img_display_inlined','1',0),
('img_link_height','0',0),
('img_link_width','0',0),
('img_max_height','0',0),
('img_max_thumb_width','400',0),
('img_max_width','0',0),
('img_min_thumb_filesize','12000',0),
('img_quality','85',0),
('img_strip_metadata','0',0),
('ip_check','3',0),
('ip_login_limit_max','50',0),
('ip_login_limit_time','21600',0),
('ip_login_limit_use_forwarded','0',0),
('jab_allow_self_signed','0',0),
('jab_enable','0',0),
('jab_host','',0),
('jab_package_size','20',0),
('jab_password','',0),
('jab_port','5222',0),
('jab_use_ssl','0',0),
('jab_username','',0),
('jab_verify_peer','1',0),
('jab_verify_peer_name','1',0),
('last_queue_run','0',1),
('ldap_base_dn','',0),
('ldap_email','',0),
('ldap_password','',0),
('ldap_port','',0),
('ldap_server','',0),
('ldap_uid','',0),
('ldap_user','',0),
('ldap_user_filter','',0),
('legend_sort_groupname','0',0),
('limit_load','0',0),
('limit_search_load','0',0),
('load_anon_lastread','0',0),
('load_birthdays','1',0),
('load_cpf_memberlist','1',0),
('load_cpf_pm','1',0),
('load_cpf_viewprofile','1',0),
('load_cpf_viewtopic','1',0),
('load_db_lastread','1',0),
('load_db_track','1',0),
('load_font_awesome_url','https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css',0),
('load_jquery_url','//ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js',0),
('load_jumpbox','1',0),
('load_moderators','1',0),
('load_notifications','1',0),
('load_online','1',0),
('load_online_guests','1',0),
('load_online_time','5',0),
('load_onlinetrack','1',0),
('load_search','1',0),
('load_tplcompile','0',0),
('load_unreads_search','1',0),
('load_user_activity','1',0),
('load_user_activity_limit','5000',0),
('max_attachments','3',0),
('max_attachments_pm','1',0),
('max_autologin_time','0',0),
('max_filesize','262144',0),
('max_filesize_pm','262144',0),
('max_login_attempts','3',0),
('max_name_chars','20',0),
('max_num_search_keywords','10',0),
('max_poll_options','10',0),
('max_post_chars','60000',0),
('max_post_font_size','200',0),
('max_post_img_height','0',0),
('max_post_img_width','0',0),
('max_post_smilies','0',0),
('max_post_urls','0',0),
('max_quote_depth','3',0),
('max_reg_attempts','5',0),
('max_sig_chars','255',0),
('max_sig_font_size','200',0),
('max_sig_img_height','0',0),
('max_sig_img_width','0',0),
('max_sig_smilies','0',0),
('max_sig_urls','5',0),
('mime_triggers','body|head|html|img|plaintext|a href|pre|script|table|title',0),
('min_name_chars','3',0),
('min_pass_chars','6',0),
('min_post_chars','1',0),
('min_search_author_chars','3',0),
('new_member_group_default','0',0),
('new_member_post_limit','3',0),
('newest_user_colour','AA0000',1),
('newest_user_id','2',1),
('newest_username','admin',1),
('num_files','0',1),
('num_posts','1',1),
('num_topics','1',1),
('num_users','1',1),
('override_user_style','0',0),
('pass_complex','PASS_TYPE_ANY',0),
('plupload_last_gc','0',1),
('plupload_salt','247325349a9730fdc9b7b781bf49d38f',0),
('pm_edit_time','0',0),
('pm_max_boxes','4',0),
('pm_max_msgs','50',0),
('pm_max_recipients','0',0),
('posts_per_page','10',0),
('print_pm','1',0),
('queue_interval','60',0),
('queue_trigger_posts','3',0),
('rand_seed','0',1),
('rand_seed_last_update','0',1),
('ranks_path','images/ranks',0),
('read_notification_expire_days','30',0),
('read_notification_gc','86400',0),
('read_notification_last_gc','0',1),
('recaptcha_v3_domain','google.com',0),
('recaptcha_v3_key','',0),
('recaptcha_v3_method','post',0),
('recaptcha_v3_secret','',0),
('recaptcha_v3_threshold_default','0.5',0),
('recaptcha_v3_threshold_login','0.5',0),
('recaptcha_v3_threshold_post','0.5',0),
('recaptcha_v3_threshold_register','0.5',0),
('recaptcha_v3_threshold_report','0.5',0),
('record_online_date','0',1),
('record_online_users','0',1),
('referer_validation','0',0),
('remote_upload_verify','0',0),
('reparse_lock','0',1),
('require_activation','0',0),
('script_path','/',0),
('search_anonymous_interval','0',0),
('search_block_size','250',0),
('search_gc','7200',0),
('search_indexing_state','',1),
('search_interval','0',0),
('search_last_gc','0',1),
('search_store_results','1800',0),
('search_type','\\phpbb\\search\\fulltext_native',0),
('secure_allow_deny','1',0),
('secure_allow_empty_referer','1',0),
('secure_downloads','0',0),
('server_name','localhost',0),
('server_port','8181',0),
('server_protocol','http://',0),
('session_gc','3600',0),
('session_last_gc','0',1),
('session_length','3600',0),
('site_desc','a vibe coded version of phpBB script',0),
('site_home_text','',0),
('site_home_url','',0),
('sitename','phpbb vibed',0),
('smilies_path','images/smilies',0),
('smilies_per_page','50',0),
('smtp_allow_self_signed','0',0),
('smtp_auth_method','PLAIN',0),
('smtp_delivery','0',0),
('smtp_host','',0),
('smtp_password','',1),
('smtp_port','',0),
('smtp_username','',1),
('smtp_verify_peer','1',0),
('smtp_verify_peer_name','1',0),
('teampage_forums','1',0),
('teampage_memberships','1',0),
('text_reparser.pm_text_cron_interval','10',0),
('text_reparser.pm_text_last_cron','0',0),
('text_reparser.poll_option_cron_interval','10',0),
('text_reparser.poll_option_last_cron','0',0),
('text_reparser.poll_title_cron_interval','10',0),
('text_reparser.poll_title_last_cron','0',0),
('text_reparser.post_text_cron_interval','10',0),
('text_reparser.post_text_last_cron','0',0),
('text_reparser.user_signature_cron_interval','10',0),
('text_reparser.user_signature_last_cron','0',0),
('topics_per_page','25',0),
('tpl_allow_php','0',0),
('update_hashes_last_cron','0',0),
('update_hashes_lock','0',0),
('upload_dir_size','0',1),
('upload_icons_path','images/upload_icons',0),
('upload_path','files',0),
('use_system_cron','0',0),
('version','3.3.15',0),
('warnings_expire_days','90',0),
('warnings_gc','14400',0),
('warnings_last_gc','0',1);
/*!40000 ALTER TABLE `phpbb_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_config_text`
--

DROP TABLE IF EXISTS `phpbb_config_text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_config_text` (
  `config_name` varchar(255) NOT NULL DEFAULT '',
  `config_value` mediumtext NOT NULL,
  PRIMARY KEY (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_config_text`
--

LOCK TABLES `phpbb_config_text` WRITE;
/*!40000 ALTER TABLE `phpbb_config_text` DISABLE KEYS */;
INSERT INTO `phpbb_config_text` VALUES
('contact_admin_info',''),
('contact_admin_info_bitfield',''),
('contact_admin_info_flags','7'),
('contact_admin_info_uid','');
/*!40000 ALTER TABLE `phpbb_config_text` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_confirm`
--

DROP TABLE IF EXISTS `phpbb_confirm`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_confirm` (
  `confirm_id` char(32) NOT NULL DEFAULT '',
  `session_id` char(32) NOT NULL DEFAULT '',
  `confirm_type` tinyint(3) NOT NULL DEFAULT 0,
  `code` varchar(8) NOT NULL DEFAULT '',
  `seed` int(10) unsigned NOT NULL DEFAULT 0,
  `attempts` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`,`confirm_id`),
  KEY `confirm_type` (`confirm_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_confirm`
--

LOCK TABLES `phpbb_confirm` WRITE;
/*!40000 ALTER TABLE `phpbb_confirm` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_confirm` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_disallow`
--

DROP TABLE IF EXISTS `phpbb_disallow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_disallow` (
  `disallow_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `disallow_username` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`disallow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_disallow`
--

LOCK TABLES `phpbb_disallow` WRITE;
/*!40000 ALTER TABLE `phpbb_disallow` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_disallow` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_drafts`
--

DROP TABLE IF EXISTS `phpbb_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_drafts` (
  `draft_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `save_time` int(11) unsigned NOT NULL DEFAULT 0,
  `draft_subject` varchar(255) NOT NULL DEFAULT '',
  `draft_message` mediumtext NOT NULL,
  PRIMARY KEY (`draft_id`),
  KEY `save_time` (`save_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_drafts`
--

LOCK TABLES `phpbb_drafts` WRITE;
/*!40000 ALTER TABLE `phpbb_drafts` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_drafts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_ext`
--

DROP TABLE IF EXISTS `phpbb_ext`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_ext` (
  `ext_name` varchar(255) NOT NULL DEFAULT '',
  `ext_active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `ext_state` text NOT NULL,
  UNIQUE KEY `ext_name` (`ext_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_ext`
--

LOCK TABLES `phpbb_ext` WRITE;
/*!40000 ALTER TABLE `phpbb_ext` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_ext` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_extension_groups`
--

DROP TABLE IF EXISTS `phpbb_extension_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_extension_groups` (
  `group_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `cat_id` tinyint(2) NOT NULL DEFAULT 0,
  `allow_group` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `download_mode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `upload_icon` varchar(255) NOT NULL DEFAULT '',
  `max_filesize` int(20) unsigned NOT NULL DEFAULT 0,
  `allowed_forums` text NOT NULL,
  `allow_in_pm` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_extension_groups`
--

LOCK TABLES `phpbb_extension_groups` WRITE;
/*!40000 ALTER TABLE `phpbb_extension_groups` DISABLE KEYS */;
INSERT INTO `phpbb_extension_groups` VALUES
(1,'IMAGES',1,1,1,'',0,'',0),
(2,'ARCHIVES',0,1,1,'',0,'',0),
(3,'PLAIN_TEXT',0,0,1,'',0,'',0),
(4,'DOCUMENTS',0,0,1,'',0,'',0),
(5,'DOWNLOADABLE_FILES',0,0,1,'',0,'',0),
(6,'IMAGES',1,1,1,'',0,'',0),
(7,'ARCHIVES',0,1,1,'',0,'',0),
(8,'PLAIN_TEXT',0,0,1,'',0,'',0),
(9,'DOCUMENTS',0,0,1,'',0,'',0),
(10,'DOWNLOADABLE_FILES',0,0,1,'',0,'',0);
/*!40000 ALTER TABLE `phpbb_extension_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_extensions`
--

DROP TABLE IF EXISTS `phpbb_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_extensions` (
  `extension_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `extension` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`extension_id`)
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_extensions`
--

LOCK TABLES `phpbb_extensions` WRITE;
/*!40000 ALTER TABLE `phpbb_extensions` DISABLE KEYS */;
INSERT INTO `phpbb_extensions` VALUES
(1,1,'gif'),
(2,1,'png'),
(3,1,'jpeg'),
(4,1,'jpg'),
(5,1,'tif'),
(6,1,'tiff'),
(7,1,'tga'),
(8,2,'gtar'),
(9,2,'gz'),
(10,2,'tar'),
(11,2,'zip'),
(12,2,'rar'),
(13,2,'ace'),
(14,2,'torrent'),
(15,2,'tgz'),
(16,2,'bz2'),
(17,2,'7z'),
(18,3,'txt'),
(19,3,'c'),
(20,3,'h'),
(21,3,'cpp'),
(22,3,'hpp'),
(23,3,'diz'),
(24,3,'csv'),
(25,3,'ini'),
(26,3,'log'),
(27,3,'js'),
(28,3,'xml'),
(29,4,'xls'),
(30,4,'xlsx'),
(31,4,'xlsm'),
(32,4,'xlsb'),
(33,4,'doc'),
(34,4,'docx'),
(35,4,'docm'),
(36,4,'dot'),
(37,4,'dotx'),
(38,4,'dotm'),
(39,4,'pdf'),
(40,4,'ai'),
(41,4,'ps'),
(42,4,'ppt'),
(43,4,'pptx'),
(44,4,'pptm'),
(45,4,'odg'),
(46,4,'odp'),
(47,4,'ods'),
(48,4,'odt'),
(49,4,'rtf'),
(50,5,'mp3'),
(51,5,'mpeg'),
(52,5,'mpg'),
(53,5,'ogg'),
(54,5,'ogm'),
(55,1,'gif'),
(56,1,'png'),
(57,1,'jpeg'),
(58,1,'jpg'),
(59,1,'tif'),
(60,1,'tiff'),
(61,1,'tga'),
(62,2,'gtar'),
(63,2,'gz'),
(64,2,'tar'),
(65,2,'zip'),
(66,2,'rar'),
(67,2,'ace'),
(68,2,'torrent'),
(69,2,'tgz'),
(70,2,'bz2'),
(71,2,'7z'),
(72,3,'txt'),
(73,3,'c'),
(74,3,'h'),
(75,3,'cpp'),
(76,3,'hpp'),
(77,3,'diz'),
(78,3,'csv'),
(79,3,'ini'),
(80,3,'log'),
(81,3,'js'),
(82,3,'xml'),
(83,4,'xls'),
(84,4,'xlsx'),
(85,4,'xlsm'),
(86,4,'xlsb'),
(87,4,'doc'),
(88,4,'docx'),
(89,4,'docm'),
(90,4,'dot'),
(91,4,'dotx'),
(92,4,'dotm'),
(93,4,'pdf'),
(94,4,'ai'),
(95,4,'ps'),
(96,4,'ppt'),
(97,4,'pptx'),
(98,4,'pptm'),
(99,4,'odg'),
(100,4,'odp'),
(101,4,'ods'),
(102,4,'odt'),
(103,4,'rtf'),
(104,5,'mp3'),
(105,5,'mpeg'),
(106,5,'mpg'),
(107,5,'ogg'),
(108,5,'ogm');
/*!40000 ALTER TABLE `phpbb_extensions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_forums`
--

DROP TABLE IF EXISTS `phpbb_forums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_forums` (
  `forum_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `left_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `right_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_parents` mediumtext NOT NULL,
  `forum_name` varchar(255) NOT NULL DEFAULT '',
  `forum_desc` text NOT NULL,
  `forum_desc_bitfield` varchar(255) NOT NULL DEFAULT '',
  `forum_desc_options` int(11) unsigned NOT NULL DEFAULT 7,
  `forum_desc_uid` varchar(8) NOT NULL DEFAULT '',
  `forum_link` varchar(255) NOT NULL DEFAULT '',
  `forum_password` varchar(255) NOT NULL DEFAULT '',
  `forum_style` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_image` varchar(255) NOT NULL DEFAULT '',
  `forum_rules` text NOT NULL,
  `forum_rules_link` varchar(255) NOT NULL DEFAULT '',
  `forum_rules_bitfield` varchar(255) NOT NULL DEFAULT '',
  `forum_rules_options` int(11) unsigned NOT NULL DEFAULT 7,
  `forum_rules_uid` varchar(8) NOT NULL DEFAULT '',
  `forum_topics_per_page` smallint(4) unsigned NOT NULL DEFAULT 0,
  `forum_type` tinyint(4) NOT NULL DEFAULT 0,
  `forum_status` tinyint(4) NOT NULL DEFAULT 0,
  `forum_last_post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_last_poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_last_post_subject` varchar(255) NOT NULL DEFAULT '',
  `forum_last_post_time` int(11) unsigned NOT NULL DEFAULT 0,
  `forum_last_poster_name` varchar(255) NOT NULL DEFAULT '',
  `forum_last_poster_colour` varchar(6) NOT NULL DEFAULT '',
  `forum_flags` tinyint(4) NOT NULL DEFAULT 32,
  `display_on_index` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_indexing` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_icons` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_prune` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `prune_next` int(11) unsigned NOT NULL DEFAULT 0,
  `prune_days` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `prune_viewed` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `prune_freq` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `display_subforum_list` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `display_subforum_limit` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `forum_options` int(20) unsigned NOT NULL DEFAULT 0,
  `enable_shadow_prune` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `prune_shadow_days` mediumint(8) unsigned NOT NULL DEFAULT 7,
  `prune_shadow_freq` mediumint(8) unsigned NOT NULL DEFAULT 1,
  `prune_shadow_next` int(11) NOT NULL DEFAULT 0,
  `forum_posts_approved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_posts_unapproved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_posts_softdeleted` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_approved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_unapproved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_softdeleted` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`forum_id`),
  KEY `left_right_id` (`left_id`,`right_id`),
  KEY `forum_lastpost_id` (`forum_last_post_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_forums`
--

LOCK TABLES `phpbb_forums` WRITE;
/*!40000 ALTER TABLE `phpbb_forums` DISABLE KEYS */;
INSERT INTO `phpbb_forums` VALUES
(1,0,1,4,'','Your first category','','',7,'','','',0,'','','','',7,'',0,0,0,1,2,'',1776276588,'admin','AA0000',32,1,1,1,0,0,0,0,0,1,0,0,0,7,1,0,0,0,0,0,0,0),
(2,1,2,3,'','Your first forum','Description of your first forum.','',7,'','','',0,'','','','',7,'',0,1,0,1,2,'Welcome to phpBB3',1776276588,'admin','AA0000',48,1,1,1,0,0,7,7,1,1,0,0,0,7,1,0,1,0,0,1,0,0),
(3,0,1,4,'','Your first category','','',7,'','','',0,'','','','',7,'',0,0,0,1,2,'',1776276588,'admin','AA0000',32,1,1,1,0,0,0,0,0,1,0,0,0,7,1,0,0,0,0,0,0,0),
(4,1,2,3,'','Your first forum','Description of your first forum.','',7,'','','',0,'','','','',7,'',0,1,0,1,2,'Welcome to phpBB3',1776276588,'admin','AA0000',48,1,1,1,0,0,7,7,1,1,0,0,0,7,1,0,1,0,0,1,0,0);
/*!40000 ALTER TABLE `phpbb_forums` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_forums_access`
--

DROP TABLE IF EXISTS `phpbb_forums_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_forums_access` (
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `session_id` char(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`forum_id`,`user_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_forums_access`
--

LOCK TABLES `phpbb_forums_access` WRITE;
/*!40000 ALTER TABLE `phpbb_forums_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_forums_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_forums_track`
--

DROP TABLE IF EXISTS `phpbb_forums_track`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_forums_track` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `mark_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`forum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_forums_track`
--

LOCK TABLES `phpbb_forums_track` WRITE;
/*!40000 ALTER TABLE `phpbb_forums_track` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_forums_track` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_forums_watch`
--

DROP TABLE IF EXISTS `phpbb_forums_watch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_forums_watch` (
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `notify_status` tinyint(1) unsigned NOT NULL DEFAULT 0,
  KEY `forum_id` (`forum_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_stat` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_forums_watch`
--

LOCK TABLES `phpbb_forums_watch` WRITE;
/*!40000 ALTER TABLE `phpbb_forums_watch` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_forums_watch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_groups`
--

DROP TABLE IF EXISTS `phpbb_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_groups` (
  `group_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_type` tinyint(4) NOT NULL DEFAULT 1,
  `group_founder_manage` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_skip_auth` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `group_desc` text NOT NULL,
  `group_desc_bitfield` varchar(255) NOT NULL DEFAULT '',
  `group_desc_options` int(11) unsigned NOT NULL DEFAULT 7,
  `group_desc_uid` varchar(8) NOT NULL DEFAULT '',
  `group_display` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_avatar` varchar(255) NOT NULL DEFAULT '',
  `group_avatar_type` varchar(255) NOT NULL DEFAULT '',
  `group_avatar_width` smallint(4) unsigned NOT NULL DEFAULT 0,
  `group_avatar_height` smallint(4) unsigned NOT NULL DEFAULT 0,
  `group_rank` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_colour` varchar(6) NOT NULL DEFAULT '',
  `group_sig_chars` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_receive_pm` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_message_limit` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_legend` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_max_recipients` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`),
  KEY `group_legend_name` (`group_legend`,`group_name`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_groups`
--

LOCK TABLES `phpbb_groups` WRITE;
/*!40000 ALTER TABLE `phpbb_groups` DISABLE KEYS */;
INSERT INTO `phpbb_groups` VALUES
(1,3,0,0,'GUESTS','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(2,3,0,0,'REGISTERED','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(3,3,0,0,'REGISTERED_COPPA','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(4,3,0,0,'GLOBAL_MODERATORS','','',7,'',0,'','',0,0,0,'00AA00',0,0,0,2,0),
(5,3,1,0,'ADMINISTRATORS','','',7,'',0,'','',0,0,0,'AA0000',0,0,0,1,0),
(6,3,0,0,'BOTS','','',7,'',0,'','',0,0,0,'9E8DA7',0,0,0,0,5),
(7,3,0,0,'NEWLY_REGISTERED','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(8,3,0,0,'GUESTS','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(9,3,0,0,'REGISTERED','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(10,3,0,0,'REGISTERED_COPPA','','',7,'',0,'','',0,0,0,'',0,0,0,0,5),
(11,3,0,0,'GLOBAL_MODERATORS','','',7,'',0,'','',0,0,0,'00AA00',0,0,0,2,0),
(12,3,1,0,'ADMINISTRATORS','','',7,'',0,'','',0,0,0,'AA0000',0,0,0,1,0),
(13,3,0,0,'BOTS','','',7,'',0,'','',0,0,0,'9E8DA7',0,0,0,0,5),
(14,3,0,0,'NEWLY_REGISTERED','','',7,'',0,'','',0,0,0,'',0,0,0,0,5);
/*!40000 ALTER TABLE `phpbb_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_icons`
--

DROP TABLE IF EXISTS `phpbb_icons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_icons` (
  `icons_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `icons_url` varchar(255) NOT NULL DEFAULT '',
  `icons_width` tinyint(4) NOT NULL DEFAULT 0,
  `icons_height` tinyint(4) NOT NULL DEFAULT 0,
  `icons_alt` varchar(255) NOT NULL DEFAULT '',
  `icons_order` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `display_on_posting` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`icons_id`),
  KEY `display_on_posting` (`display_on_posting`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_icons`
--

LOCK TABLES `phpbb_icons` WRITE;
/*!40000 ALTER TABLE `phpbb_icons` DISABLE KEYS */;
INSERT INTO `phpbb_icons` VALUES
(1,'misc/fire.gif',16,16,'',1,1),
(2,'smile/redface.gif',16,16,'',9,1),
(3,'smile/mrgreen.gif',16,16,'',10,1),
(4,'misc/heart.gif',16,16,'',4,1),
(5,'misc/star.gif',16,16,'',2,1),
(6,'misc/radioactive.gif',16,16,'',3,1),
(7,'misc/thinking.gif',16,16,'',5,1),
(8,'smile/info.gif',16,16,'',8,1),
(9,'smile/question.gif',16,16,'',6,1),
(10,'smile/alert.gif',16,16,'',7,1),
(11,'misc/fire.gif',16,16,'',1,1),
(12,'smile/redface.gif',16,16,'',9,1),
(13,'smile/mrgreen.gif',16,16,'',10,1),
(14,'misc/heart.gif',16,16,'',4,1),
(15,'misc/star.gif',16,16,'',2,1),
(16,'misc/radioactive.gif',16,16,'',3,1),
(17,'misc/thinking.gif',16,16,'',5,1),
(18,'smile/info.gif',16,16,'',8,1),
(19,'smile/question.gif',16,16,'',6,1),
(20,'smile/alert.gif',16,16,'',7,1);
/*!40000 ALTER TABLE `phpbb_icons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_lang`
--

DROP TABLE IF EXISTS `phpbb_lang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_lang` (
  `lang_id` tinyint(4) NOT NULL AUTO_INCREMENT,
  `lang_iso` varchar(30) NOT NULL DEFAULT '',
  `lang_dir` varchar(30) NOT NULL DEFAULT '',
  `lang_english_name` varchar(100) NOT NULL DEFAULT '',
  `lang_local_name` varchar(255) NOT NULL DEFAULT '',
  `lang_author` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`lang_id`),
  KEY `lang_iso` (`lang_iso`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_lang`
--

LOCK TABLES `phpbb_lang` WRITE;
/*!40000 ALTER TABLE `phpbb_lang` DISABLE KEYS */;
INSERT INTO `phpbb_lang` VALUES
(1,'en','en','British English','British English','phpBB Limited'),
(2,'en','en','British English','British English','phpBB Limited');
/*!40000 ALTER TABLE `phpbb_lang` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_log`
--

DROP TABLE IF EXISTS `phpbb_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_log` (
  `log_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `log_type` tinyint(4) NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `reportee_id` int(10) unsigned NOT NULL DEFAULT 0,
  `log_ip` varchar(40) NOT NULL DEFAULT '',
  `log_time` int(11) unsigned NOT NULL DEFAULT 0,
  `log_operation` text NOT NULL,
  `log_data` mediumtext NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `log_type` (`log_type`),
  KEY `forum_id` (`forum_id`),
  KEY `topic_id` (`topic_id`),
  KEY `reportee_id` (`reportee_id`),
  KEY `user_id` (`user_id`),
  KEY `log_time` (`log_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_log`
--

LOCK TABLES `phpbb_log` WRITE;
/*!40000 ALTER TABLE `phpbb_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_login_attempts`
--

DROP TABLE IF EXISTS `phpbb_login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_login_attempts` (
  `attempt_ip` varchar(40) NOT NULL DEFAULT '',
  `attempt_browser` varchar(150) NOT NULL DEFAULT '',
  `attempt_forwarded_for` varchar(255) NOT NULL DEFAULT '',
  `attempt_time` int(11) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `username` varchar(255) NOT NULL DEFAULT '0',
  `username_clean` varchar(255) NOT NULL DEFAULT '0',
  KEY `att_ip` (`attempt_ip`,`attempt_time`),
  KEY `att_for` (`attempt_forwarded_for`,`attempt_time`),
  KEY `att_time` (`attempt_time`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_login_attempts`
--

LOCK TABLES `phpbb_login_attempts` WRITE;
/*!40000 ALTER TABLE `phpbb_login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_migrations`
--

DROP TABLE IF EXISTS `phpbb_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_migrations` (
  `migration_name` varchar(255) NOT NULL DEFAULT '',
  `migration_depends_on` text NOT NULL,
  `migration_schema_done` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `migration_data_done` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `migration_data_state` text NOT NULL,
  `migration_start_time` int(11) unsigned NOT NULL DEFAULT 0,
  `migration_end_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_migrations`
--

LOCK TABLES `phpbb_migrations` WRITE;
/*!40000 ALTER TABLE `phpbb_migrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_moderator_cache`
--

DROP TABLE IF EXISTS `phpbb_moderator_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_moderator_cache` (
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `username` varchar(255) NOT NULL DEFAULT '',
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `display_on_index` tinyint(1) unsigned NOT NULL DEFAULT 1,
  KEY `disp_idx` (`display_on_index`),
  KEY `forum_id` (`forum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_moderator_cache`
--

LOCK TABLES `phpbb_moderator_cache` WRITE;
/*!40000 ALTER TABLE `phpbb_moderator_cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_moderator_cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_modules`
--

DROP TABLE IF EXISTS `phpbb_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_modules` (
  `module_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `module_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `module_display` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `module_basename` varchar(255) NOT NULL DEFAULT '',
  `module_class` varchar(10) NOT NULL DEFAULT '',
  `parent_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `left_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `right_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `module_langname` varchar(255) NOT NULL DEFAULT '',
  `module_mode` varchar(255) NOT NULL DEFAULT '',
  `module_auth` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`module_id`),
  KEY `left_right_id` (`left_id`,`right_id`),
  KEY `module_enabled` (`module_enabled`),
  KEY `class_left_id` (`module_class`,`left_id`)
) ENGINE=InnoDB AUTO_INCREMENT=338 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_modules`
--

LOCK TABLES `phpbb_modules` WRITE;
/*!40000 ALTER TABLE `phpbb_modules` DISABLE KEYS */;
INSERT INTO `phpbb_modules` VALUES
(1,1,1,'','acp',0,1,66,'ACP_CAT_GENERAL','',''),
(2,1,1,'','acp',1,4,17,'ACP_QUICK_ACCESS','',''),
(3,1,1,'','acp',1,18,43,'ACP_BOARD_CONFIGURATION','',''),
(4,1,1,'','acp',1,44,51,'ACP_CLIENT_COMMUNICATION','',''),
(5,1,1,'','acp',1,52,65,'ACP_SERVER_CONFIGURATION','',''),
(6,1,1,'','acp',0,67,86,'ACP_CAT_FORUMS','',''),
(7,1,1,'','acp',6,68,73,'ACP_MANAGE_FORUMS','',''),
(8,1,1,'','acp',6,74,85,'ACP_FORUM_BASED_PERMISSIONS','',''),
(9,1,1,'','acp',0,87,114,'ACP_CAT_POSTING','',''),
(10,1,1,'','acp',9,88,101,'ACP_MESSAGES','',''),
(11,1,1,'','acp',9,102,113,'ACP_ATTACHMENTS','',''),
(12,1,1,'','acp',0,115,172,'ACP_CAT_USERGROUP','',''),
(13,1,1,'','acp',12,116,151,'ACP_CAT_USERS','',''),
(14,1,1,'','acp',12,152,161,'ACP_GROUPS','',''),
(15,1,1,'','acp',12,162,171,'ACP_USER_SECURITY','',''),
(16,1,1,'','acp',0,173,222,'ACP_CAT_PERMISSIONS','',''),
(17,1,1,'','acp',16,176,185,'ACP_GLOBAL_PERMISSIONS','',''),
(18,1,1,'','acp',16,186,197,'ACP_FORUM_BASED_PERMISSIONS','',''),
(19,1,1,'','acp',16,198,207,'ACP_PERMISSION_ROLES','',''),
(20,1,1,'','acp',16,208,221,'ACP_PERMISSION_MASKS','',''),
(21,1,1,'','acp',0,223,238,'ACP_CAT_CUSTOMISE','',''),
(22,1,1,'','acp',21,228,233,'ACP_STYLE_MANAGEMENT','',''),
(23,1,1,'','acp',21,224,227,'ACP_EXTENSION_MANAGEMENT','',''),
(24,1,1,'','acp',21,234,237,'ACP_LANGUAGE','',''),
(25,1,1,'','acp',0,239,258,'ACP_CAT_MAINTENANCE','',''),
(26,1,1,'','acp',25,240,249,'ACP_FORUM_LOGS','',''),
(27,1,1,'','acp',25,250,257,'ACP_CAT_DATABASE','',''),
(28,1,1,'','acp',0,259,282,'ACP_CAT_SYSTEM','',''),
(29,1,1,'','acp',28,260,263,'ACP_AUTOMATION','',''),
(30,1,1,'','acp',28,264,273,'ACP_GENERAL_TASKS','',''),
(31,1,1,'','acp',28,274,281,'ACP_MODULE_MANAGEMENT','',''),
(32,1,1,'','acp',0,283,284,'ACP_CAT_DOT_MODS','',''),
(33,1,1,'acp_attachments','acp',3,19,20,'ACP_ATTACHMENT_SETTINGS','attach','acl_a_attach'),
(34,1,1,'acp_attachments','acp',11,103,104,'ACP_ATTACHMENT_SETTINGS','attach','acl_a_attach'),
(35,1,1,'acp_attachments','acp',11,105,106,'ACP_MANAGE_EXTENSIONS','extensions','acl_a_attach'),
(36,1,1,'acp_attachments','acp',11,107,108,'ACP_EXTENSION_GROUPS','ext_groups','acl_a_attach'),
(37,1,1,'acp_attachments','acp',11,109,110,'ACP_ORPHAN_ATTACHMENTS','orphan','acl_a_attach'),
(38,1,1,'acp_attachments','acp',11,111,112,'ACP_MANAGE_ATTACHMENTS','manage','acl_a_attach'),
(39,1,1,'acp_ban','acp',15,163,164,'ACP_BAN_EMAILS','email','acl_a_ban'),
(40,1,1,'acp_ban','acp',15,165,166,'ACP_BAN_IPS','ip','acl_a_ban'),
(41,1,1,'acp_ban','acp',15,167,168,'ACP_BAN_USERNAMES','user','acl_a_ban'),
(42,1,1,'acp_bbcodes','acp',10,89,90,'ACP_BBCODES','bbcodes','acl_a_bbcode'),
(43,1,1,'acp_board','acp',3,21,22,'ACP_BOARD_SETTINGS','settings','acl_a_board'),
(44,1,1,'acp_board','acp',3,23,24,'ACP_BOARD_FEATURES','features','acl_a_board'),
(45,1,1,'acp_board','acp',3,25,26,'ACP_AVATAR_SETTINGS','avatar','acl_a_board'),
(46,1,1,'acp_board','acp',3,27,28,'ACP_MESSAGE_SETTINGS','message','acl_a_board'),
(47,1,1,'acp_board','acp',10,91,92,'ACP_MESSAGE_SETTINGS','message','acl_a_board'),
(48,1,1,'acp_board','acp',3,29,30,'ACP_POST_SETTINGS','post','acl_a_board'),
(49,1,1,'acp_board','acp',10,93,94,'ACP_POST_SETTINGS','post','acl_a_board'),
(50,1,1,'acp_board','acp',3,31,32,'ACP_SIGNATURE_SETTINGS','signature','acl_a_board'),
(51,1,1,'acp_board','acp',3,33,34,'ACP_FEED_SETTINGS','feed','acl_a_board'),
(52,1,1,'acp_board','acp',3,35,36,'ACP_REGISTER_SETTINGS','registration','acl_a_board'),
(53,1,1,'acp_board','acp',4,45,46,'ACP_AUTH_SETTINGS','auth','acl_a_server'),
(54,1,1,'acp_board','acp',4,47,48,'ACP_EMAIL_SETTINGS','email','acl_a_server'),
(55,1,1,'acp_board','acp',5,53,54,'ACP_COOKIE_SETTINGS','cookie','acl_a_server'),
(56,1,1,'acp_board','acp',5,55,56,'ACP_SERVER_SETTINGS','server','acl_a_server'),
(57,1,1,'acp_board','acp',5,57,58,'ACP_SECURITY_SETTINGS','security','acl_a_server'),
(58,1,1,'acp_board','acp',5,59,60,'ACP_LOAD_SETTINGS','load','acl_a_server'),
(59,1,1,'acp_bots','acp',30,265,266,'ACP_BOTS','bots','acl_a_bots'),
(60,1,1,'acp_captcha','acp',3,37,38,'ACP_VC_SETTINGS','visual','acl_a_board'),
(61,1,0,'acp_captcha','acp',3,39,40,'ACP_VC_CAPTCHA_DISPLAY','img','acl_a_board'),
(62,1,1,'acp_contact','acp',3,41,42,'ACP_CONTACT_SETTINGS','contact','acl_a_board'),
(63,1,1,'acp_database','acp',27,251,252,'ACP_BACKUP','backup','acl_a_backup'),
(64,1,1,'acp_database','acp',27,253,254,'ACP_RESTORE','restore','acl_a_backup'),
(65,1,1,'acp_disallow','acp',15,169,170,'ACP_DISALLOW_USERNAMES','usernames','acl_a_names'),
(66,1,1,'acp_email','acp',30,267,268,'ACP_MASS_EMAIL','email','acl_a_email && cfg_email_enable'),
(67,1,1,'acp_extensions','acp',23,225,226,'ACP_EXTENSIONS','main','acl_a_extensions'),
(68,1,1,'acp_forums','acp',7,69,70,'ACP_MANAGE_FORUMS','manage','acl_a_forum'),
(69,1,1,'acp_groups','acp',14,153,154,'ACP_GROUPS_MANAGE','manage','acl_a_group'),
(70,1,1,'acp_groups','acp',14,155,156,'ACP_GROUPS_POSITION','position','acl_a_group'),
(71,1,1,'acp_help_phpbb','acp',5,61,62,'ACP_HELP_PHPBB','help_phpbb','acl_a_server'),
(72,1,1,'acp_icons','acp',10,95,96,'ACP_ICONS','icons','acl_a_icons'),
(73,1,1,'acp_icons','acp',10,97,98,'ACP_SMILIES','smilies','acl_a_icons'),
(74,1,1,'acp_inactive','acp',13,117,118,'ACP_INACTIVE_USERS','list','acl_a_user'),
(75,1,1,'acp_jabber','acp',4,49,50,'ACP_JABBER_SETTINGS','settings','acl_a_jabber'),
(76,1,1,'acp_language','acp',24,235,236,'ACP_LANGUAGE_PACKS','lang_packs','acl_a_language'),
(77,1,1,'acp_logs','acp',26,241,242,'ACP_ADMIN_LOGS','admin','acl_a_viewlogs'),
(78,1,1,'acp_logs','acp',26,243,244,'ACP_MOD_LOGS','mod','acl_a_viewlogs'),
(79,1,1,'acp_logs','acp',26,245,246,'ACP_USERS_LOGS','users','acl_a_viewlogs'),
(80,1,1,'acp_logs','acp',26,247,248,'ACP_CRITICAL_LOGS','critical','acl_a_viewlogs'),
(81,1,1,'acp_main','acp',1,2,3,'ACP_INDEX','main',''),
(82,1,1,'acp_modules','acp',31,275,276,'ACP','acp','acl_a_modules'),
(83,1,1,'acp_modules','acp',31,277,278,'UCP','ucp','acl_a_modules'),
(84,1,1,'acp_modules','acp',31,279,280,'MCP','mcp','acl_a_modules'),
(85,1,1,'acp_permission_roles','acp',19,199,200,'ACP_ADMIN_ROLES','admin_roles','acl_a_roles && acl_a_aauth'),
(86,1,1,'acp_permission_roles','acp',19,201,202,'ACP_USER_ROLES','user_roles','acl_a_roles && acl_a_uauth'),
(87,1,1,'acp_permission_roles','acp',19,203,204,'ACP_MOD_ROLES','mod_roles','acl_a_roles && acl_a_mauth'),
(88,1,1,'acp_permission_roles','acp',19,205,206,'ACP_FORUM_ROLES','forum_roles','acl_a_roles && acl_a_fauth'),
(89,1,1,'acp_permissions','acp',16,174,175,'ACP_PERMISSIONS','intro','acl_a_authusers || acl_a_authgroups || acl_a_viewauth'),
(90,1,0,'acp_permissions','acp',20,209,210,'ACP_PERMISSION_TRACE','trace','acl_a_viewauth'),
(91,1,1,'acp_permissions','acp',18,187,188,'ACP_FORUM_PERMISSIONS','setting_forum_local','acl_a_fauth && (acl_a_authusers || acl_a_authgroups)'),
(92,1,1,'acp_permissions','acp',18,189,190,'ACP_FORUM_PERMISSIONS_COPY','setting_forum_copy','acl_a_fauth && acl_a_authusers && acl_a_authgroups && acl_a_mauth'),
(93,1,1,'acp_permissions','acp',18,191,192,'ACP_FORUM_MODERATORS','setting_mod_local','acl_a_mauth && (acl_a_authusers || acl_a_authgroups)'),
(94,1,1,'acp_permissions','acp',17,177,178,'ACP_USERS_PERMISSIONS','setting_user_global','acl_a_authusers && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(95,1,1,'acp_permissions','acp',13,121,122,'ACP_USERS_PERMISSIONS','setting_user_global','acl_a_authusers && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(96,1,1,'acp_permissions','acp',18,193,194,'ACP_USERS_FORUM_PERMISSIONS','setting_user_local','acl_a_authusers && (acl_a_mauth || acl_a_fauth)'),
(97,1,1,'acp_permissions','acp',13,123,124,'ACP_USERS_FORUM_PERMISSIONS','setting_user_local','acl_a_authusers && (acl_a_mauth || acl_a_fauth)'),
(98,1,1,'acp_permissions','acp',17,179,180,'ACP_GROUPS_PERMISSIONS','setting_group_global','acl_a_authgroups && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(99,1,1,'acp_permissions','acp',14,157,158,'ACP_GROUPS_PERMISSIONS','setting_group_global','acl_a_authgroups && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(100,1,1,'acp_permissions','acp',18,195,196,'ACP_GROUPS_FORUM_PERMISSIONS','setting_group_local','acl_a_authgroups && (acl_a_mauth || acl_a_fauth)'),
(101,1,1,'acp_permissions','acp',14,159,160,'ACP_GROUPS_FORUM_PERMISSIONS','setting_group_local','acl_a_authgroups && (acl_a_mauth || acl_a_fauth)'),
(102,1,1,'acp_permissions','acp',17,181,182,'ACP_ADMINISTRATORS','setting_admin_global','acl_a_aauth && (acl_a_authusers || acl_a_authgroups)'),
(103,1,1,'acp_permissions','acp',17,183,184,'ACP_GLOBAL_MODERATORS','setting_mod_global','acl_a_mauth && (acl_a_authusers || acl_a_authgroups)'),
(104,1,1,'acp_permissions','acp',20,211,212,'ACP_VIEW_ADMIN_PERMISSIONS','view_admin_global','acl_a_viewauth'),
(105,1,1,'acp_permissions','acp',20,213,214,'ACP_VIEW_USER_PERMISSIONS','view_user_global','acl_a_viewauth'),
(106,1,1,'acp_permissions','acp',20,215,216,'ACP_VIEW_GLOBAL_MOD_PERMISSIONS','view_mod_global','acl_a_viewauth'),
(107,1,1,'acp_permissions','acp',20,217,218,'ACP_VIEW_FORUM_MOD_PERMISSIONS','view_mod_local','acl_a_viewauth'),
(108,1,1,'acp_permissions','acp',20,219,220,'ACP_VIEW_FORUM_PERMISSIONS','view_forum_local','acl_a_viewauth'),
(109,1,1,'acp_php_info','acp',30,269,270,'ACP_PHP_INFO','info','acl_a_phpinfo'),
(110,1,1,'acp_profile','acp',13,125,126,'ACP_CUSTOM_PROFILE_FIELDS','profile','acl_a_profile'),
(111,1,1,'acp_prune','acp',7,71,72,'ACP_PRUNE_FORUMS','forums','acl_a_prune'),
(112,1,1,'acp_prune','acp',13,127,128,'ACP_PRUNE_USERS','users','acl_a_userdel'),
(113,1,1,'acp_ranks','acp',13,129,130,'ACP_MANAGE_RANKS','ranks','acl_a_ranks'),
(114,1,1,'acp_reasons','acp',30,271,272,'ACP_MANAGE_REASONS','main','acl_a_reasons'),
(115,1,1,'acp_search','acp',5,63,64,'ACP_SEARCH_SETTINGS','settings','acl_a_search'),
(116,1,1,'acp_search','acp',27,255,256,'ACP_SEARCH_INDEX','index','acl_a_search'),
(117,1,1,'acp_styles','acp',22,229,230,'ACP_STYLES','style','acl_a_styles'),
(118,1,1,'acp_styles','acp',22,231,232,'ACP_STYLES_INSTALL','install','acl_a_styles'),
(119,1,1,'acp_update','acp',29,261,262,'ACP_VERSION_CHECK','version_check','acl_a_board'),
(120,1,1,'acp_users','acp',13,119,120,'ACP_MANAGE_USERS','overview','acl_a_user'),
(121,1,0,'acp_users','acp',13,131,132,'ACP_USER_FEEDBACK','feedback','acl_a_user'),
(122,1,0,'acp_users','acp',13,133,134,'ACP_USER_WARNINGS','warnings','acl_a_user'),
(123,1,0,'acp_users','acp',13,135,136,'ACP_USER_PROFILE','profile','acl_a_user'),
(124,1,0,'acp_users','acp',13,137,138,'ACP_USER_PREFS','prefs','acl_a_user'),
(125,1,0,'acp_users','acp',13,139,140,'ACP_USER_AVATAR','avatar','acl_a_user'),
(126,1,0,'acp_users','acp',13,141,142,'ACP_USER_RANK','rank','acl_a_user'),
(127,1,0,'acp_users','acp',13,143,144,'ACP_USER_SIG','sig','acl_a_user'),
(128,1,0,'acp_users','acp',13,145,146,'ACP_USER_GROUPS','groups','acl_a_user && acl_a_group'),
(129,1,0,'acp_users','acp',13,147,148,'ACP_USER_PERM','perm','acl_a_user && acl_a_viewauth'),
(130,1,0,'acp_users','acp',13,149,150,'ACP_USER_ATTACH','attach','acl_a_user'),
(131,1,1,'acp_words','acp',10,99,100,'ACP_WORDS','words','acl_a_words'),
(132,1,1,'acp_users','acp',2,5,6,'ACP_MANAGE_USERS','overview','acl_a_user'),
(133,1,1,'acp_groups','acp',2,7,8,'ACP_GROUPS_MANAGE','manage','acl_a_group'),
(134,1,1,'acp_forums','acp',2,9,10,'ACP_MANAGE_FORUMS','manage','acl_a_forum'),
(135,1,1,'acp_logs','acp',2,11,12,'ACP_MOD_LOGS','mod','acl_a_viewlogs'),
(136,1,1,'acp_bots','acp',2,13,14,'ACP_BOTS','bots','acl_a_bots'),
(137,1,1,'acp_php_info','acp',2,15,16,'ACP_PHP_INFO','info','acl_a_phpinfo'),
(138,1,1,'acp_permissions','acp',8,75,76,'ACP_FORUM_PERMISSIONS','setting_forum_local','acl_a_fauth && (acl_a_authusers || acl_a_authgroups)'),
(139,1,1,'acp_permissions','acp',8,77,78,'ACP_FORUM_PERMISSIONS_COPY','setting_forum_copy','acl_a_fauth && acl_a_authusers && acl_a_authgroups && acl_a_mauth'),
(140,1,1,'acp_permissions','acp',8,79,80,'ACP_FORUM_MODERATORS','setting_mod_local','acl_a_mauth && (acl_a_authusers || acl_a_authgroups)'),
(141,1,1,'acp_permissions','acp',8,81,82,'ACP_USERS_FORUM_PERMISSIONS','setting_user_local','acl_a_authusers && (acl_a_mauth || acl_a_fauth)'),
(142,1,1,'acp_permissions','acp',8,83,84,'ACP_GROUPS_FORUM_PERMISSIONS','setting_group_local','acl_a_authgroups && (acl_a_mauth || acl_a_fauth)'),
(143,1,1,'','mcp',0,1,10,'MCP_MAIN','',''),
(144,1,1,'','mcp',0,11,22,'MCP_QUEUE','',''),
(145,1,1,'','mcp',0,23,36,'MCP_REPORTS','',''),
(146,1,1,'','mcp',0,37,42,'MCP_NOTES','',''),
(147,1,1,'','mcp',0,43,52,'MCP_WARN','',''),
(148,1,1,'','mcp',0,53,60,'MCP_LOGS','',''),
(149,1,1,'','mcp',0,61,68,'MCP_BAN','',''),
(150,1,1,'mcp_ban','mcp',149,62,63,'MCP_BAN_USERNAMES','user','acl_m_ban'),
(151,1,1,'mcp_ban','mcp',149,64,65,'MCP_BAN_IPS','ip','acl_m_ban'),
(152,1,1,'mcp_ban','mcp',149,66,67,'MCP_BAN_EMAILS','email','acl_m_ban'),
(153,1,1,'mcp_logs','mcp',148,54,55,'MCP_LOGS_FRONT','front','acl_m_ || aclf_m_'),
(154,1,1,'mcp_logs','mcp',148,56,57,'MCP_LOGS_FORUM_VIEW','forum_logs','acl_m_,$id'),
(155,1,1,'mcp_logs','mcp',148,58,59,'MCP_LOGS_TOPIC_VIEW','topic_logs','acl_m_,$id'),
(156,1,1,'mcp_main','mcp',143,2,3,'MCP_MAIN_FRONT','front',''),
(157,1,1,'mcp_main','mcp',143,4,5,'MCP_MAIN_FORUM_VIEW','forum_view','acl_m_,$id'),
(158,1,1,'mcp_main','mcp',143,6,7,'MCP_MAIN_TOPIC_VIEW','topic_view','acl_m_,$id'),
(159,1,1,'mcp_main','mcp',143,8,9,'MCP_MAIN_POST_DETAILS','post_details','acl_m_,$id || (!$id && aclf_m_)'),
(160,1,1,'mcp_notes','mcp',146,38,39,'MCP_NOTES_FRONT','front',''),
(161,1,1,'mcp_notes','mcp',146,40,41,'MCP_NOTES_USER','user_notes',''),
(162,1,1,'mcp_pm_reports','mcp',145,30,31,'MCP_PM_REPORTS_OPEN','pm_reports','acl_m_pm_report'),
(163,1,1,'mcp_pm_reports','mcp',145,32,33,'MCP_PM_REPORTS_CLOSED','pm_reports_closed','acl_m_pm_report'),
(164,1,1,'mcp_pm_reports','mcp',145,34,35,'MCP_PM_REPORT_DETAILS','pm_report_details','acl_m_pm_report'),
(165,1,1,'mcp_queue','mcp',144,12,13,'MCP_QUEUE_UNAPPROVED_TOPICS','unapproved_topics','aclf_m_approve'),
(166,1,1,'mcp_queue','mcp',144,14,15,'MCP_QUEUE_UNAPPROVED_POSTS','unapproved_posts','aclf_m_approve'),
(167,1,1,'mcp_queue','mcp',144,16,17,'MCP_QUEUE_DELETED_TOPICS','deleted_topics','aclf_m_approve'),
(168,1,1,'mcp_queue','mcp',144,18,19,'MCP_QUEUE_DELETED_POSTS','deleted_posts','aclf_m_approve'),
(169,1,1,'mcp_queue','mcp',144,20,21,'MCP_QUEUE_APPROVE_DETAILS','approve_details','acl_m_approve,$id || (!$id && aclf_m_approve)'),
(170,1,1,'mcp_reports','mcp',145,24,25,'MCP_REPORTS_OPEN','reports','aclf_m_report'),
(171,1,1,'mcp_reports','mcp',145,26,27,'MCP_REPORTS_CLOSED','reports_closed','aclf_m_report'),
(172,1,1,'mcp_reports','mcp',145,28,29,'MCP_REPORT_DETAILS','report_details','acl_m_report,$id || (!$id && aclf_m_report)'),
(173,1,1,'mcp_warn','mcp',147,44,45,'MCP_WARN_FRONT','front','aclf_m_warn'),
(174,1,1,'mcp_warn','mcp',147,46,47,'MCP_WARN_LIST','list','aclf_m_warn'),
(175,1,1,'mcp_warn','mcp',147,48,49,'MCP_WARN_USER','warn_user','aclf_m_warn'),
(176,1,1,'mcp_warn','mcp',147,50,51,'MCP_WARN_POST','warn_post','acl_m_warn && acl_f_read,$id'),
(177,1,1,'','ucp',0,1,14,'UCP_MAIN','',''),
(178,1,1,'','ucp',0,15,28,'UCP_PROFILE','',''),
(179,1,1,'','ucp',0,29,38,'UCP_PREFS','',''),
(180,1,1,'ucp_pm','ucp',0,39,48,'UCP_PM','',''),
(181,1,1,'','ucp',0,49,54,'UCP_USERGROUPS','',''),
(182,1,1,'','ucp',0,55,60,'UCP_ZEBRA','',''),
(183,1,1,'ucp_attachments','ucp',177,10,11,'UCP_MAIN_ATTACHMENTS','attachments','acl_u_attach'),
(184,1,1,'ucp_auth_link','ucp',178,26,27,'UCP_AUTH_LINK_MANAGE','auth_link','authmethod_oauth'),
(185,1,1,'ucp_groups','ucp',181,50,51,'UCP_USERGROUPS_MEMBER','membership',''),
(186,1,1,'ucp_groups','ucp',181,52,53,'UCP_USERGROUPS_MANAGE','manage',''),
(187,1,1,'ucp_main','ucp',177,2,3,'UCP_MAIN_FRONT','front',''),
(188,1,1,'ucp_main','ucp',177,4,5,'UCP_MAIN_SUBSCRIBED','subscribed',''),
(189,1,1,'ucp_main','ucp',177,6,7,'UCP_MAIN_BOOKMARKS','bookmarks','cfg_allow_bookmarks'),
(190,1,1,'ucp_main','ucp',177,8,9,'UCP_MAIN_DRAFTS','drafts',''),
(191,1,1,'ucp_notifications','ucp',179,36,37,'UCP_NOTIFICATION_OPTIONS','notification_options',''),
(192,1,1,'ucp_notifications','ucp',177,12,13,'UCP_NOTIFICATION_LIST','notification_list','cfg_allow_board_notifications'),
(193,1,0,'ucp_pm','ucp',180,40,41,'UCP_PM_VIEW','view','cfg_allow_privmsg'),
(194,1,1,'ucp_pm','ucp',180,42,43,'UCP_PM_COMPOSE','compose','cfg_allow_privmsg'),
(195,1,1,'ucp_pm','ucp',180,44,45,'UCP_PM_DRAFTS','drafts','cfg_allow_privmsg'),
(196,1,1,'ucp_pm','ucp',180,46,47,'UCP_PM_OPTIONS','options','cfg_allow_privmsg'),
(197,1,1,'ucp_prefs','ucp',179,30,31,'UCP_PREFS_PERSONAL','personal',''),
(198,1,1,'ucp_prefs','ucp',179,32,33,'UCP_PREFS_POST','post',''),
(199,1,1,'ucp_prefs','ucp',179,34,35,'UCP_PREFS_VIEW','view',''),
(200,1,1,'ucp_profile','ucp',178,16,17,'UCP_PROFILE_PROFILE_INFO','profile_info','acl_u_chgprofileinfo'),
(201,1,1,'ucp_profile','ucp',178,18,19,'UCP_PROFILE_SIGNATURE','signature','acl_u_sig'),
(202,1,1,'ucp_profile','ucp',178,20,21,'UCP_PROFILE_AVATAR','avatar','cfg_allow_avatar'),
(203,1,1,'ucp_profile','ucp',178,22,23,'UCP_PROFILE_REG_DETAILS','reg_details',''),
(204,1,1,'ucp_profile','ucp',178,24,25,'UCP_PROFILE_AUTOLOGIN_KEYS','autologin_keys',''),
(205,1,1,'ucp_zebra','ucp',182,56,57,'UCP_ZEBRA_FRIENDS','friends',''),
(206,1,1,'ucp_zebra','ucp',182,58,59,'UCP_ZEBRA_FOES','foes',''),
(207,1,1,'','acp',0,285,338,'ACP_CAT_GENERAL','',''),
(208,1,1,'','acp',207,286,287,'ACP_QUICK_ACCESS','',''),
(209,1,1,'','acp',207,288,313,'ACP_BOARD_CONFIGURATION','',''),
(210,1,1,'','acp',207,314,321,'ACP_CLIENT_COMMUNICATION','',''),
(211,1,1,'','acp',207,322,335,'ACP_SERVER_CONFIGURATION','',''),
(212,1,1,'','acp',0,339,348,'ACP_CAT_FORUMS','',''),
(213,1,1,'','acp',212,340,345,'ACP_MANAGE_FORUMS','',''),
(214,1,1,'','acp',212,346,347,'ACP_FORUM_BASED_PERMISSIONS','',''),
(215,1,1,'','acp',0,349,376,'ACP_CAT_POSTING','',''),
(216,1,1,'','acp',215,350,363,'ACP_MESSAGES','',''),
(217,1,1,'','acp',215,364,375,'ACP_ATTACHMENTS','',''),
(218,1,1,'','acp',0,377,434,'ACP_CAT_USERGROUP','',''),
(219,1,1,'','acp',218,378,413,'ACP_CAT_USERS','',''),
(220,1,1,'','acp',218,414,423,'ACP_GROUPS','',''),
(221,1,1,'','acp',218,424,433,'ACP_USER_SECURITY','',''),
(222,1,1,'','acp',0,435,484,'ACP_CAT_PERMISSIONS','',''),
(223,1,1,'','acp',222,436,445,'ACP_GLOBAL_PERMISSIONS','',''),
(224,1,1,'','acp',222,446,457,'ACP_FORUM_BASED_PERMISSIONS','',''),
(225,1,1,'','acp',222,458,467,'ACP_PERMISSION_ROLES','',''),
(226,1,1,'','acp',222,468,481,'ACP_PERMISSION_MASKS','',''),
(227,1,1,'','acp',0,485,500,'ACP_CAT_CUSTOMISE','',''),
(228,1,1,'','acp',227,486,491,'ACP_STYLE_MANAGEMENT','',''),
(229,1,1,'','acp',227,492,495,'ACP_EXTENSION_MANAGEMENT','',''),
(230,1,1,'','acp',227,496,499,'ACP_LANGUAGE','',''),
(231,1,1,'','acp',0,501,520,'ACP_CAT_MAINTENANCE','',''),
(232,1,1,'','acp',231,502,511,'ACP_FORUM_LOGS','',''),
(233,1,1,'','acp',231,512,519,'ACP_CAT_DATABASE','',''),
(234,1,1,'','acp',0,521,544,'ACP_CAT_SYSTEM','',''),
(235,1,1,'','acp',234,522,525,'ACP_AUTOMATION','',''),
(236,1,1,'','acp',234,526,535,'ACP_GENERAL_TASKS','',''),
(237,1,1,'','acp',234,536,543,'ACP_MODULE_MANAGEMENT','',''),
(238,1,1,'','acp',0,545,546,'ACP_CAT_DOT_MODS','',''),
(239,1,1,'acp_attachments','acp',209,289,290,'ACP_ATTACHMENT_SETTINGS','attach','acl_a_attach'),
(240,1,1,'acp_attachments','acp',217,365,366,'ACP_ATTACHMENT_SETTINGS','attach','acl_a_attach'),
(241,1,1,'acp_attachments','acp',217,367,368,'ACP_MANAGE_EXTENSIONS','extensions','acl_a_attach'),
(242,1,1,'acp_attachments','acp',217,369,370,'ACP_EXTENSION_GROUPS','ext_groups','acl_a_attach'),
(243,1,1,'acp_attachments','acp',217,371,372,'ACP_ORPHAN_ATTACHMENTS','orphan','acl_a_attach'),
(244,1,1,'acp_attachments','acp',217,373,374,'ACP_MANAGE_ATTACHMENTS','manage','acl_a_attach'),
(245,1,1,'acp_ban','acp',221,425,426,'ACP_BAN_EMAILS','email','acl_a_ban'),
(246,1,1,'acp_ban','acp',221,427,428,'ACP_BAN_IPS','ip','acl_a_ban'),
(247,1,1,'acp_ban','acp',221,429,430,'ACP_BAN_USERNAMES','user','acl_a_ban'),
(248,1,1,'acp_bbcodes','acp',216,351,352,'ACP_BBCODES','bbcodes','acl_a_bbcode'),
(249,1,1,'acp_board','acp',209,291,292,'ACP_BOARD_SETTINGS','settings','acl_a_board'),
(250,1,1,'acp_board','acp',209,293,294,'ACP_BOARD_FEATURES','features','acl_a_board'),
(251,1,1,'acp_board','acp',209,295,296,'ACP_AVATAR_SETTINGS','avatar','acl_a_board'),
(252,1,1,'acp_board','acp',209,297,298,'ACP_MESSAGE_SETTINGS','message','acl_a_board'),
(253,1,1,'acp_board','acp',216,353,354,'ACP_MESSAGE_SETTINGS','message','acl_a_board'),
(254,1,1,'acp_board','acp',209,299,300,'ACP_POST_SETTINGS','post','acl_a_board'),
(255,1,1,'acp_board','acp',216,355,356,'ACP_POST_SETTINGS','post','acl_a_board'),
(256,1,1,'acp_board','acp',209,301,302,'ACP_SIGNATURE_SETTINGS','signature','acl_a_board'),
(257,1,1,'acp_board','acp',209,303,304,'ACP_FEED_SETTINGS','feed','acl_a_board'),
(258,1,1,'acp_board','acp',209,305,306,'ACP_REGISTER_SETTINGS','registration','acl_a_board'),
(259,1,1,'acp_board','acp',210,315,316,'ACP_AUTH_SETTINGS','auth','acl_a_server'),
(260,1,1,'acp_board','acp',210,317,318,'ACP_EMAIL_SETTINGS','email','acl_a_server'),
(261,1,1,'acp_board','acp',211,323,324,'ACP_COOKIE_SETTINGS','cookie','acl_a_server'),
(262,1,1,'acp_board','acp',211,325,326,'ACP_SERVER_SETTINGS','server','acl_a_server'),
(263,1,1,'acp_board','acp',211,327,328,'ACP_SECURITY_SETTINGS','security','acl_a_server'),
(264,1,1,'acp_board','acp',211,329,330,'ACP_LOAD_SETTINGS','load','acl_a_server'),
(265,1,1,'acp_bots','acp',236,527,528,'ACP_BOTS','bots','acl_a_bots'),
(266,1,1,'acp_captcha','acp',209,307,308,'ACP_VC_SETTINGS','visual','acl_a_board'),
(267,1,0,'acp_captcha','acp',209,309,310,'ACP_VC_CAPTCHA_DISPLAY','img','acl_a_board'),
(268,1,1,'acp_contact','acp',209,311,312,'ACP_CONTACT_SETTINGS','contact','acl_a_board'),
(269,1,1,'acp_database','acp',233,513,514,'ACP_BACKUP','backup','acl_a_backup'),
(270,1,1,'acp_database','acp',233,515,516,'ACP_RESTORE','restore','acl_a_backup'),
(271,1,1,'acp_disallow','acp',221,431,432,'ACP_DISALLOW_USERNAMES','usernames','acl_a_names'),
(272,1,1,'acp_email','acp',236,529,530,'ACP_MASS_EMAIL','email','acl_a_email && cfg_email_enable'),
(273,1,1,'acp_extensions','acp',229,493,494,'ACP_EXTENSIONS','main','acl_a_extensions'),
(274,1,1,'acp_forums','acp',213,341,342,'ACP_MANAGE_FORUMS','manage','acl_a_forum'),
(275,1,1,'acp_groups','acp',220,415,416,'ACP_GROUPS_MANAGE','manage','acl_a_group'),
(276,1,1,'acp_groups','acp',220,417,418,'ACP_GROUPS_POSITION','position','acl_a_group'),
(277,1,1,'acp_help_phpbb','acp',211,331,332,'ACP_HELP_PHPBB','help_phpbb','acl_a_server'),
(278,1,1,'acp_icons','acp',216,357,358,'ACP_ICONS','icons','acl_a_icons'),
(279,1,1,'acp_icons','acp',216,359,360,'ACP_SMILIES','smilies','acl_a_icons'),
(280,1,1,'acp_inactive','acp',219,379,380,'ACP_INACTIVE_USERS','list','acl_a_user'),
(281,1,1,'acp_jabber','acp',210,319,320,'ACP_JABBER_SETTINGS','settings','acl_a_jabber'),
(282,1,1,'acp_language','acp',230,497,498,'ACP_LANGUAGE_PACKS','lang_packs','acl_a_language'),
(283,1,1,'acp_logs','acp',232,503,504,'ACP_ADMIN_LOGS','admin','acl_a_viewlogs'),
(284,1,1,'acp_logs','acp',232,505,506,'ACP_MOD_LOGS','mod','acl_a_viewlogs'),
(285,1,1,'acp_logs','acp',232,507,508,'ACP_USERS_LOGS','users','acl_a_viewlogs'),
(286,1,1,'acp_logs','acp',232,509,510,'ACP_CRITICAL_LOGS','critical','acl_a_viewlogs'),
(287,1,1,'acp_main','acp',207,336,337,'ACP_INDEX','main',''),
(288,1,1,'acp_modules','acp',237,537,538,'ACP','acp','acl_a_modules'),
(289,1,1,'acp_modules','acp',237,539,540,'UCP','ucp','acl_a_modules'),
(290,1,1,'acp_modules','acp',237,541,542,'MCP','mcp','acl_a_modules'),
(291,1,1,'acp_permission_roles','acp',225,459,460,'ACP_ADMIN_ROLES','admin_roles','acl_a_roles && acl_a_aauth'),
(292,1,1,'acp_permission_roles','acp',225,461,462,'ACP_USER_ROLES','user_roles','acl_a_roles && acl_a_uauth'),
(293,1,1,'acp_permission_roles','acp',225,463,464,'ACP_MOD_ROLES','mod_roles','acl_a_roles && acl_a_mauth'),
(294,1,1,'acp_permission_roles','acp',225,465,466,'ACP_FORUM_ROLES','forum_roles','acl_a_roles && acl_a_fauth'),
(295,1,1,'acp_permissions','acp',222,482,483,'ACP_PERMISSIONS','intro','acl_a_authusers || acl_a_authgroups || acl_a_viewauth'),
(296,1,0,'acp_permissions','acp',226,469,470,'ACP_PERMISSION_TRACE','trace','acl_a_viewauth'),
(297,1,1,'acp_permissions','acp',224,447,448,'ACP_FORUM_PERMISSIONS','setting_forum_local','acl_a_fauth && (acl_a_authusers || acl_a_authgroups)'),
(298,1,1,'acp_permissions','acp',224,449,450,'ACP_FORUM_PERMISSIONS_COPY','setting_forum_copy','acl_a_fauth && acl_a_authusers && acl_a_authgroups && acl_a_mauth'),
(299,1,1,'acp_permissions','acp',224,451,452,'ACP_FORUM_MODERATORS','setting_mod_local','acl_a_mauth && (acl_a_authusers || acl_a_authgroups)'),
(300,1,1,'acp_permissions','acp',223,437,438,'ACP_USERS_PERMISSIONS','setting_user_global','acl_a_authusers && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(301,1,1,'acp_permissions','acp',219,381,382,'ACP_USERS_PERMISSIONS','setting_user_global','acl_a_authusers && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(302,1,1,'acp_permissions','acp',224,453,454,'ACP_USERS_FORUM_PERMISSIONS','setting_user_local','acl_a_authusers && (acl_a_mauth || acl_a_fauth)'),
(303,1,1,'acp_permissions','acp',219,383,384,'ACP_USERS_FORUM_PERMISSIONS','setting_user_local','acl_a_authusers && (acl_a_mauth || acl_a_fauth)'),
(304,1,1,'acp_permissions','acp',223,439,440,'ACP_GROUPS_PERMISSIONS','setting_group_global','acl_a_authgroups && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(305,1,1,'acp_permissions','acp',220,419,420,'ACP_GROUPS_PERMISSIONS','setting_group_global','acl_a_authgroups && (acl_a_aauth || acl_a_mauth || acl_a_uauth)'),
(306,1,1,'acp_permissions','acp',224,455,456,'ACP_GROUPS_FORUM_PERMISSIONS','setting_group_local','acl_a_authgroups && (acl_a_mauth || acl_a_fauth)'),
(307,1,1,'acp_permissions','acp',220,421,422,'ACP_GROUPS_FORUM_PERMISSIONS','setting_group_local','acl_a_authgroups && (acl_a_mauth || acl_a_fauth)'),
(308,1,1,'acp_permissions','acp',223,441,442,'ACP_ADMINISTRATORS','setting_admin_global','acl_a_aauth && (acl_a_authusers || acl_a_authgroups)'),
(309,1,1,'acp_permissions','acp',223,443,444,'ACP_GLOBAL_MODERATORS','setting_mod_global','acl_a_mauth && (acl_a_authusers || acl_a_authgroups)'),
(310,1,1,'acp_permissions','acp',226,471,472,'ACP_VIEW_ADMIN_PERMISSIONS','view_admin_global','acl_a_viewauth'),
(311,1,1,'acp_permissions','acp',226,473,474,'ACP_VIEW_USER_PERMISSIONS','view_user_global','acl_a_viewauth'),
(312,1,1,'acp_permissions','acp',226,475,476,'ACP_VIEW_GLOBAL_MOD_PERMISSIONS','view_mod_global','acl_a_viewauth'),
(313,1,1,'acp_permissions','acp',226,477,478,'ACP_VIEW_FORUM_MOD_PERMISSIONS','view_mod_local','acl_a_viewauth'),
(314,1,1,'acp_permissions','acp',226,479,480,'ACP_VIEW_FORUM_PERMISSIONS','view_forum_local','acl_a_viewauth'),
(315,1,1,'acp_php_info','acp',236,531,532,'ACP_PHP_INFO','info','acl_a_phpinfo'),
(316,1,1,'acp_profile','acp',219,385,386,'ACP_CUSTOM_PROFILE_FIELDS','profile','acl_a_profile'),
(317,1,1,'acp_prune','acp',213,343,344,'ACP_PRUNE_FORUMS','forums','acl_a_prune'),
(318,1,1,'acp_prune','acp',219,387,388,'ACP_PRUNE_USERS','users','acl_a_userdel'),
(319,1,1,'acp_ranks','acp',219,389,390,'ACP_MANAGE_RANKS','ranks','acl_a_ranks'),
(320,1,1,'acp_reasons','acp',236,533,534,'ACP_MANAGE_REASONS','main','acl_a_reasons'),
(321,1,1,'acp_search','acp',211,333,334,'ACP_SEARCH_SETTINGS','settings','acl_a_search'),
(322,1,1,'acp_search','acp',233,517,518,'ACP_SEARCH_INDEX','index','acl_a_search'),
(323,1,1,'acp_styles','acp',228,487,488,'ACP_STYLES','style','acl_a_styles'),
(324,1,1,'acp_styles','acp',228,489,490,'ACP_STYLES_INSTALL','install','acl_a_styles'),
(325,1,1,'acp_update','acp',235,523,524,'ACP_VERSION_CHECK','version_check','acl_a_board'),
(326,1,1,'acp_users','acp',219,391,392,'ACP_MANAGE_USERS','overview','acl_a_user'),
(327,1,0,'acp_users','acp',219,393,394,'ACP_USER_FEEDBACK','feedback','acl_a_user'),
(328,1,0,'acp_users','acp',219,395,396,'ACP_USER_WARNINGS','warnings','acl_a_user'),
(329,1,0,'acp_users','acp',219,397,398,'ACP_USER_PROFILE','profile','acl_a_user'),
(330,1,0,'acp_users','acp',219,399,400,'ACP_USER_PREFS','prefs','acl_a_user'),
(331,1,0,'acp_users','acp',219,401,402,'ACP_USER_AVATAR','avatar','acl_a_user'),
(332,1,0,'acp_users','acp',219,403,404,'ACP_USER_RANK','rank','acl_a_user'),
(333,1,0,'acp_users','acp',219,405,406,'ACP_USER_SIG','sig','acl_a_user'),
(334,1,0,'acp_users','acp',219,407,408,'ACP_USER_GROUPS','groups','acl_a_user && acl_a_group'),
(335,1,0,'acp_users','acp',219,409,410,'ACP_USER_PERM','perm','acl_a_user && acl_a_viewauth'),
(336,1,0,'acp_users','acp',219,411,412,'ACP_USER_ATTACH','attach','acl_a_user'),
(337,1,1,'acp_words','acp',216,361,362,'ACP_WORDS','words','acl_a_words');
/*!40000 ALTER TABLE `phpbb_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_notification_emails`
--

DROP TABLE IF EXISTS `phpbb_notification_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_notification_emails` (
  `notification_type_id` smallint(4) unsigned NOT NULL DEFAULT 0,
  `item_id` int(10) unsigned NOT NULL DEFAULT 0,
  `item_parent_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`notification_type_id`,`item_id`,`item_parent_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_notification_emails`
--

LOCK TABLES `phpbb_notification_emails` WRITE;
/*!40000 ALTER TABLE `phpbb_notification_emails` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_notification_emails` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_notification_types`
--

DROP TABLE IF EXISTS `phpbb_notification_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_notification_types` (
  `notification_type_id` smallint(4) unsigned NOT NULL AUTO_INCREMENT,
  `notification_type_name` varchar(255) NOT NULL DEFAULT '',
  `notification_type_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`notification_type_id`),
  UNIQUE KEY `type` (`notification_type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_notification_types`
--

LOCK TABLES `phpbb_notification_types` WRITE;
/*!40000 ALTER TABLE `phpbb_notification_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_notification_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_notifications`
--

DROP TABLE IF EXISTS `phpbb_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_notifications` (
  `notification_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notification_type_id` smallint(4) unsigned NOT NULL DEFAULT 0,
  `item_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `item_parent_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `notification_read` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `notification_time` int(11) unsigned NOT NULL DEFAULT 1,
  `notification_data` text NOT NULL,
  PRIMARY KEY (`notification_id`),
  KEY `item_ident` (`notification_type_id`,`item_id`),
  KEY `user` (`user_id`,`notification_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_notifications`
--

LOCK TABLES `phpbb_notifications` WRITE;
/*!40000 ALTER TABLE `phpbb_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_oauth_accounts`
--

DROP TABLE IF EXISTS `phpbb_oauth_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_oauth_accounts` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `provider` varchar(255) NOT NULL DEFAULT '',
  `oauth_provider_id` text NOT NULL,
  PRIMARY KEY (`user_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_oauth_accounts`
--

LOCK TABLES `phpbb_oauth_accounts` WRITE;
/*!40000 ALTER TABLE `phpbb_oauth_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_oauth_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_oauth_states`
--

DROP TABLE IF EXISTS `phpbb_oauth_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_oauth_states` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `session_id` char(32) NOT NULL DEFAULT '',
  `provider` varchar(255) NOT NULL DEFAULT '',
  `oauth_state` varchar(255) NOT NULL DEFAULT '',
  KEY `user_id` (`user_id`),
  KEY `provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_oauth_states`
--

LOCK TABLES `phpbb_oauth_states` WRITE;
/*!40000 ALTER TABLE `phpbb_oauth_states` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_oauth_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_oauth_tokens`
--

DROP TABLE IF EXISTS `phpbb_oauth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_oauth_tokens` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `session_id` char(32) NOT NULL DEFAULT '',
  `provider` varchar(255) NOT NULL DEFAULT '',
  `oauth_token` mediumtext NOT NULL,
  KEY `user_id` (`user_id`),
  KEY `provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_oauth_tokens`
--

LOCK TABLES `phpbb_oauth_tokens` WRITE;
/*!40000 ALTER TABLE `phpbb_oauth_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_oauth_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_poll_options`
--

DROP TABLE IF EXISTS `phpbb_poll_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_poll_options` (
  `poll_option_id` tinyint(4) NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `poll_option_text` text NOT NULL,
  `poll_option_total` mediumint(8) unsigned NOT NULL DEFAULT 0,
  KEY `poll_opt_id` (`poll_option_id`),
  KEY `topic_id` (`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_poll_options`
--

LOCK TABLES `phpbb_poll_options` WRITE;
/*!40000 ALTER TABLE `phpbb_poll_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_poll_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_poll_votes`
--

DROP TABLE IF EXISTS `phpbb_poll_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_poll_votes` (
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `poll_option_id` tinyint(4) NOT NULL DEFAULT 0,
  `vote_user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `vote_user_ip` varchar(40) NOT NULL DEFAULT '',
  KEY `topic_id` (`topic_id`),
  KEY `vote_user_id` (`vote_user_id`),
  KEY `vote_user_ip` (`vote_user_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_poll_votes`
--

LOCK TABLES `phpbb_poll_votes` WRITE;
/*!40000 ALTER TABLE `phpbb_poll_votes` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_poll_votes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_posts`
--

DROP TABLE IF EXISTS `phpbb_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_posts` (
  `post_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `icon_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poster_ip` varchar(40) NOT NULL DEFAULT '',
  `post_time` int(11) unsigned NOT NULL DEFAULT 0,
  `post_reported` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `enable_bbcode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_smilies` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_sig` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `post_username` varchar(255) NOT NULL DEFAULT '',
  `post_subject` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `post_text` mediumtext NOT NULL,
  `post_checksum` varchar(32) NOT NULL DEFAULT '',
  `post_attachment` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `bbcode_bitfield` varchar(255) NOT NULL DEFAULT '',
  `bbcode_uid` varchar(8) NOT NULL DEFAULT '',
  `post_postcount` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `post_edit_time` int(11) unsigned NOT NULL DEFAULT 0,
  `post_edit_reason` varchar(255) NOT NULL DEFAULT '',
  `post_edit_user` int(10) unsigned NOT NULL DEFAULT 0,
  `post_edit_count` smallint(4) unsigned NOT NULL DEFAULT 0,
  `post_edit_locked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `post_visibility` tinyint(3) NOT NULL DEFAULT 0,
  `post_delete_time` int(11) unsigned NOT NULL DEFAULT 0,
  `post_delete_reason` varchar(255) NOT NULL DEFAULT '',
  `post_delete_user` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`post_id`),
  KEY `forum_id` (`forum_id`),
  KEY `topic_id` (`topic_id`),
  KEY `poster_ip` (`poster_ip`),
  KEY `poster_id` (`poster_id`),
  KEY `tid_post_time` (`topic_id`,`post_time`),
  KEY `post_username` (`post_username`),
  KEY `post_visibility` (`post_visibility`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_posts`
--

LOCK TABLES `phpbb_posts` WRITE;
/*!40000 ALTER TABLE `phpbb_posts` DISABLE KEYS */;
INSERT INTO `phpbb_posts` VALUES
(1,1,2,2,0,'192.168.65.1',1776276588,0,1,1,1,1,'','Welcome to phpBB3','<t>This is an example post in your phpBB3 installation. Everything seems to be working. You may delete this post if you like and continue to set up your board. During the installation process your first category and your first forum are assigned an appropriate set of permissions for the predefined usergroups administrators, bots, global moderators, guests, registered users and registered COPPA users. If you also choose to delete your first category and your first forum, do not forget to assign permissions for all these usergroups for all new categories and forums you create. It is recommended to rename your first category and your first forum and copy permissions from these while creating new categories and forums. Have fun!</t>','5dd683b17f641daf84c040bfefc58ce9',0,'','',1,0,'',0,0,0,1,0,'',0),
(2,1,2,2,0,'192.168.65.1',1776276588,0,1,1,1,1,'','Welcome to phpBB3','<t>This is an example post in your phpBB3 installation. Everything seems to be working. You may delete this post if you like and continue to set up your board. During the installation process your first category and your first forum are assigned an appropriate set of permissions for the predefined usergroups administrators, bots, global moderators, guests, registered users and registered COPPA users. If you also choose to delete your first category and your first forum, do not forget to assign permissions for all these usergroups for all new categories and forums you create. It is recommended to rename your first category and your first forum and copy permissions from these while creating new categories and forums. Have fun!</t>','5dd683b17f641daf84c040bfefc58ce9',0,'','',1,0,'',0,0,0,1,0,'',0);
/*!40000 ALTER TABLE `phpbb_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_privmsgs`
--

DROP TABLE IF EXISTS `phpbb_privmsgs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_privmsgs` (
  `msg_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `root_level` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `author_id` int(10) unsigned NOT NULL DEFAULT 0,
  `icon_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `author_ip` varchar(40) NOT NULL DEFAULT '',
  `message_time` int(11) unsigned NOT NULL DEFAULT 0,
  `enable_bbcode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_smilies` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_sig` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `message_subject` varchar(255) NOT NULL DEFAULT '',
  `message_text` mediumtext NOT NULL,
  `message_edit_reason` varchar(255) NOT NULL DEFAULT '',
  `message_edit_user` int(10) unsigned NOT NULL DEFAULT 0,
  `message_attachment` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `bbcode_bitfield` varchar(255) NOT NULL DEFAULT '',
  `bbcode_uid` varchar(8) NOT NULL DEFAULT '',
  `message_edit_time` int(11) unsigned NOT NULL DEFAULT 0,
  `message_edit_count` smallint(4) unsigned NOT NULL DEFAULT 0,
  `to_address` text NOT NULL,
  `bcc_address` text NOT NULL,
  `message_reported` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`msg_id`),
  KEY `author_ip` (`author_ip`),
  KEY `message_time` (`message_time`),
  KEY `author_id` (`author_id`),
  KEY `root_level` (`root_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_privmsgs`
--

LOCK TABLES `phpbb_privmsgs` WRITE;
/*!40000 ALTER TABLE `phpbb_privmsgs` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_privmsgs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_privmsgs_folder`
--

DROP TABLE IF EXISTS `phpbb_privmsgs_folder`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_privmsgs_folder` (
  `folder_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `folder_name` varchar(255) NOT NULL DEFAULT '',
  `pm_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`folder_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_privmsgs_folder`
--

LOCK TABLES `phpbb_privmsgs_folder` WRITE;
/*!40000 ALTER TABLE `phpbb_privmsgs_folder` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_privmsgs_folder` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_privmsgs_rules`
--

DROP TABLE IF EXISTS `phpbb_privmsgs_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_privmsgs_rules` (
  `rule_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rule_check` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_connection` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_string` varchar(255) NOT NULL DEFAULT '',
  `rule_user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rule_group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_action` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_folder_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rule_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_privmsgs_rules`
--

LOCK TABLES `phpbb_privmsgs_rules` WRITE;
/*!40000 ALTER TABLE `phpbb_privmsgs_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_privmsgs_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_privmsgs_to`
--

DROP TABLE IF EXISTS `phpbb_privmsgs_to`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_privmsgs_to` (
  `msg_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `author_id` int(10) unsigned NOT NULL DEFAULT 0,
  `pm_deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_new` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_unread` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_replied` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_marked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_forwarded` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `folder_id` int(11) NOT NULL DEFAULT 0,
  KEY `msg_id` (`msg_id`),
  KEY `author_id` (`author_id`),
  KEY `usr_flder_id` (`user_id`,`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_privmsgs_to`
--

LOCK TABLES `phpbb_privmsgs_to` WRITE;
/*!40000 ALTER TABLE `phpbb_privmsgs_to` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_privmsgs_to` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_profile_fields`
--

DROP TABLE IF EXISTS `phpbb_profile_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_profile_fields` (
  `field_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `field_name` varchar(255) NOT NULL DEFAULT '',
  `field_type` varchar(100) NOT NULL DEFAULT '',
  `field_ident` varchar(20) NOT NULL DEFAULT '',
  `field_length` varchar(20) NOT NULL DEFAULT '',
  `field_minlen` varchar(255) NOT NULL DEFAULT '',
  `field_maxlen` varchar(255) NOT NULL DEFAULT '',
  `field_novalue` varchar(255) NOT NULL DEFAULT '',
  `field_default_value` varchar(255) NOT NULL DEFAULT '',
  `field_validation` varchar(128) NOT NULL DEFAULT '',
  `field_required` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_show_on_reg` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_hide` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_no_view` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_order` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `field_show_profile` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_show_on_vt` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_show_novalue` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_show_on_pm` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_show_on_ml` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_is_contact` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `field_contact_desc` varchar(255) NOT NULL DEFAULT '',
  `field_contact_url` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`field_id`),
  KEY `fld_type` (`field_type`),
  KEY `fld_ordr` (`field_order`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_profile_fields`
--

LOCK TABLES `phpbb_profile_fields` WRITE;
/*!40000 ALTER TABLE `phpbb_profile_fields` DISABLE KEYS */;
INSERT INTO `phpbb_profile_fields` VALUES
(1,'phpbb_location','profilefields.type.string','phpbb_location','20','2','100','','','.*',0,0,0,0,1,1,1,1,0,1,1,0,'',''),
(2,'phpbb_website','profilefields.type.url','phpbb_website','40','12','255','','','',0,0,0,0,1,2,1,1,0,1,1,1,'VISIT_WEBSITE','%s'),
(3,'phpbb_interests','profilefields.type.text','phpbb_interests','3|30','2','500','','','.*',0,0,0,0,0,3,1,0,0,0,0,0,'',''),
(4,'phpbb_occupation','profilefields.type.text','phpbb_occupation','3|30','2','500','','','.*',0,0,0,0,0,4,1,0,0,0,0,0,'',''),
(5,'phpbb_icq','profilefields.type.string','phpbb_icq','20','3','15','','','[0-9]+',0,0,0,0,0,6,1,1,0,1,1,1,'SEND_ICQ_MESSAGE','https://www.icq.com/people/%s/'),
(6,'phpbb_yahoo','profilefields.type.string','phpbb_yahoo','40','5','255','','','.*',0,0,0,0,0,8,1,1,0,1,1,1,'SEND_YIM_MESSAGE','ymsgr:sendim?%s'),
(7,'phpbb_facebook','profilefields.type.string','phpbb_facebook','20','5','50','','','[\\w.]+',0,0,0,0,1,9,1,1,0,1,1,1,'VIEW_FACEBOOK_PROFILE','https://facebook.com/%s/'),
(8,'phpbb_twitter','profilefields.type.string','phpbb_twitter','20','1','15','','','[\\w_]+',0,0,0,0,1,10,1,1,0,1,1,1,'VIEW_TWITTER_PROFILE','https://twitter.com/%s'),
(9,'phpbb_skype','profilefields.type.string','phpbb_skype','20','6','32','','','[a-zA-Z][\\w\\.,\\-_]+',0,0,0,0,1,11,1,1,0,1,1,1,'VIEW_SKYPE_PROFILE','skype:%s?userinfo'),
(10,'phpbb_youtube','profilefields.type.string','phpbb_youtube','20','3','60','','','(@[a-zA-Z0-9_.-]{3,30}|c/[a-zA-Z][\\w\\.,\\-_]+|(channel|user)/[a-zA-Z][\\w\\.,\\-_]+)',0,0,0,0,1,12,1,1,0,1,1,1,'VIEW_YOUTUBE_PROFILE','https://youtube.com/%s'),
(11,'phpbb_location','profilefields.type.string','phpbb_location','20','2','100','','','.*',0,0,0,0,1,1,1,1,0,1,1,0,'',''),
(12,'phpbb_website','profilefields.type.url','phpbb_website','40','12','255','','','',0,0,0,0,1,2,1,1,0,1,1,1,'VISIT_WEBSITE','%s'),
(13,'phpbb_interests','profilefields.type.text','phpbb_interests','3|30','2','500','','','.*',0,0,0,0,0,3,1,0,0,0,0,0,'',''),
(14,'phpbb_occupation','profilefields.type.text','phpbb_occupation','3|30','2','500','','','.*',0,0,0,0,0,4,1,0,0,0,0,0,'',''),
(15,'phpbb_icq','profilefields.type.string','phpbb_icq','20','3','15','','','[0-9]+',0,0,0,0,0,6,1,1,0,1,1,1,'SEND_ICQ_MESSAGE','https://www.icq.com/people/%s/'),
(16,'phpbb_yahoo','profilefields.type.string','phpbb_yahoo','40','5','255','','','.*',0,0,0,0,0,8,1,1,0,1,1,1,'SEND_YIM_MESSAGE','ymsgr:sendim?%s'),
(17,'phpbb_facebook','profilefields.type.string','phpbb_facebook','20','5','50','','','[\\w.]+',0,0,0,0,1,9,1,1,0,1,1,1,'VIEW_FACEBOOK_PROFILE','https://facebook.com/%s/'),
(18,'phpbb_twitter','profilefields.type.string','phpbb_twitter','20','1','15','','','[\\w_]+',0,0,0,0,1,10,1,1,0,1,1,1,'VIEW_TWITTER_PROFILE','https://twitter.com/%s'),
(19,'phpbb_skype','profilefields.type.string','phpbb_skype','20','6','32','','','[a-zA-Z][\\w\\.,\\-_]+',0,0,0,0,1,11,1,1,0,1,1,1,'VIEW_SKYPE_PROFILE','skype:%s?userinfo'),
(20,'phpbb_youtube','profilefields.type.string','phpbb_youtube','20','3','60','','','(@[a-zA-Z0-9_.-]{3,30}|c/[a-zA-Z][\\w\\.,\\-_]+|(channel|user)/[a-zA-Z][\\w\\.,\\-_]+)',0,0,0,0,1,12,1,1,0,1,1,1,'VIEW_YOUTUBE_PROFILE','https://youtube.com/%s');
/*!40000 ALTER TABLE `phpbb_profile_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_profile_fields_data`
--

DROP TABLE IF EXISTS `phpbb_profile_fields_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_profile_fields_data` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `pf_phpbb_interests` mediumtext NOT NULL,
  `pf_phpbb_occupation` mediumtext NOT NULL,
  `pf_phpbb_location` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_facebook` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_icq` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_skype` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_twitter` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_youtube` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_website` varchar(255) NOT NULL DEFAULT '',
  `pf_phpbb_yahoo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_profile_fields_data`
--

LOCK TABLES `phpbb_profile_fields_data` WRITE;
/*!40000 ALTER TABLE `phpbb_profile_fields_data` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_profile_fields_data` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_profile_fields_lang`
--

DROP TABLE IF EXISTS `phpbb_profile_fields_lang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_profile_fields_lang` (
  `field_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `lang_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `option_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `field_type` varchar(100) NOT NULL DEFAULT '',
  `lang_value` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`field_id`,`lang_id`,`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_profile_fields_lang`
--

LOCK TABLES `phpbb_profile_fields_lang` WRITE;
/*!40000 ALTER TABLE `phpbb_profile_fields_lang` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_profile_fields_lang` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_profile_lang`
--

DROP TABLE IF EXISTS `phpbb_profile_lang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_profile_lang` (
  `field_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `lang_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `lang_name` varchar(255) NOT NULL DEFAULT '',
  `lang_explain` text NOT NULL,
  `lang_default_value` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`field_id`,`lang_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_profile_lang`
--

LOCK TABLES `phpbb_profile_lang` WRITE;
/*!40000 ALTER TABLE `phpbb_profile_lang` DISABLE KEYS */;
INSERT INTO `phpbb_profile_lang` VALUES
(1,1,'LOCATION','',''),
(1,2,'LOCATION','',''),
(2,1,'WEBSITE','',''),
(2,2,'WEBSITE','',''),
(3,1,'INTERESTS','',''),
(3,2,'INTERESTS','',''),
(4,1,'OCCUPATION','',''),
(4,2,'OCCUPATION','',''),
(5,1,'ICQ','',''),
(5,2,'ICQ','',''),
(6,1,'YAHOO','',''),
(6,2,'YAHOO','',''),
(7,1,'FACEBOOK','',''),
(7,2,'FACEBOOK','',''),
(8,1,'TWITTER','',''),
(8,2,'TWITTER','',''),
(9,1,'SKYPE','',''),
(9,2,'SKYPE','',''),
(10,1,'YOUTUBE','',''),
(10,2,'YOUTUBE','',''),
(11,2,'LOCATION','',''),
(12,2,'WEBSITE','',''),
(13,2,'INTERESTS','',''),
(14,2,'OCCUPATION','',''),
(15,2,'ICQ','',''),
(16,2,'YAHOO','',''),
(17,2,'FACEBOOK','',''),
(18,2,'TWITTER','',''),
(19,2,'SKYPE','',''),
(20,2,'YOUTUBE','','');
/*!40000 ALTER TABLE `phpbb_profile_lang` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_ranks`
--

DROP TABLE IF EXISTS `phpbb_ranks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_ranks` (
  `rank_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `rank_title` varchar(255) NOT NULL DEFAULT '',
  `rank_min` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rank_special` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `rank_image` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`rank_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_ranks`
--

LOCK TABLES `phpbb_ranks` WRITE;
/*!40000 ALTER TABLE `phpbb_ranks` DISABLE KEYS */;
INSERT INTO `phpbb_ranks` VALUES
(1,'Site Admin',0,1,''),
(2,'Site Admin',0,1,'');
/*!40000 ALTER TABLE `phpbb_ranks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_reports`
--

DROP TABLE IF EXISTS `phpbb_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_reports` (
  `report_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reason_id` smallint(4) unsigned NOT NULL DEFAULT 0,
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_notify` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `report_closed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `report_time` int(11) unsigned NOT NULL DEFAULT 0,
  `report_text` mediumtext NOT NULL,
  `pm_id` int(10) unsigned NOT NULL DEFAULT 0,
  `reported_post_enable_bbcode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_enable_smilies` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_text` mediumtext NOT NULL,
  `reported_post_uid` varchar(8) NOT NULL DEFAULT '',
  `reported_post_bitfield` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`report_id`),
  KEY `post_id` (`post_id`),
  KEY `pm_id` (`pm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_reports`
--

LOCK TABLES `phpbb_reports` WRITE;
/*!40000 ALTER TABLE `phpbb_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_reports_reasons`
--

DROP TABLE IF EXISTS `phpbb_reports_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_reports_reasons` (
  `reason_id` smallint(4) unsigned NOT NULL AUTO_INCREMENT,
  `reason_title` varchar(255) NOT NULL DEFAULT '',
  `reason_description` mediumtext NOT NULL,
  `reason_order` smallint(4) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`reason_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_reports_reasons`
--

LOCK TABLES `phpbb_reports_reasons` WRITE;
/*!40000 ALTER TABLE `phpbb_reports_reasons` DISABLE KEYS */;
INSERT INTO `phpbb_reports_reasons` VALUES
(1,'warez','The post contains links to illegal or pirated software.',1),
(2,'spam','The reported post has the only purpose to advertise for a website or another product.',2),
(3,'off_topic','The reported post is off topic.',3),
(4,'other','The reported post does not fit into any other category, please use the further information field.',4),
(5,'warez','The post contains links to illegal or pirated software.',1),
(6,'spam','The reported post has the only purpose to advertise for a website or another product.',2),
(7,'off_topic','The reported post is off topic.',3),
(8,'other','The reported post does not fit into any other category, please use the further information field.',4);
/*!40000 ALTER TABLE `phpbb_reports_reasons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_search_results`
--

DROP TABLE IF EXISTS `phpbb_search_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_search_results` (
  `search_key` varchar(32) NOT NULL DEFAULT '',
  `search_time` int(11) unsigned NOT NULL DEFAULT 0,
  `search_keywords` mediumtext NOT NULL,
  `search_authors` mediumtext NOT NULL,
  PRIMARY KEY (`search_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_search_results`
--

LOCK TABLES `phpbb_search_results` WRITE;
/*!40000 ALTER TABLE `phpbb_search_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_search_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_search_wordlist`
--

DROP TABLE IF EXISTS `phpbb_search_wordlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_search_wordlist` (
  `word_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `word_text` varchar(255) NOT NULL DEFAULT '',
  `word_common` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `word_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`word_id`),
  UNIQUE KEY `wrd_txt` (`word_text`),
  KEY `wrd_cnt` (`word_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_search_wordlist`
--

LOCK TABLES `phpbb_search_wordlist` WRITE;
/*!40000 ALTER TABLE `phpbb_search_wordlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_search_wordlist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_search_wordmatch`
--

DROP TABLE IF EXISTS `phpbb_search_wordmatch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_search_wordmatch` (
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `word_id` int(10) unsigned NOT NULL DEFAULT 0,
  `title_match` tinyint(1) unsigned NOT NULL DEFAULT 0,
  UNIQUE KEY `un_mtch` (`word_id`,`post_id`,`title_match`),
  KEY `word_id` (`word_id`),
  KEY `post_id` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_search_wordmatch`
--

LOCK TABLES `phpbb_search_wordmatch` WRITE;
/*!40000 ALTER TABLE `phpbb_search_wordmatch` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_search_wordmatch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_sessions`
--

DROP TABLE IF EXISTS `phpbb_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_sessions` (
  `session_id` char(32) NOT NULL DEFAULT '',
  `session_user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `session_last_visit` int(11) unsigned NOT NULL DEFAULT 0,
  `session_start` int(11) unsigned NOT NULL DEFAULT 0,
  `session_time` int(11) unsigned NOT NULL DEFAULT 0,
  `session_ip` varchar(40) NOT NULL DEFAULT '',
  `session_browser` varchar(150) NOT NULL DEFAULT '',
  `session_forwarded_for` varchar(255) NOT NULL DEFAULT '',
  `session_page` varchar(255) NOT NULL DEFAULT '',
  `session_viewonline` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `session_autologin` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `session_admin` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `session_forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`),
  KEY `session_time` (`session_time`),
  KEY `session_user_id` (`session_user_id`),
  KEY `session_fid` (`session_forum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_sessions`
--

LOCK TABLES `phpbb_sessions` WRITE;
/*!40000 ALTER TABLE `phpbb_sessions` DISABLE KEYS */;
INSERT INTO `phpbb_sessions` VALUES
('83f64ea2bb0446295fd2e368cb0741b2',1,1776276428,1776276428,1776276428,'192.168.65.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:149.0) Gecko/20100101 Firefox/149.0','','web/app.php/install/installer/status',1,0,0,0);
/*!40000 ALTER TABLE `phpbb_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_sessions_keys`
--

DROP TABLE IF EXISTS `phpbb_sessions_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_sessions_keys` (
  `key_id` char(32) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `last_ip` varchar(40) NOT NULL DEFAULT '',
  `last_login` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`key_id`,`user_id`),
  KEY `last_login` (`last_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_sessions_keys`
--

LOCK TABLES `phpbb_sessions_keys` WRITE;
/*!40000 ALTER TABLE `phpbb_sessions_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_sessions_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_sitelist`
--

DROP TABLE IF EXISTS `phpbb_sitelist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_sitelist` (
  `site_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `site_ip` varchar(40) NOT NULL DEFAULT '',
  `site_hostname` varchar(255) NOT NULL DEFAULT '',
  `ip_exclude` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_sitelist`
--

LOCK TABLES `phpbb_sitelist` WRITE;
/*!40000 ALTER TABLE `phpbb_sitelist` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_sitelist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_smilies`
--

DROP TABLE IF EXISTS `phpbb_smilies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_smilies` (
  `smiley_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL DEFAULT '',
  `emotion` varchar(255) NOT NULL DEFAULT '',
  `smiley_url` varchar(50) NOT NULL DEFAULT '',
  `smiley_width` smallint(4) unsigned NOT NULL DEFAULT 0,
  `smiley_height` smallint(4) unsigned NOT NULL DEFAULT 0,
  `smiley_order` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `display_on_posting` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`smiley_id`),
  KEY `display_on_post` (`display_on_posting`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_smilies`
--

LOCK TABLES `phpbb_smilies` WRITE;
/*!40000 ALTER TABLE `phpbb_smilies` DISABLE KEYS */;
INSERT INTO `phpbb_smilies` VALUES
(1,':D','Very Happy','icon_e_biggrin.gif',15,17,1,1),
(2,':-D','Very Happy','icon_e_biggrin.gif',15,17,2,1),
(3,':grin:','Very Happy','icon_e_biggrin.gif',15,17,3,1),
(4,':)','Smile','icon_e_smile.gif',15,17,4,1),
(5,':-)','Smile','icon_e_smile.gif',15,17,5,1),
(6,':smile:','Smile','icon_e_smile.gif',15,17,6,1),
(7,';)','Wink','icon_e_wink.gif',15,17,7,1),
(8,';-)','Wink','icon_e_wink.gif',15,17,8,1),
(9,':wink:','Wink','icon_e_wink.gif',15,17,9,1),
(10,':(','Sad','icon_e_sad.gif',15,17,10,1),
(11,':-(','Sad','icon_e_sad.gif',15,17,11,1),
(12,':sad:','Sad','icon_e_sad.gif',15,17,12,1),
(13,':o','Surprised','icon_e_surprised.gif',15,17,13,1),
(14,':-o','Surprised','icon_e_surprised.gif',15,17,14,1),
(15,':eek:','Surprised','icon_e_surprised.gif',15,17,15,1),
(16,':shock:','Shocked','icon_eek.gif',15,17,16,1),
(17,':?','Confused','icon_e_confused.gif',15,17,17,1),
(18,':-?','Confused','icon_e_confused.gif',15,17,18,1),
(19,':???:','Confused','icon_e_confused.gif',15,17,19,1),
(20,'8-)','Cool','icon_cool.gif',15,17,20,1),
(21,':cool:','Cool','icon_cool.gif',15,17,21,1),
(22,':lol:','Laughing','icon_lol.gif',15,17,22,1),
(23,':x','Mad','icon_mad.gif',15,17,23,1),
(24,':-x','Mad','icon_mad.gif',15,17,24,1),
(25,':mad:','Mad','icon_mad.gif',15,17,25,1),
(26,':P','Razz','icon_razz.gif',15,17,26,1),
(27,':-P','Razz','icon_razz.gif',15,17,27,1),
(28,':razz:','Razz','icon_razz.gif',15,17,28,1),
(29,':oops:','Embarrassed','icon_redface.gif',15,17,29,1),
(30,':cry:','Crying or Very Sad','icon_cry.gif',15,17,30,1),
(31,':evil:','Evil or Very Mad','icon_evil.gif',15,17,31,1),
(32,':twisted:','Twisted Evil','icon_twisted.gif',15,17,32,1),
(33,':roll:','Rolling Eyes','icon_rolleyes.gif',15,17,33,1),
(34,':!:','Exclamation','icon_exclaim.gif',15,17,34,1),
(35,':?:','Question','icon_question.gif',15,17,35,1),
(36,':idea:','Idea','icon_idea.gif',15,17,36,1),
(37,':arrow:','Arrow','icon_arrow.gif',15,17,37,1),
(38,':|','Neutral','icon_neutral.gif',15,17,38,1),
(39,':-|','Neutral','icon_neutral.gif',15,17,39,1),
(40,':mrgreen:','Mr. Green','icon_mrgreen.gif',15,17,40,1),
(41,':geek:','Geek','icon_e_geek.gif',17,17,41,1),
(42,':ugeek:','Uber Geek','icon_e_ugeek.gif',17,18,42,1),
(43,':D','Very Happy','icon_e_biggrin.gif',15,17,1,1),
(44,':-D','Very Happy','icon_e_biggrin.gif',15,17,2,1),
(45,':grin:','Very Happy','icon_e_biggrin.gif',15,17,3,1),
(46,':)','Smile','icon_e_smile.gif',15,17,4,1),
(47,':-)','Smile','icon_e_smile.gif',15,17,5,1),
(48,':smile:','Smile','icon_e_smile.gif',15,17,6,1),
(49,';)','Wink','icon_e_wink.gif',15,17,7,1),
(50,';-)','Wink','icon_e_wink.gif',15,17,8,1),
(51,':wink:','Wink','icon_e_wink.gif',15,17,9,1),
(52,':(','Sad','icon_e_sad.gif',15,17,10,1),
(53,':-(','Sad','icon_e_sad.gif',15,17,11,1),
(54,':sad:','Sad','icon_e_sad.gif',15,17,12,1),
(55,':o','Surprised','icon_e_surprised.gif',15,17,13,1),
(56,':-o','Surprised','icon_e_surprised.gif',15,17,14,1),
(57,':eek:','Surprised','icon_e_surprised.gif',15,17,15,1),
(58,':shock:','Shocked','icon_eek.gif',15,17,16,1),
(59,':?','Confused','icon_e_confused.gif',15,17,17,1),
(60,':-?','Confused','icon_e_confused.gif',15,17,18,1),
(61,':???:','Confused','icon_e_confused.gif',15,17,19,1),
(62,'8-)','Cool','icon_cool.gif',15,17,20,1),
(63,':cool:','Cool','icon_cool.gif',15,17,21,1),
(64,':lol:','Laughing','icon_lol.gif',15,17,22,1),
(65,':x','Mad','icon_mad.gif',15,17,23,1),
(66,':-x','Mad','icon_mad.gif',15,17,24,1),
(67,':mad:','Mad','icon_mad.gif',15,17,25,1),
(68,':P','Razz','icon_razz.gif',15,17,26,1),
(69,':-P','Razz','icon_razz.gif',15,17,27,1),
(70,':razz:','Razz','icon_razz.gif',15,17,28,1),
(71,':oops:','Embarrassed','icon_redface.gif',15,17,29,1),
(72,':cry:','Crying or Very Sad','icon_cry.gif',15,17,30,1),
(73,':evil:','Evil or Very Mad','icon_evil.gif',15,17,31,1),
(74,':twisted:','Twisted Evil','icon_twisted.gif',15,17,32,1),
(75,':roll:','Rolling Eyes','icon_rolleyes.gif',15,17,33,1),
(76,':!:','Exclamation','icon_exclaim.gif',15,17,34,1),
(77,':?:','Question','icon_question.gif',15,17,35,1),
(78,':idea:','Idea','icon_idea.gif',15,17,36,1),
(79,':arrow:','Arrow','icon_arrow.gif',15,17,37,1),
(80,':|','Neutral','icon_neutral.gif',15,17,38,1),
(81,':-|','Neutral','icon_neutral.gif',15,17,39,1),
(82,':mrgreen:','Mr. Green','icon_mrgreen.gif',15,17,40,1),
(83,':geek:','Geek','icon_e_geek.gif',17,17,41,1),
(84,':ugeek:','Uber Geek','icon_e_ugeek.gif',17,18,42,1);
/*!40000 ALTER TABLE `phpbb_smilies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_styles`
--

DROP TABLE IF EXISTS `phpbb_styles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_styles` (
  `style_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `style_name` varchar(255) NOT NULL DEFAULT '',
  `style_copyright` varchar(255) NOT NULL DEFAULT '',
  `style_active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `style_path` varchar(100) NOT NULL DEFAULT '',
  `bbcode_bitfield` varchar(255) NOT NULL DEFAULT 'kNg=',
  `style_parent_id` int(4) unsigned NOT NULL DEFAULT 0,
  `style_parent_tree` text NOT NULL,
  PRIMARY KEY (`style_id`),
  UNIQUE KEY `style_name` (`style_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_styles`
--

LOCK TABLES `phpbb_styles` WRITE;
/*!40000 ALTER TABLE `phpbb_styles` DISABLE KEYS */;
INSERT INTO `phpbb_styles` VALUES
(1,'prosilver','&copy; phpBB Limited',1,'prosilver','//g=',0,'');
/*!40000 ALTER TABLE `phpbb_styles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_teampage`
--

DROP TABLE IF EXISTS `phpbb_teampage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_teampage` (
  `teampage_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `teampage_name` varchar(255) NOT NULL DEFAULT '',
  `teampage_position` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `teampage_parent` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`teampage_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_teampage`
--

LOCK TABLES `phpbb_teampage` WRITE;
/*!40000 ALTER TABLE `phpbb_teampage` DISABLE KEYS */;
INSERT INTO `phpbb_teampage` VALUES
(1,5,'',1,0),
(2,4,'',2,0),
(3,5,'',1,0),
(4,4,'',2,0);
/*!40000 ALTER TABLE `phpbb_teampage` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_topics`
--

DROP TABLE IF EXISTS `phpbb_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_topics` (
  `topic_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `icon_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_attachment` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_reported` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `topic_poster` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_time` int(11) unsigned NOT NULL DEFAULT 0,
  `topic_time_limit` int(11) unsigned NOT NULL DEFAULT 0,
  `topic_views` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_status` tinyint(3) NOT NULL DEFAULT 0,
  `topic_type` tinyint(3) NOT NULL DEFAULT 0,
  `topic_first_post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_first_poster_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `topic_first_poster_colour` varchar(6) NOT NULL DEFAULT '',
  `topic_last_post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_last_poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_last_poster_name` varchar(255) NOT NULL DEFAULT '',
  `topic_last_poster_colour` varchar(6) NOT NULL DEFAULT '',
  `topic_last_post_subject` varchar(255) NOT NULL DEFAULT '',
  `topic_last_post_time` int(11) unsigned NOT NULL DEFAULT 0,
  `topic_last_view_time` int(11) unsigned NOT NULL DEFAULT 0,
  `topic_moved_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_bumped` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_bumper` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poll_title` varchar(255) NOT NULL DEFAULT '',
  `poll_start` int(11) unsigned NOT NULL DEFAULT 0,
  `poll_length` int(11) unsigned NOT NULL DEFAULT 0,
  `poll_max_options` tinyint(4) NOT NULL DEFAULT 1,
  `poll_last_vote` int(11) unsigned NOT NULL DEFAULT 0,
  `poll_vote_change` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_visibility` tinyint(3) NOT NULL DEFAULT 0,
  `topic_delete_time` int(11) unsigned NOT NULL DEFAULT 0,
  `topic_delete_reason` varchar(255) NOT NULL DEFAULT '',
  `topic_delete_user` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_posts_approved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_posts_unapproved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_posts_softdeleted` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`topic_id`),
  KEY `forum_id` (`forum_id`),
  KEY `forum_id_type` (`forum_id`,`topic_type`),
  KEY `last_post_time` (`topic_last_post_time`),
  KEY `fid_time_moved` (`forum_id`,`topic_last_post_time`,`topic_moved_id`),
  KEY `topic_visibility` (`topic_visibility`),
  KEY `forum_vis_last` (`forum_id`,`topic_visibility`,`topic_last_post_id`),
  KEY `latest_topics` (`forum_id`,`topic_last_post_time`,`topic_last_post_id`,`topic_moved_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_topics`
--

LOCK TABLES `phpbb_topics` WRITE;
/*!40000 ALTER TABLE `phpbb_topics` DISABLE KEYS */;
INSERT INTO `phpbb_topics` VALUES
(1,2,0,0,0,'Welcome to phpBB3',2,1776276588,0,0,0,0,1,'admin','AA0000',1,2,'admin','AA0000','Welcome to phpBB3',1776276588,972086460,0,0,0,'',0,0,1,0,0,1,0,'',0,1,0,0),
(2,2,0,0,0,'Welcome to phpBB3',2,1776276588,0,0,0,0,1,'admin','AA0000',1,2,'admin','AA0000','Welcome to phpBB3',1776276588,972086460,0,0,0,'',0,0,1,0,0,1,0,'',0,1,0,0);
/*!40000 ALTER TABLE `phpbb_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_topics_posted`
--

DROP TABLE IF EXISTS `phpbb_topics_posted`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_topics_posted` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_posted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_topics_posted`
--

LOCK TABLES `phpbb_topics_posted` WRITE;
/*!40000 ALTER TABLE `phpbb_topics_posted` DISABLE KEYS */;
INSERT INTO `phpbb_topics_posted` VALUES
(2,1,1);
/*!40000 ALTER TABLE `phpbb_topics_posted` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_topics_track`
--

DROP TABLE IF EXISTS `phpbb_topics_track`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_topics_track` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `mark_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`topic_id`),
  KEY `forum_id` (`forum_id`),
  KEY `topic_id` (`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_topics_track`
--

LOCK TABLES `phpbb_topics_track` WRITE;
/*!40000 ALTER TABLE `phpbb_topics_track` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_topics_track` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_topics_watch`
--

DROP TABLE IF EXISTS `phpbb_topics_watch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_topics_watch` (
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `notify_status` tinyint(1) unsigned NOT NULL DEFAULT 0,
  KEY `topic_id` (`topic_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_stat` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_topics_watch`
--

LOCK TABLES `phpbb_topics_watch` WRITE;
/*!40000 ALTER TABLE `phpbb_topics_watch` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_topics_watch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_user_group`
--

DROP TABLE IF EXISTS `phpbb_user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_user_group` (
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `group_leader` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `user_pending` tinyint(1) unsigned NOT NULL DEFAULT 1,
  KEY `group_id` (`group_id`),
  KEY `user_id` (`user_id`),
  KEY `group_leader` (`group_leader`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_user_group`
--

LOCK TABLES `phpbb_user_group` WRITE;
/*!40000 ALTER TABLE `phpbb_user_group` DISABLE KEYS */;
INSERT INTO `phpbb_user_group` VALUES
(1,1,0,0),
(2,2,0,0),
(4,2,0,0),
(5,2,1,0),
(6,3,0,0),
(6,4,0,0),
(6,5,0,0),
(6,6,0,0),
(6,7,0,0),
(6,8,0,0),
(6,9,0,0),
(6,10,0,0),
(6,11,0,0),
(6,12,0,0),
(6,13,0,0),
(6,14,0,0),
(6,15,0,0),
(6,16,0,0),
(6,17,0,0),
(6,18,0,0),
(6,19,0,0),
(6,20,0,0),
(6,21,0,0),
(6,22,0,0),
(6,23,0,0),
(6,24,0,0),
(6,25,0,0),
(6,26,0,0),
(6,27,0,0),
(6,28,0,0),
(6,29,0,0),
(6,30,0,0),
(6,31,0,0),
(6,32,0,0),
(6,33,0,0),
(6,34,0,0),
(6,35,0,0),
(6,36,0,0),
(6,37,0,0),
(6,38,0,0),
(6,39,0,0),
(6,40,0,0),
(6,41,0,0),
(6,42,0,0),
(6,43,0,0),
(6,44,0,0),
(6,45,0,0),
(6,46,0,0),
(6,47,0,0),
(6,48,0,0),
(6,49,0,0),
(6,50,0,0),
(6,51,0,0),
(6,52,0,0),
(6,53,0,0),
(6,54,0,0),
(6,55,0,0),
(6,56,0,0),
(6,57,0,0),
(1,1,0,0),
(2,2,0,0),
(4,2,0,0),
(5,2,1,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0),
(6,0,0,0);
/*!40000 ALTER TABLE `phpbb_user_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_user_notifications`
--

DROP TABLE IF EXISTS `phpbb_user_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_user_notifications` (
  `item_type` varchar(165) NOT NULL DEFAULT '',
  `item_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `method` varchar(165) NOT NULL DEFAULT '',
  `notify` tinyint(1) unsigned NOT NULL DEFAULT 1,
  UNIQUE KEY `itm_usr_mthd` (`item_type`,`item_id`,`user_id`,`method`),
  KEY `user_id` (`user_id`),
  KEY `uid_itm_id` (`user_id`,`item_id`),
  KEY `usr_itm_tpe` (`user_id`,`item_type`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_user_notifications`
--

LOCK TABLES `phpbb_user_notifications` WRITE;
/*!40000 ALTER TABLE `phpbb_user_notifications` DISABLE KEYS */;
INSERT INTO `phpbb_user_notifications` VALUES
('notification.type.forum',0,2,'notification.method.board',1),
('notification.type.forum',0,2,'notification.method.email',1),
('notification.type.post',0,0,'notification.method.email',1),
('notification.type.post',0,2,'notification.method.board',1),
('notification.type.post',0,2,'notification.method.email',1),
('notification.type.post',0,3,'notification.method.email',1),
('notification.type.post',0,4,'notification.method.email',1),
('notification.type.post',0,5,'notification.method.email',1),
('notification.type.post',0,6,'notification.method.email',1),
('notification.type.post',0,7,'notification.method.email',1),
('notification.type.post',0,8,'notification.method.email',1),
('notification.type.post',0,9,'notification.method.email',1),
('notification.type.post',0,10,'notification.method.email',1),
('notification.type.post',0,11,'notification.method.email',1),
('notification.type.post',0,12,'notification.method.email',1),
('notification.type.post',0,13,'notification.method.email',1),
('notification.type.post',0,14,'notification.method.email',1),
('notification.type.post',0,15,'notification.method.email',1),
('notification.type.post',0,16,'notification.method.email',1),
('notification.type.post',0,17,'notification.method.email',1),
('notification.type.post',0,18,'notification.method.email',1),
('notification.type.post',0,19,'notification.method.email',1),
('notification.type.post',0,20,'notification.method.email',1),
('notification.type.post',0,21,'notification.method.email',1),
('notification.type.post',0,22,'notification.method.email',1),
('notification.type.post',0,23,'notification.method.email',1),
('notification.type.post',0,24,'notification.method.email',1),
('notification.type.post',0,25,'notification.method.email',1),
('notification.type.post',0,26,'notification.method.email',1),
('notification.type.post',0,27,'notification.method.email',1),
('notification.type.post',0,28,'notification.method.email',1),
('notification.type.post',0,29,'notification.method.email',1),
('notification.type.post',0,30,'notification.method.email',1),
('notification.type.post',0,31,'notification.method.email',1),
('notification.type.post',0,32,'notification.method.email',1),
('notification.type.post',0,33,'notification.method.email',1),
('notification.type.post',0,34,'notification.method.email',1),
('notification.type.post',0,35,'notification.method.email',1),
('notification.type.post',0,36,'notification.method.email',1),
('notification.type.post',0,37,'notification.method.email',1),
('notification.type.post',0,38,'notification.method.email',1),
('notification.type.post',0,39,'notification.method.email',1),
('notification.type.post',0,40,'notification.method.email',1),
('notification.type.post',0,41,'notification.method.email',1),
('notification.type.post',0,42,'notification.method.email',1),
('notification.type.post',0,43,'notification.method.email',1),
('notification.type.post',0,44,'notification.method.email',1),
('notification.type.post',0,45,'notification.method.email',1),
('notification.type.post',0,46,'notification.method.email',1),
('notification.type.post',0,47,'notification.method.email',1),
('notification.type.post',0,48,'notification.method.email',1),
('notification.type.post',0,49,'notification.method.email',1),
('notification.type.post',0,50,'notification.method.email',1),
('notification.type.post',0,51,'notification.method.email',1),
('notification.type.post',0,52,'notification.method.email',1),
('notification.type.post',0,53,'notification.method.email',1),
('notification.type.post',0,54,'notification.method.email',1),
('notification.type.post',0,55,'notification.method.email',1),
('notification.type.post',0,56,'notification.method.email',1),
('notification.type.post',0,57,'notification.method.email',1),
('notification.type.topic',0,0,'notification.method.email',1),
('notification.type.topic',0,2,'notification.method.board',1),
('notification.type.topic',0,2,'notification.method.email',1),
('notification.type.topic',0,3,'notification.method.email',1),
('notification.type.topic',0,4,'notification.method.email',1),
('notification.type.topic',0,5,'notification.method.email',1),
('notification.type.topic',0,6,'notification.method.email',1),
('notification.type.topic',0,7,'notification.method.email',1),
('notification.type.topic',0,8,'notification.method.email',1),
('notification.type.topic',0,9,'notification.method.email',1),
('notification.type.topic',0,10,'notification.method.email',1),
('notification.type.topic',0,11,'notification.method.email',1),
('notification.type.topic',0,12,'notification.method.email',1),
('notification.type.topic',0,13,'notification.method.email',1),
('notification.type.topic',0,14,'notification.method.email',1),
('notification.type.topic',0,15,'notification.method.email',1),
('notification.type.topic',0,16,'notification.method.email',1),
('notification.type.topic',0,17,'notification.method.email',1),
('notification.type.topic',0,18,'notification.method.email',1),
('notification.type.topic',0,19,'notification.method.email',1),
('notification.type.topic',0,20,'notification.method.email',1),
('notification.type.topic',0,21,'notification.method.email',1),
('notification.type.topic',0,22,'notification.method.email',1),
('notification.type.topic',0,23,'notification.method.email',1),
('notification.type.topic',0,24,'notification.method.email',1),
('notification.type.topic',0,25,'notification.method.email',1),
('notification.type.topic',0,26,'notification.method.email',1),
('notification.type.topic',0,27,'notification.method.email',1),
('notification.type.topic',0,28,'notification.method.email',1),
('notification.type.topic',0,29,'notification.method.email',1),
('notification.type.topic',0,30,'notification.method.email',1),
('notification.type.topic',0,31,'notification.method.email',1),
('notification.type.topic',0,32,'notification.method.email',1),
('notification.type.topic',0,33,'notification.method.email',1),
('notification.type.topic',0,34,'notification.method.email',1),
('notification.type.topic',0,35,'notification.method.email',1),
('notification.type.topic',0,36,'notification.method.email',1),
('notification.type.topic',0,37,'notification.method.email',1),
('notification.type.topic',0,38,'notification.method.email',1),
('notification.type.topic',0,39,'notification.method.email',1),
('notification.type.topic',0,40,'notification.method.email',1),
('notification.type.topic',0,41,'notification.method.email',1),
('notification.type.topic',0,42,'notification.method.email',1),
('notification.type.topic',0,43,'notification.method.email',1),
('notification.type.topic',0,44,'notification.method.email',1),
('notification.type.topic',0,45,'notification.method.email',1),
('notification.type.topic',0,46,'notification.method.email',1),
('notification.type.topic',0,47,'notification.method.email',1),
('notification.type.topic',0,48,'notification.method.email',1),
('notification.type.topic',0,49,'notification.method.email',1),
('notification.type.topic',0,50,'notification.method.email',1),
('notification.type.topic',0,51,'notification.method.email',1),
('notification.type.topic',0,52,'notification.method.email',1),
('notification.type.topic',0,53,'notification.method.email',1),
('notification.type.topic',0,54,'notification.method.email',1),
('notification.type.topic',0,55,'notification.method.email',1),
('notification.type.topic',0,56,'notification.method.email',1),
('notification.type.topic',0,57,'notification.method.email',1);
/*!40000 ALTER TABLE `phpbb_user_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_users`
--

DROP TABLE IF EXISTS `phpbb_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_type` tinyint(2) NOT NULL DEFAULT 0,
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 3,
  `user_permissions` mediumtext NOT NULL,
  `user_perm_from` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_ip` varchar(40) NOT NULL DEFAULT '',
  `user_regdate` int(11) unsigned NOT NULL DEFAULT 0,
  `username` varchar(255) NOT NULL DEFAULT '',
  `username_clean` varchar(255) NOT NULL DEFAULT '',
  `user_password` varchar(255) NOT NULL DEFAULT '',
  `user_passchg` int(11) unsigned NOT NULL DEFAULT 0,
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_birthday` varchar(10) NOT NULL DEFAULT '',
  `user_lastvisit` int(11) unsigned NOT NULL DEFAULT 0,
  `user_last_active` int(11) unsigned NOT NULL DEFAULT 0,
  `user_lastmark` int(11) unsigned NOT NULL DEFAULT 0,
  `user_lastpost_time` int(11) unsigned NOT NULL DEFAULT 0,
  `user_lastpage` varchar(200) NOT NULL DEFAULT '',
  `user_last_confirm_key` varchar(10) NOT NULL DEFAULT '',
  `user_last_search` int(11) unsigned NOT NULL DEFAULT 0,
  `user_warnings` tinyint(4) NOT NULL DEFAULT 0,
  `user_last_warning` int(11) unsigned NOT NULL DEFAULT 0,
  `user_login_attempts` tinyint(4) NOT NULL DEFAULT 0,
  `user_inactive_reason` tinyint(2) NOT NULL DEFAULT 0,
  `user_inactive_time` int(11) unsigned NOT NULL DEFAULT 0,
  `user_posts` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_lang` varchar(30) NOT NULL DEFAULT '',
  `user_timezone` varchar(100) NOT NULL DEFAULT '',
  `user_dateformat` varchar(64) NOT NULL DEFAULT 'd M Y H:i',
  `user_style` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_rank` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_colour` varchar(6) NOT NULL DEFAULT '',
  `user_new_privmsg` int(4) NOT NULL DEFAULT 0,
  `user_unread_privmsg` int(4) NOT NULL DEFAULT 0,
  `user_last_privmsg` int(11) unsigned NOT NULL DEFAULT 0,
  `user_message_rules` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `user_full_folder` int(11) NOT NULL DEFAULT -3,
  `user_emailtime` int(11) unsigned NOT NULL DEFAULT 0,
  `user_topic_show_days` smallint(4) unsigned NOT NULL DEFAULT 0,
  `user_topic_sortby_type` varchar(1) NOT NULL DEFAULT 't',
  `user_topic_sortby_dir` varchar(1) NOT NULL DEFAULT 'd',
  `user_post_show_days` smallint(4) unsigned NOT NULL DEFAULT 0,
  `user_post_sortby_type` varchar(1) NOT NULL DEFAULT 't',
  `user_post_sortby_dir` varchar(1) NOT NULL DEFAULT 'a',
  `user_notify` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `user_notify_pm` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_notify_type` tinyint(4) NOT NULL DEFAULT 0,
  `user_allow_pm` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_allow_viewonline` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_allow_viewemail` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_allow_massemail` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_options` int(11) unsigned NOT NULL DEFAULT 230271,
  `user_avatar` varchar(255) NOT NULL DEFAULT '',
  `user_avatar_type` varchar(255) NOT NULL DEFAULT '',
  `user_avatar_width` smallint(4) unsigned NOT NULL DEFAULT 0,
  `user_avatar_height` smallint(4) unsigned NOT NULL DEFAULT 0,
  `user_sig` mediumtext NOT NULL,
  `user_sig_bbcode_uid` varchar(8) NOT NULL DEFAULT '',
  `user_sig_bbcode_bitfield` varchar(255) NOT NULL DEFAULT '',
  `user_jabber` varchar(255) NOT NULL DEFAULT '',
  `user_actkey` varchar(32) NOT NULL DEFAULT '',
  `user_actkey_expiration` int(11) unsigned NOT NULL DEFAULT 0,
  `reset_token` varchar(64) NOT NULL DEFAULT '',
  `reset_token_expiration` int(11) unsigned NOT NULL DEFAULT 0,
  `user_newpasswd` varchar(255) NOT NULL DEFAULT '',
  `user_form_salt` varchar(32) NOT NULL DEFAULT '',
  `user_new` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_reminded` tinyint(4) NOT NULL DEFAULT 0,
  `user_reminded_time` int(11) unsigned NOT NULL DEFAULT 0,
  `token_generation` int(10) unsigned NOT NULL DEFAULT 0,
  `perm_version` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username_clean` (`username_clean`),
  KEY `user_birthday` (`user_birthday`),
  KEY `user_type` (`user_type`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_users`
--

LOCK TABLES `phpbb_users` WRITE;
/*!40000 ALTER TABLE `phpbb_users` DISABLE KEYS */;
INSERT INTO `phpbb_users` VALUES
(1,2,1,'00000000000g13ydmo\nhwby9w000000\nhwby9w000000',0,'',1776276588,'Anonymous','anonymous','',0,'','',0,1776276428,0,0,'','',0,0,0,0,0,0,0,'en','','d M Y H:i',1,0,'',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,1,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','6xoj4tyqom8rhyom',1,0,0,0,0),
(2,3,5,'',0,'192.168.65.1',1776276588,'admin','admin','$argon2id$v=19$m=65536,t=4,p=2$b1RoSmY3a3BPbFkzNDhZTQ$KbDgsHcBKEY8AcLqXpkn1sBPYgHTlaYK2dcpWmd8UJk',0,'admin@domain.tld','',0,0,0,0,'','',0,0,0,0,0,0,1,'en','','D M d, Y g:i a',1,1,'AA0000',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,1,1,1,1,230271,'','',0,0,'','','','','',0,'',0,'','',1,0,0,0,0),
(3,2,6,'',0,'',1776276588,'AdsBot [Google]','adsbot [google]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','h82nd5arhstgk71p',0,0,0,0,0),
(4,2,6,'',0,'',1776276588,'Ahrefs [Bot]','ahrefs [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','c268g56gdo5y1m7w',0,0,0,0,0),
(5,2,6,'',0,'',1776276588,'Alexa [Bot]','alexa [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','zil2usp0h89bmss8',0,0,0,0,0),
(6,2,6,'',0,'',1776276588,'Alta Vista [Bot]','alta vista [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hz3t3cmroprlern3',0,0,0,0,0),
(7,2,6,'',0,'',1776276588,'Amazon [Bot]','amazon [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','tfkh9wkli7xsjkr2',0,0,0,0,0),
(8,2,6,'',0,'',1776276588,'Ask Jeeves [Bot]','ask jeeves [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','lrc9ap4fon2qpmjl',0,0,0,0,0),
(9,2,6,'',0,'',1776276588,'Baidu [Spider]','baidu [spider]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','qorsopmlalceye2y',0,0,0,0,0),
(10,2,6,'',0,'',1776276588,'Bing [Bot]','bing [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','1s2viych2ocp8o2j',0,0,0,0,0),
(11,2,6,'',0,'',1776276588,'DuckDuckGo [Bot]','duckduckgo [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hj6nnh75njomb2cr',0,0,0,0,0),
(12,2,6,'',0,'',1776276588,'Exabot [Bot]','exabot [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','l8nf3fy8q9dg9iy0',0,0,0,0,0),
(13,2,6,'',0,'',1776276588,'FAST Enterprise [Crawler]','fast enterprise [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','847djtxsvi66mz20',0,0,0,0,0),
(14,2,6,'',0,'',1776276588,'FAST WebCrawler [Crawler]','fast webcrawler [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','xe2sqolp3aoyzlqz',0,0,0,0,0),
(15,2,6,'',0,'',1776276588,'Francis [Bot]','francis [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','fnbvbdo772r00lke',0,0,0,0,0),
(16,2,6,'',0,'',1776276588,'Gigabot [Bot]','gigabot [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','fsx0p28184j6wf6s',0,0,0,0,0),
(17,2,6,'',0,'',1776276588,'Google Adsense [Bot]','google adsense [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','6957b4be51ka23su',0,0,0,0,0),
(18,2,6,'',0,'',1776276588,'Google Desktop','google desktop','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','jl2w6kos07ajlvvo',0,0,0,0,0),
(19,2,6,'',0,'',1776276588,'Google Feedfetcher','google feedfetcher','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','idtc18q548e686fu',0,0,0,0,0),
(20,2,6,'',0,'',1776276588,'Google [Bot]','google [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hfvxq1lnha9q9dkh',0,0,0,0,0),
(21,2,6,'',0,'',1776276588,'Heise IT-Markt [Crawler]','heise it-markt [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','t4e6xelroxds878f',0,0,0,0,0),
(22,2,6,'',0,'',1776276588,'Heritrix [Crawler]','heritrix [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','o4a1xtvct0l11p6x',0,0,0,0,0),
(23,2,6,'',0,'',1776276588,'IBM Research [Bot]','ibm research [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','cztlnfk4w5hdkj90',0,0,0,0,0),
(24,2,6,'',0,'',1776276588,'ICCrawler - ICjobs','iccrawler - icjobs','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','86hib83uk1r9wqyu',0,0,0,0,0),
(25,2,6,'',0,'',1776276588,'ichiro [Crawler]','ichiro [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hk5cd0vnn4tb2wn9',0,0,0,0,0),
(26,2,6,'',0,'',1776276588,'Majestic-12 [Bot]','majestic-12 [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','thhc4lt8vzq7sbbo',0,0,0,0,0),
(27,2,6,'',0,'',1776276588,'Metager [Bot]','metager [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hkebf8v7r152l4oc',0,0,0,0,0),
(28,2,6,'',0,'',1776276588,'MSN NewsBlogs','msn newsblogs','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','hop0riukegszk6kh',0,0,0,0,0),
(29,2,6,'',0,'',1776276588,'MSN [Bot]','msn [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','brqqsv0a1wyrzdn4',0,0,0,0,0),
(30,2,6,'',0,'',1776276588,'MSNbot Media','msnbot media','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','ykn6nj0vne02ifu0',0,0,0,0,0),
(31,2,6,'',0,'',1776276588,'NG-Search [Bot]','ng-search [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','m2lax3en24lzxr8r',0,0,0,0,0),
(32,2,6,'',0,'',1776276588,'Nutch [Bot]','nutch [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','b7spety5zf9x51sr',0,0,0,0,0),
(33,2,6,'',0,'',1776276588,'Nutch/CVS [Bot]','nutch/cvs [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','u2h9ffgabm697bvo',0,0,0,0,0),
(34,2,6,'',0,'',1776276588,'OmniExplorer [Bot]','omniexplorer [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','poe5vgu67eokvx8n',0,0,0,0,0),
(35,2,6,'',0,'',1776276588,'Online link [Validator]','online link [validator]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','170qr887tn3imtsx',0,0,0,0,0),
(36,2,6,'',0,'',1776276588,'psbot [Picsearch]','psbot [picsearch]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','4ln6slcgc0wixyh5',0,0,0,0,0),
(37,2,6,'',0,'',1776276588,'Seekport [Bot]','seekport [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','u90hw9s6azwoqlrs',0,0,0,0,0),
(38,2,6,'',0,'',1776276588,'Semrush [Bot]','semrush [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','8dr45z0rbr9kslaq',0,0,0,0,0),
(39,2,6,'',0,'',1776276588,'Sensis [Crawler]','sensis [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','a1ivu2glo3w5tnvm',0,0,0,0,0),
(40,2,6,'',0,'',1776276588,'SEO Crawler','seo crawler','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','6agse9e9g4bdasi2',0,0,0,0,0),
(41,2,6,'',0,'',1776276588,'Seoma [Crawler]','seoma [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','6mmendwxgxia4exm',0,0,0,0,0),
(42,2,6,'',0,'',1776276588,'SEOSearch [Crawler]','seosearch [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','tfd1eijnmscvvdx1',0,0,0,0,0),
(43,2,6,'',0,'',1776276588,'Snappy [Bot]','snappy [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','w5fytpd98z9hh75q',0,0,0,0,0),
(44,2,6,'',0,'',1776276588,'Steeler [Crawler]','steeler [crawler]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','5lfma4s3pom6lowv',0,0,0,0,0),
(45,2,6,'',0,'',1776276588,'Synoo [Bot]','synoo [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','46hs2pey6ihtj2o0',0,0,0,0,0),
(46,2,6,'',0,'',1776276588,'Telekom [Bot]','telekom [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','nu04c7m6s31az34a',0,0,0,0,0),
(47,2,6,'',0,'',1776276588,'TurnitinBot [Bot]','turnitinbot [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','t03006owy9w2a9up',0,0,0,0,0),
(48,2,6,'',0,'',1776276588,'Voyager [Bot]','voyager [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','vmmz0pdv9i10xmn5',0,0,0,0,0),
(49,2,6,'',0,'',1776276588,'W3 [Sitesearch]','w3 [sitesearch]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','nvcnshoyci4v5vhj',0,0,0,0,0),
(50,2,6,'',0,'',1776276588,'W3C [Linkcheck]','w3c [linkcheck]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','55wr9c72symm3cwz',0,0,0,0,0),
(51,2,6,'',0,'',1776276588,'W3C [Validator]','w3c [validator]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','px3uulym07khpfdm',0,0,0,0,0),
(52,2,6,'',0,'',1776276588,'WiseNut [Bot]','wisenut [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','zt0tlat38irfw83w',0,0,0,0,0),
(53,2,6,'',0,'',1776276588,'YaCy [Bot]','yacy [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','rycj2hoi3tca4ekc',0,0,0,0,0),
(54,2,6,'',0,'',1776276588,'Yahoo MMCrawler [Bot]','yahoo mmcrawler [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','snip6w7wzwv24jc8',0,0,0,0,0),
(55,2,6,'',0,'',1776276588,'Yahoo Slurp [Bot]','yahoo slurp [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','gaip1m2pcansax0w',0,0,0,0,0),
(56,2,6,'',0,'',1776276588,'Yahoo [Bot]','yahoo [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','73q9jkuba48oi3j2',0,0,0,0,0),
(57,2,6,'',0,'',1776276588,'YahooSeeker [Bot]','yahooseeker [bot]','',1776276428,'','',0,0,1776276428,0,'','',0,0,0,0,0,0,0,'en','UTC','D M d, Y g:i a',1,0,'9E8DA7',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,0,1,1,0,230271,'','',0,0,'','','','','',0,'',0,'','i6f5bsntqu7e01mk',0,0,0,0,0),
(200,0,2,'',0,'127.0.0.1',1776276588,'alice','alice','$argon2id$v=19$m=65536,t=4,p=2$TUtPb3FuMTlFVEdWcW1kVA$BFGei7n+s4nMnnB/ZJQku90Mfz9amjHZ7DDbpVkhGEs',0,'alice@example.com','',0,0,1776276588,0,'','',0,0,0,0,0,0,0,'en','','d M Y H:i',1,0,'',0,0,0,0,-3,0,0,'t','d',0,'t','a',0,1,0,1,1,1,1,230271,'','',0,0,'','','','','',0,'',0,'','aliceformsal1234567890abcdefgh1',0,0,0,0,0);
/*!40000 ALTER TABLE `phpbb_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_auth_refresh_tokens`
--

DROP TABLE IF EXISTS `phpbb_auth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_auth_refresh_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `family_id` char(36) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_at` int(10) unsigned NOT NULL,
  `expires_at` int(10) unsigned NOT NULL,
  `revoked_at` int(10) unsigned NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_family` (`family_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_auth_refresh_tokens`
--

LOCK TABLES `phpbb_auth_refresh_tokens` WRITE;
/*!40000 ALTER TABLE `phpbb_auth_refresh_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_auth_refresh_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_warnings`
--

DROP TABLE IF EXISTS `phpbb_warnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_warnings` (
  `warning_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `log_id` int(10) unsigned NOT NULL DEFAULT 0,
  `warning_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`warning_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_warnings`
--

LOCK TABLES `phpbb_warnings` WRITE;
/*!40000 ALTER TABLE `phpbb_warnings` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_warnings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_words`
--

DROP TABLE IF EXISTS `phpbb_words`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_words` (
  `word_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(255) NOT NULL DEFAULT '',
  `replacement` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`word_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_words`
--

LOCK TABLES `phpbb_words` WRITE;
/*!40000 ALTER TABLE `phpbb_words` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_words` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phpbb_zebra`
--

DROP TABLE IF EXISTS `phpbb_zebra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phpbb_zebra` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `zebra_id` int(10) unsigned NOT NULL DEFAULT 0,
  `friend` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `foe` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`zebra_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phpbb_zebra`
--

LOCK TABLES `phpbb_zebra` WRITE;
/*!40000 ALTER TABLE `phpbb_zebra` DISABLE KEYS */;
/*!40000 ALTER TABLE `phpbb_zebra` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-15 18:11:24
