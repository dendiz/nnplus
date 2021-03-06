DROP TABLE IF EXISTS `upcoming`;
CREATE TABLE `upcoming` (
  `ID` INT(10) NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(20) COLLATE utf8_unicode_ci NOT NULL,
  `typeID` INT(10) NOT NULL,
  `info` TEXT NULL,
  `updateddate` TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  unique key (source, typeID)
) ENGINE=MYISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

