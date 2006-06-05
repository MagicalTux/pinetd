-- phpMyAdmin SQL Dump
-- version 2.8.0.3
-- http://www.phpmyadmin.net
-- 
-- Serveur: localhost
-- Généré le : Lundi 05 Juin 2006 à 10:24
-- Version du serveur: 5.0.22
-- Version de PHP: 5.1.4
-- 
-- Base de données: `phpinetd-maild`
-- 

-- --------------------------------------------------------

-- 
-- Structure de la table `domains`
-- 

CREATE TABLE `domains` (
  `domainid` int(10) unsigned zerofill NOT NULL auto_increment,
  `domain` varchar(128) NOT NULL default '',
  `adminpass` varchar(40) NOT NULL default '',
  `defaultuser` int(10) unsigned default NULL,
  `state` enum('new','active') NOT NULL default 'new',
  `flags` set('create_account_on_mail','fake_domain') NOT NULL default '',
  `antispam` set('resend','rbl','internal','spamassassin') NOT NULL default '',
  `antivirus` set('clam') NOT NULL default 'clam',
  `protocol` set('pop3','imap4') NOT NULL default '',
  PRIMARY KEY  (`domainid`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 PACK_KEYS=0 AUTO_INCREMENT=34 ;

-- --------------------------------------------------------

-- 
-- Structure de la table `hosts`
-- 

CREATE TABLE `hosts` (
  `ip` varchar(15) NOT NULL default '',
  `type` enum('trust','spam') NOT NULL default 'trust',
  `regdate` datetime NOT NULL default '0000-00-00 00:00:00',
  `spampoints` int(11) NOT NULL default '0',
  `spamupdate` datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (`ip`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
