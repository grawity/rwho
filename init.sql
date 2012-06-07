DROP TABLE IF EXISTS utmp;
CREATE TABLE utmp (
	rowid	integer		AUTO_INCREMENT PRIMARY KEY,
	host	varchar(255)	NOT NULL,
	-- normally UT_NAMESIZE, but allow more for Windows
	user	varchar(64),
	rawuser	varchar(64),
	uid	integer,
	-- UT_HOSTSIZE
	rhost	varchar(256),
	-- UT_LINESIZE
	line	varchar(32),
	time	integer,
	updated	integer
);

DROP TABLE IF EXISTS hosts;
CREATE TABLE hosts (
	hostid		integer		AUTO_INCREMENT PRIMARY KEY,
	host		varchar(255)	NOT NULL UNIQUE,
	last_update	integer,
	last_addr	varchar(63)
);
