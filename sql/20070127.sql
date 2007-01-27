-- phpMyAdmin SQL Dump
-- version 2.9.0.1
-- http://www.phpmyadmin.net
-- 
-- Serveur: localhost
-- Généré le : Samedi 27 Janvier 2007 à 13:01
-- Version du serveur: 5.0.22
-- Version de PHP: 5.2.0
-- 
-- Base de données: `phpinetd-maild`
-- 

-- --------------------------------------------------------

-- 
-- Structure de la table `dnsbl_cache`
-- 

CREATE TABLE IF NOT EXISTS `dnsbl_cache` (
  `ip` varchar(15) NOT NULL,
  `list` varchar(30) NOT NULL,
  `regdate` datetime NOT NULL,
  `clear` enum('Y','N') NOT NULL,
  `answer` varchar(15) default NULL,
  PRIMARY KEY  (`ip`,`list`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

-- 
-- Structure de la table `domainaliases`
-- 

CREATE TABLE IF NOT EXISTS `domainaliases` (
  `domain` varchar(128) NOT NULL,
  `domainid` int(10) unsigned NOT NULL,
  `last_recv` datetime default NULL,
  PRIMARY KEY  (`domain`),
  KEY `domainid` (`domainid`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

-- 
-- Structure de la table `domains`
-- 

CREATE TABLE IF NOT EXISTS `domains` (
  `domainid` int(10) unsigned zerofill NOT NULL auto_increment,
  `domain` varchar(128) NOT NULL default '',
  `adminpass` varchar(40) NOT NULL default '',
  `state` enum('new','active') NOT NULL default 'new',
  `flags` set('create_account_on_mail','fake_domain','drop_email_on_spam') NOT NULL,
  `antispam` set('resend','rbl','internal','spamassassin') NOT NULL default '',
  `dnsbl` set('spews1','spews2','spamcop','spamhaus') NOT NULL,
  `antivirus` set('clam') NOT NULL default 'clam',
  `protocol` set('pop3','imap4') NOT NULL default '',
  `created` datetime NOT NULL,
  `last_recv` datetime default NULL,
  PRIMARY KEY  (`domainid`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

-- 
-- Structure de la table `hosts`
-- 

CREATE TABLE IF NOT EXISTS `hosts` (
  `ip` varchar(15) NOT NULL default '',
  `type` enum('trust','spam') NOT NULL default 'trust',
  `regdate` datetime NOT NULL default '0000-00-00 00:00:00',
  `expires` datetime default NULL,
  `user_email` varchar(255) default NULL,
  `spampoints` int(11) NOT NULL default '0',
  `spamupdate` datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (`ip`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

-- 
-- Structure de la table `mailqueue`
-- 

CREATE TABLE IF NOT EXISTS `mailqueue` (
  `mlid` varchar(128) NOT NULL,
  `from` varchar(255) default NULL,
  `to` varchar(255) NOT NULL,
  `queued` datetime NOT NULL,
  `pid` int(10) unsigned default NULL,
  `attempt_count` int(11) NOT NULL default '0',
  `last_attempt` datetime default NULL,
  `last_error` varchar(255) NOT NULL,
  `next_attempt` datetime default NULL,
  PRIMARY KEY  (`mlid`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
