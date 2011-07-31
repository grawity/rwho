create table utmp (
	rowid	integer		auto_increment primary key,
	host	varchar(255)	not null,
	-- normally UT_NAMESIZE, but allow more for Windows
	user	varchar(64),
	uid	integer,
	-- UT_HOSTSIZE
	rhost	varchar(256),
	-- UT_LINESIZE
	line	varchar(32),
	time	integer,
	updated	integer,
);
create table hosts (
	hostid		integer		auto_increment primary key,
	host		varchar(255)	not null unique,
	last_update	integer,
	last_addr	varchar(63),
);
