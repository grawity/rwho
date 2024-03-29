START TRANSACTION;

DROP TABLE IF EXISTS utmp;
CREATE TABLE utmp (
	rowid		integer		AUTO_INCREMENT,
	host		varchar(255)	NOT NULL,
	-- normally UT_NAMESIZE, but allow more for Windows
	user		varchar(64)	NOT NULL,
	raw_user	varchar(64)	NOT NULL,
	uid		integer,
	-- UT_HOSTSIZE
	rhost		varchar(256),
	-- UT_LINESIZE
	line		varchar(32),
	time		integer,
	updated		integer,
	-- indexes
	PRIMARY KEY (rowid)
);

DROP TABLE IF EXISTS hosts;
CREATE TABLE hosts (
	host		varchar(255)	NOT NULL,
	last_update	integer,
	last_addr	varchar(63),
	-- indexes
	PRIMARY KEY (host)
);

COMMIT;
