CREATE TABLE IF NOT EXISTS `craigslist_ads` (
  `clid` int(11) NOT NULL,
  `email` varchar(200) NOT NULL,
  `applied` varchar(20) NOT NULL,
  PRIMARY KEY  (`clid`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;
